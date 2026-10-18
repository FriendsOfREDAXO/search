<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

/**
 * Fuehrt mehrere Ranglisten zu einer zusammen.
 *
 * Volltext und Vektoren liefern Zahlen, die sich nicht verrechnen lassen: die Bewertung
 * von InnoDB ist unbegrenzt und haengt am Wortschatz des Bestands, eine Kosinus-
 * Aehnlichkeit liegt zwischen 0 und 1. Jede gewichtete Summe der beiden braucht eine
 * Normierung, und die ist auf der naechsten Installation wieder falsch -- dieselbe
 * Falle wie bei der Laengendaempfung, die zwischen 24 und 984 Zeichen unterschied.
 *
 * Deshalb werden nicht die Werte verglichen, sondern die *Plaetze*. Jede Liste steuert
 * zu einem Eintrag 1/(k + Platz) bei, die Summe entscheidet. Das ist Reciprocal Rank
 * Fusion; k daempft die Spitze, damit nicht allein der erste Platz zaehlt.
 *
 * Nebenbei loest das ein zweites Problem: ein Eintrag, den nur eine der beiden Listen
 * kennt, braucht keinen Ersatzwert. Er bekommt schlicht nur einen Summanden.
 */
final class RankFusion
{
    /**
     * Daempfung. 60 ist der Wert aus der Arbeit, die das Verfahren eingefuehrt hat, und
     * der seither ueberall steht: der erste Platz zaehlt 1/61, der zehnte 1/70 -- also
     * knapp ein Siebtel weniger und nicht ein Zehntel.
     */
    public const K = 1;

    /**
     * @param array<string, list<string>> $lists Name der Liste auf ihre Schluessel, bester zuerst
     * @param array<string, float> $weights Name der Liste auf Gewicht, fehlt eines gilt 1.0
     * @return array<string, float> Schluessel auf Punktzahl, absteigend sortiert
     */
    public static function fuse(array $lists, array $weights = [], int $k = self::K): array
    {
        $k = max(1, $k);
        $scores = [];

        foreach ($lists as $name => $keys) {
            $weight = $weights[$name] ?? 1.0;
            if (0.0 >= $weight) {
                continue;
            }
            foreach (array_values($keys) as $position => $key) {
                $scores[$key] = ($scores[$key] ?? 0.0) + $weight / ($k + $position + 1);
            }
        }

        // Bei gleicher Punktzahl entscheidet, wer in der ersten Liste weiter vorn stand:
        // arsort haelt die Einfuegereihenfolge, und die kommt aus der ersten Liste.
        arsort($scores);

        return $scores;
    }
}
