<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Empareja las líneas viejas de un documento con las nuevas al editarlo, para actualizar las que siguen, crear las nuevas
 * y borrar las que se quitaron, cada una por el modelo (así se auditan). Lo usan la fórmula (por ingrediente) y la
 * cotización (por presentación).
 *
 * Cada línea llega como [clave, firma]: la clave dice qué es (el ingrediente, la presentación) y solo se emparejan líneas
 * con la misma clave; la firma suma todo lo demás y desempata. La pareja sigue la secuencia común más larga de claves
 * (en orden): quitar una línea del medio no hace que las de abajo parezcan cambiadas. Entre las secuencias igual de
 * largas prefiere la que deja más líneas idénticas: cada pareja vale `$weight` por la clave (más que todas las idénticas
 * juntas, así que nunca se sacrifica una pareja) y 1 más si además la firma es igual.
 */
final class LinePairing
{
    /**
     * @param  array<int, array{0: string, 1: string}>  $old  [clave, firma] de cada línea vieja, por posición
     * @param  array<int, array{0: string, 1: string}>  $new  lo mismo de cada línea nueva
     * @return array<int, int> posición nueva => posición vieja, solo las emparejadas
     */
    public static function pair(array $old, array $new): array
    {
        $old = array_values($old);
        $new = array_values($new);
        $oldCount = count($old);
        $newCount = count($new);
        $weight = min($oldCount, $newCount) + 1;
        $score = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        $pairScore = fn (int $i, int $j): int => $weight + ($old[$i][1] === $new[$j][1] ? 1 : 0);

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
