<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los QR que ya existen (los de prueba de staging) pasan al token corto de 12 caracteres `a–z0–9` y a la URL del dominio
 * propio del QR. Las estampitas de prueba ya impresas dejan de abrir: aceptado. En una base sin QR no hace nada.
 *
 * Genera el token y arma la URL aquí mismo, sin código de la aplicación: si ese código cambia, esta migración tiene que
 * seguir haciendo lo mismo. Lee el dominio de `config('qr.host')`: QR_HOST tiene que estar en el `.env` del servidor
 * antes del despliegue, o `qr_codes.url` queda con el dominio equivocado.
 *
 * Compatible con volver a la imagen anterior: esa busca el token por igualdad, sin importar el largo.
 */
return new class extends Migration
{
    private const TOKEN_LENGTH = 12;

    private const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function up(): void
    {
        $this->assertQrHostConfigured();

        $baseUrl = $this->baseUrl();

        DB::table('qr_codes')
            ->select(['id', 'token'])
            ->lazyById()
            ->reject(fn (object $row): bool => preg_match('/^[a-z0-9]{'.self::TOKEN_LENGTH.'}$/', (string) $row->token) === 1)
            ->each(function (object $row) use ($baseUrl): void {
                $token = $this->uniqueToken();

                DB::table('qr_codes')->where('id', $row->id)->update([
                    'token' => $token,
                    'url' => $baseUrl.'/'.$token,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Los tokens anteriores no se guardan y la imagen anterior abre los nuevos: no hay nada que deshacer.
    }

    /**
     * En un servidor (todo lo que no es local ni pruebas, como DeploymentEnvironment) sin QR_HOST, falla antes de tocar
     * una fila: pintech-deploy migra antes de cambiar contenedores y aborta ahí, en vez de dejar URLs a `qr.localhost`.
     * Escrito aquí y no con QrHost: la migración no usa código de la aplicación.
     */
    private function assertQrHostConfigured(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $host = strtolower(trim((string) config('qr.host')));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('QR_HOST no está configurado en este servidor: defínelo en el .env antes de migrar.');
        }
    }

    private function uniqueToken(): string
    {
        $last = strlen(self::TOKEN_ALPHABET) - 1;

        do {
            $token = '';

            for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
                $token .= self::TOKEN_ALPHABET[random_int(0, $last)];
            }
        } while (DB::table('qr_codes')->where('token', $token)->exists());

        return $token;
    }

    /**
     * `https://{QR_HOST}`, con el esquema (y el puerto, si lo hay) de APP_URL, como lo arma `route()` sin petición.
     */
    private function baseUrl(): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.strtolower(trim((string) config('qr.host'))).($port !== null && $port !== false ? ':'.$port : '');
    }
};
