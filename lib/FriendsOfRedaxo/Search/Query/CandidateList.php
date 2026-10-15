<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

/**
 * Rechenschritte auf der Kandidatenliste, bevor daraus eine Seite wird.
 *
 * Eigene Klasse aus demselben Grund wie {@see ScoreExpression}: hier wird entschieden,
 * welche Treffer der Nutzer ueberhaupt zu sehen bekommt und was unter der Liste an
 * Zahlen steht. Als reine Funktionen auf Arrays laesst sich das pruefen, ohne eine
 * Datenbank dafuer zu brauchen.
 *
 * @phpstan-type Candidate array{id: int, namespace: string, item_id: string, type: string, source_id: int, clang_id: int, score: float}
 */
final class CandidateList
{
    /**
     * Wirft Treffer weg, die gemessen am besten Treffer zu schwach sind.
     *
     * Verglichen wird ein Anteil, kein absoluter Wert: die Bewertung haengt am Wortschatz
     * des Bestands, "mindestens 4,5" waere auf jeder Installation etwas anderes.
     *
     * @param list<Candidate> $ranked absteigend nach Bewertung sortiert -- darauf verlaesst
     *                                sich der vorzeitige Abbruch in der Schleife
     * @return list<Candidate>
     */
    public static function dropBelowMinRelevance(array $ranked, float $best, float $minRelevance): array
    {
        // Abkuerzung ohne eigene Wirkung: die Schwelle waere 0, und keine Bewertung ist
        // kleiner als 0. Sie erspart nur das Umkopieren der ganzen Liste.
        if (0.0 >= $minRelevance) {
            return $ranked;
        }

        $threshold = $best * $minRelevance;
        $kept = [];
        foreach ($ranked as $candidate) {
            // Ebenfalls nur schneller, nicht anders: die Liste ist absteigend sortiert,
            // ab hier kommt nichts Besseres mehr.
            if ($candidate['score'] < $threshold) {
                break;
            }
            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * Zaehlt die Treffer je Auspraegung -- "12 Artikel, 3 Dokumente".
     *
     * Gezaehlt wird ueber die ganze Trefferliste, nicht ueber die angezeigte Seite: eine
     * Verteilung, die sich beim Blaettern aendert, waere unbrauchbar.
     *
     * @param list<Candidate> $ranked
     * @param list<string> $facets
     * @return array<string, array<string, int>>
     */
    public static function countFacets(array $ranked, array $facets): array
    {
        if ([] === $facets || [] === $ranked) {
            return [];
        }

        $counts = [];
        foreach ($facets as $facet) {
            $counts[$facet] = [];
        }

        foreach ($ranked as $candidate) {
            foreach ($facets as $facet) {
                if (!isset($candidate[$facet])) {
                    continue;
                }
                $value = (string) $candidate[$facet];
                $counts[$facet][$value] = ($counts[$facet][$value] ?? 0) + 1;
            }
        }

        foreach ($counts as $facet => $values) {
            arsort($values);
            $counts[$facet] = $values;
        }

        return $counts;
    }

    /**
     * Der Anteil einer Bewertung an der besten dieser Suche, 0 bis 1.
     */
    public static function relevance(float $score, float $best): float
    {
        if (0.0 >= $best) {
            return 0.0;
        }

        return min(1.0, max(0.0, $score / $best));
    }
}
