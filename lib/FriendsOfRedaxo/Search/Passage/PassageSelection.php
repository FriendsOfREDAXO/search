<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Passage;

use function count;

/**
 * Entscheidet, welche Abschnitte in den Kontext kommen.
 *
 * Eigene Klasse aus demselben Grund wie FriendsOfRedaxo\Search\Query\CandidateList: hier
 * faellt die Entscheidung, die ein Sprachmodell nachher als Tatsache behandelt, und als
 * reine Funktion auf einer Liste laesst sie sich pruefen, ohne eine Datenbank oder einen
 * KI-Dienst dafuer zu brauchen.
 */
final class PassageSelection
{
    /**
     * Wie viele Passagen die Schwelle ueberhaupt bestanden haetten.
     *
     * Getrennt von {@see select()}, damit sich "es gab nichts Passendes" von "es passte
     * nicht alles hinein" unterscheiden laesst. Ohne diese Trennung meldet eine Antwort
     * ohne jede Passage, sie sei gekuerzt worden -- und der Aufrufer sucht den Fehler
     * bei seiner Zeichengrenze statt bei der Frage.
     *
     * @param list<Passage> $passages absteigend nach Aehnlichkeit sortiert
     */
    public static function countEligible(array $passages, float $minSimilarity): int
    {
        $count = 0;
        foreach ($passages as $passage) {
            if ($passage->similarity < $minSimilarity) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * @param list<Passage> $passages absteigend nach Aehnlichkeit sortiert
     * @return list<Passage>
     */
    public static function select(array $passages, PassageQuery $query): array
    {
        $kept = [];
        $seen = [];
        $characters = 0;

        foreach ($passages as $passage) {
            // Die Liste faellt, ab hier wird es nur schlechter.
            if ($passage->similarity < $query->getMinSimilarity()) {
                break;
            }
            if (count($kept) >= $query->getMaxPassages()) {
                break;
            }

            // Erfassen zwei Quellen dieselbe Datei, steht derselbe Text zweimal im
            // Index -- im Kontext saehe das aus wie die Bestaetigung durch eine zweite
            // Quelle.
            $key = sha1($passage->text);
            if (isset($seen[$key])) {
                continue;
            }

            // Eine Passage, die nicht mehr ganz hineinpasst, faellt weg statt gekuerzt
            // zu werden: ein abgeschnittener Satz ist schlimmer als ein fehlender
            // Absatz, weil ein Modell ihn trotzdem zu Ende denkt. Die erste kommt immer
            // mit -- sonst bliebe bei einer knappen Grenze gar nichts uebrig.
            $length = $passage->getCharacters();
            if ([] !== $kept && $characters + $length > $query->getMaxCharacters()) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $passage;
            $characters += $length;
        }

        return $kept;
    }
}
