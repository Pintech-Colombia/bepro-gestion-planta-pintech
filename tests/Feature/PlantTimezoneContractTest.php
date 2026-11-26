<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// Todo «hoy» y todo «día» del servidor sale de TimezoneService: de 7 p. m. a medianoche en Bogotá ya es el día
// siguiente en UTC, y cualquier conversión que no pase por la zona de planta falla en esa franja. Esta prueba recorre
// app/ y routes/ y falla si aparece una copia. Los comentarios no cuentan: se lee el código con el tokenizer de PHP.
//
// Atrapa las formas de abajo, escritas sobre `now()` o sobre una columna `*_at`. No adivina por el nombre de una
// variable (`$createdAt->toDateString()`): eso queda para la revisión de código.

/**
 * Archivos que sí pueden leer la zona de planta directamente, con su razón.
 *
 * @var array<string, string>
 */
const PLANT_TIMEZONE_ALLOWED_FILES = [
    'app/Services/TimezoneService.php' => 'es la pieza única: aquí viven «hoy» y «día de planta»',
    'app/Providers/AppServiceProvider.php' => 'crea el singleton de TimezoneService con la zona de config/app.php',
    'routes/console.php' => 'el scheduler necesita la zona como texto para ->timezone() de cada tarea',
];

/**
 * Columnas terminadas en `_at` que son `date` (el día de planta ya guardado), no un instante: su `toDateString()` no
 * convierte nada.
 *
 * @var array<string, string>
 */
const PLANT_TIMEZONE_DATE_COLUMNS = [
    'wrapped_at' => 'shrink_wraps.wrapped_at es una columna date',
];

/**
 * El código sin comentarios, un token por vez separado por un espacio, y la línea donde empieza cada token.
 *
 * @return array{0: string, 1: list<array{0: int, 1: int}>} [código, [[posición, línea], ...]]
 */
function plantTimezoneCode(string $path): array
{
    $code = '';
    $offsets = [];
    $line = 1;

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $line = is_array($token) ? $token[2] : $line;

        if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            $offsets[] = [strlen($code), $line];
            $code .= $text.' ';
        }

        $line += substr_count($text, "\n");
    }

    return [$code, $offsets];
}

/** @param  list<array{0: int, 1: int}>  $offsets */
function plantTimezoneLineAt(array $offsets, int $position): int
{
    $line = 1;

    foreach ($offsets as [$offset, $tokenLine]) {
        if ($offset > $position) {
            break;
        }

        $line = $tokenLine;
    }

    return $line;
}

it('calcula «hoy» y el día de un instante solo con TimezoneService', function (): void {
    // Los tokens van separados por un espacio: `now ( ) -> toDateString ( )`.
    // El grupo 1, cuando lo hay, es la columna: las de PLANT_TIMEZONE_DATE_COLUMNS no cuentan.
    $rules = [
        // `today()` global, `Carbon::today()`, `Date::today()`, `\today()`; no un método `->today()` ni su declaración.
        '/(?<!-> |function )\btoday \(/' => '«hoy» en UTC: usa TimezoneService::todayInPlant()',
        '/\bnow \( \) \??-> toDateString \( \)/' => '«hoy» en UTC: usa TimezoneService::todayInPlant()',
        '/[\'"]app\.plant_timezone[\'"]/' => 'la zona de planta se lee de TimezoneService::getPlantTimezone()',
        '/\bnow \( \) \??-> format \(/' => '«hoy» o la hora en UTC: usa TimezoneService::todayInPlant() o nowInPlant()',
        '/\bnow \( \) \??-> (?:startOfDay|endOfDay) \( \)/' => 'el día en UTC: usa TimezoneService::nowInPlant() o plantDayStartUtc()',
        '/\b(\w+_at) \??-> toDateString \( \)/' => 'un instante convertido a día en UTC: usa TimezoneService::plantDateOf()',
        '/\b(\w+_at) \??-> format \(/' => 'un instante formateado en UTC: usa TimezoneService::formatPlantDateTime()',
        // Un filtro por día sobre un `timestamp` toma el día UTC (el error de los filtros de OP y auditoría).
        '/\b(?:applyDateRange|whereDate|orWhereDate|whereYear|orWhereYear|whereMonth|orWhereMonth|whereDay|orWhereDay) \( [\'"](?:\w+\.)?(\w+_at)[\'"]/' => 'un filtro por día sobre un instante: usa applyInstantDateRange() o los límites de TimezoneService',
    ];

    $files = Finder::create()->files()->name('*.php')->in([base_path('app'), base_path('routes')]);
    $violations = [];

    foreach ($files as $file) {
        // `getPathname()` empieza por base_path(), igual que las rutas de la lista; `getRealPath()` resolvería symlinks.
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));

        if (array_key_exists($relative, PLANT_TIMEZONE_ALLOWED_FILES)) {
            continue;
        }

        [$code, $offsets] = plantTimezoneCode($file->getPathname());

        foreach ($rules as $pattern => $message) {
            preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                if (isset($match[1]) && array_key_exists($match[1][0], PLANT_TIMEZONE_DATE_COLUMNS)) {
                    continue;
                }

                $line = plantTimezoneLineAt($offsets, $match[0][1]);
                $violations[] = "{$relative}:{$line} — {$message}";
            }
        }
    }

    expect($violations)->toBe([], "Días calculados sin la zona de planta:\n".implode("\n", $violations));
});
