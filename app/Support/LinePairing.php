<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Empareja las líneas viejas de un documento con las nuevas al editarlo, para actualizar las que siguen, crear las nuevas
 * y borrar las que se quitaron, cada una por el modelo (así se auditan). Lo usan la fórmula (por ingrediente) y la
 * cotización (por presentación).
 *
 * Cada línea llega como [clave, desempate, desempate…]: la clave dice qué es (el ingrediente, la presentación) y solo se
 * emparejan líneas con la misma clave; los desempates, en orden de importancia, eligen entre parejas posibles (la
 * cotización desempata por color y luego por la línea entera; la fórmula, por la línea entera). La pareja sigue la
 * secuencia común más larga de claves (en orden): quitar una línea del medio no hace que las de abajo parezcan cambiadas.
 *
 * Puntaje de una pareja: `$weight` por la clave más 2^(n-1-k) por cada desempate k que coincide, así un desempate más
 * importante vale más que todos los siguientes juntos. `$weight` supera la suma de todos los desempates posibles, de modo
 * que nunca se sacrifica una pareja por desempatar.
 */
final class LinePairing
{
    /**
     * @param  list<list<string>>  $old  [clave, desempates…] de cada línea vieja, por posición
     * @param  list<list<string>>  $new  lo mismo de cada línea nueva
     * @return array<int, int> posición nueva => posición vieja, solo las emparejadas
     */
    public static function pair(array $old, array $new): array
    {
        // Los índices de vuelta son posiciones: con otras llaves no corresponderían a las líneas de quien llama.
        if (! array_is_list($old) || ! array_is_list($new)) {
            throw new InvalidArgumentException('LinePairing::pair recibe listas: las posiciones devueltas son índices desde 0.');
        }

        $oldCount = count($old);
        $newCount = count($new);
        $tieBreakers = max([0, ...array_map(fn (array $line): int => count($line) - 1, [...$old, ...$new])]);
        $maxTieBreak = (1 << $tieBreakers) - 1;
        $weight = min($oldCount, $newCount) * $maxTieBreak + 1;
        $score = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));

        $pairScore = function (int $i, int $j) use ($old, $new, $weight, $tieBreakers): int {
            $points = $weight;

            for ($k = 1; $k <= $tieBreakers; $k++) {
                if (($old[$i][$k] ?? null) === ($new[$j][$k] ?? null)) {
                    $points += 1 << ($tieBreakers - $k);
                }
            }

            return $points;
        };

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $score[$i][$j] = max(
                    $old[$i][0] === $new[$j][0] ? $score[$i + 1][$j + 1] + $pairScore($i, $j) : 0,
                    $score[$i + 1][$j],
                    $score[$i][$j + 1],
                );
            }
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $oldCount && $j < $newCount) {
            if ($old[$i][0] === $new[$j][0] && $score[$i][$j] === $score[$i + 1][$j + 1] + $pairScore($i, $j)) {
                $pairs[$j] = $i;
                $i++;
                $j++;
            } elseif ($score[$i + 1][$j] >= $score[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }
}
