<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Import\BaseDataImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Facades\CauserResolver;
use Throwable;

/**
 * Carga inicial de datos reales (familias, categorías, proveedores, materias primas y lotes) desde la plantilla
 * canónica. Todo o nada: con un solo error no queda nada guardado. Con --dry-run hace la carga completa y la deshace,
 * así prueba también las restricciones de la base.
 */
#[Signature('import:base-data
    {file : Ruta de la plantilla canónica (.xlsx)}
    {--user= : Correo del usuario que queda como autor de los movimientos y de la auditoría}
    {--dry-run : Valida y simula la carga sin guardar nada}')]
#[Description('Carga familias, categorías, proveedores, materias primas y lotes iniciales desde la plantilla canónica')]
class ImportBaseDataCommand extends Command
{
    public function handle(BaseDataImporter $importer): int
    {
        $user = User::query()->where('email', (string) $this->option('user'))->first();

        if ($user === null) {
            $this->error('Indica con --user el correo de un usuario existente: queda como autor de la carga.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No existe el archivo {$path}.");

            return self::FAILURE;
        }

        try {
            $sheets = $importer->read($path);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        CauserResolver::setCauser($user);

        DB::beginTransaction();

        try {
            $result = $importer->import($sheets, $user);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($result['errors'] !== [] || $dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->table(
            ['Hoja', 'Nuevos', 'Ya existían'],
            collect($result['counts'])->map(fn (array $count, string $sheet): array => [$sheet, $count['created'], $count['existing']])->values()->all(),
        );

        if ($result['errors'] !== []) {
            $this->error(count($result['errors']).' errores: no se guardó nada.');
            foreach ($result['errors'] as $error) {
                $this->line("  · {$error}");
            }

            return self::FAILURE;
        }

        $dryRun
            ? $this->info('Simulación correcta: no se guardó nada. Corre de nuevo sin --dry-run para cargar.')
            : $this->info('Carga completa.');

        return self::SUCCESS;
    }
}
