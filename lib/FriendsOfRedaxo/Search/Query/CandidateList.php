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
 * @phpstan-type Candidate array{id: int, namespace: string, item_id: string, type: string, source_id: int, clang_id: int, language: string, direct: bool, score: float, relevance?: float}
 */
final class CandidateList
{
    /**
     * Setzt jedem Kandidaten seine Relevanz, 0 bis 1 und von oben nach unten fallend.
     *
     * Die Bewertung allein taugt dafuer nicht mehr, seit eine Erweiterung mitsuchen kann.
     * Ein Treffer, der nur ueber eine verwandte Form kam, steht hinten -- seine rohe
     * Bewertung kann trotzdem hoeher sein, weil sie die Haeufigkeit eines *anderen* Wortes
     * misst. Gemessen bekam der Haushaltsplan bei der Suche nach "Häuser" eine Bewertung
     * von 6,06, die Seite mit dem gesuchten Wort 2,94.
     *
     * Deshalb werden die beiden Gruppen getrennt skaliert: die direkten Treffer auf ihren
     * besten, die erweiterten in den Bereich unterhalb des schwaechsten direkten. Damit
     * faellt die Zahl mit der Position, und "mindestens dreissig Prozent" bedeutet wieder
     * etwas -- ein erweiterter Treffer kann einen direkten nie ueberholen.
     *
     * @param list<Candidate> $ranked absteigend sortiert, direkte Treffer zuerst
     * @return list<Candidate>
     */
    public static function rank(array $ranked): array
    {
        $bestDirect = 0.0;
        $bestExpanded = 0.0;
        foreach ($ranked as $candidate) {
            if ($candidate['direct']) {
                $bestDirect = max($bestDirect, $candidate['score']);
            } else {
                $bestExpanded = max($bestExpanded, $candidate['score']);
            }
        }

        // Die Obergrenze fuer die erweiterten Treffer: so viel, wie der schwaechste direkte
        // noch hat. Gibt es keinen direkten, steht die Gruppe fuer sich und bekommt die
        // volle Spanne.
        $ceiling = 1.0;
        foreach ($ranked as $candidate) {
            if ($candidate['direct']) {
                $ceiling = self::relevance($candidate['score'], $bestDirect);
            }
        }

        foreach ($ranked as $i => $candidate) {
            $ranked[$i]['relevance'] = $candidate['direct']
                ? self::relevance($candidate['score'], $bestDirect)
                : $ceiling * self::relevance($candidate['score'], $bestExpanded);
        }

        return $ranked;
    }

    /**
     * Wirft Treffer weg, die gemessen am besten Treffer zu schwach sind.
     *
     * Verglichen wird ein Anteil, kein absoluter Wert: die Bewertung haengt am Wortschatz
     * des Bestands, "mindestens 4,5" waere auf jeder Installation etwas anderes.
     *
     * @param list<Candidate> $ranked durch {@see rank()} gegangen und damit fallend
     *                                nach Relevanz -- darauf verlaesst sich der
     *                                vorzeitige Abbruch in der Schleife
     * @return list<Candidate>
     */
    public static function dropBelowMinRelevance(array $ranked, float $minRelevance): array
    {
        // Abkuerzung ohne eigene Wirkung: die Schwelle waere 0, und keine Relevanz ist
        // kleiner als 0. Sie erspart nur das Umkopieren der ganzen Liste.
        if (0.0 >= $minRelevance) {
            return $ranked;
        }

        $kept = [];
        foreach ($ranked as $candidate) {
            // Ebenfalls nur schneller, nicht anders: die Liste faellt, ab hier kommt
            // nichts Besseres mehr.
            if (($candidate['relevance'] ?? 0.0) < $minRelevance) {
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
     * Der Anteil einer Bewertung an der besten ihrer Gruppe, 0 bis 1.
     */
    public static function relevance(float $score, float $best): float
    {
        if (0.0 >= $best) {
            return 0.0;
        }

        return min(1.0, max(0.0, $score / $best));
    }
}
