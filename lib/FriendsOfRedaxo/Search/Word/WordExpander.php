<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Word;

use FriendsOfRedaxo\Search\Analysis\Analyzer;

use function count;
use function in_array;

/**
 * Sucht zu einem eingegebenen Wort verwandte Formen in der Wortliste.
 *
 * Zwei Dinge, die auseinandergehalten werden muessen:
 *
 * Die *Erweiterung* laeuft immer mit. "Ruine" bekommt "Burgruine" und "Ruinen" dazu, alle
 * als ODER-Gruppe. Das kann nur mehr Treffer bringen, nie weniger, und die exakte Form
 * bleibt durch den Zuschlag in der Bewertung oben.
 *
 * Die *Korrektur* laeuft nur, wenn die Suche zu wenig gefunden hat. Sie ersetzt das Wort,
 * statt es zu ergaenzen, und wird dem Nutzer angezeigt. Dass sie erst im zweiten Anlauf
 * kommt, ist die wichtigste Regel ueberhaupt: "burg" liefert zwanzig Treffer, da ist jede
 * Korrektur ueberfluessig.
 */
final class WordExpander
{
    /** So viele zusaetzliche Formen je Wort, haeufigste zuerst. */
    public const MAX_FORMS = 8;

    /** Erst ab dieser Laenge wird nach enthaltenen Teilen gesucht. */
    public const MIN_INFIX_LENGTH = 4;

    /**
     * Erlaubter Abstand bei der Tippfehlerkorrektur, nach Wortlaenge in Zeichen.
     *
     * Die Staffelung ist keine Vorsicht, sondern gemessen notwendig. Bei einem Wort aus vier
     * Zeichen trifft Abstand 2 fast alles: "burg" wurde damit zu "fuer", "nur", "zur",
     * "blog", "kurz" korrigiert. Kurze Woerter werden deshalb gar nicht korrigiert.
     */
    private const DISTANCE = [4 => 0, 7 => 1];

    private const DISTANCE_MAX = 2;

    private readonly WordRepository $words;

    private readonly WordFormRepository $forms;

    public function __construct(?WordRepository $words = null, ?WordFormRepository $forms = null)
    {
        $this->words = $words ?? new WordRepository();
        $this->forms = $forms ?? new WordFormRepository();
    }

    /**
     * Zusaetzliche Formen zu einem Wort: erst die analysierten, dann enthaltene Teile.
     *
     * Die Reihenfolge ist das Entscheidende. Die Analyse liefert die gemeinten Verwandten:
     * "Ruine" fuehrt ueber den Stamm zu "Ruinen" und ueber die Zerlegung zu "Burgruine",
     * waehrend "Hamburg" und "Einbuergerung" draussen bleiben. Sie findet dafuer nicht
     * alles -- gemessen faellt bei "Burg" unter anderem "Ganerbenburg" durch, weil das
     * Woerterbuch den ersten Teil nicht kennt. Deshalb wird mit der Suche nach enthaltenen
     * Teilen aufgefuellt: sie bringt den Rest, und weil die guten Formen schon vorne
     * stehen, landet ihr Beifang bei der Begrenzung als Erstes unter dem Strich.
     *
     * Ohne Analyse -- Quelle ohne Sprachangabe, fehlende Bibliothek -- bleibt genau das
     * Verhalten von vorher.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<string>
     */
    public function expand(string $word, array $sourceIds = [], array $languages = [], int $max = self::MAX_FORMS): array
    {
        if (mb_strlen($word) < self::MIN_INFIX_LENGTH) {
            return [];
        }

        $folded = WordFolder::fold($word);
        $forms = [];

        $this->take($forms, $this->analyzed($word, $sourceIds, $languages, $max), $folded, $max);
        if (count($forms) < $max) {
            $this->take($forms, $this->words->findByInfix($word, $sourceIds, $languages, $max * 3), $folded, $max);
        }

        return $forms;
    }

    /**
     * Die wahrscheinlichste Schreibweise zu einem Wort, das nichts gefunden hat.
     *
     * Verglichen wird auf der gefalteten Form, weil levenshtein() Bytes zaehlt: ein
     * Tippfehler auf einem Umlaut kostet sonst zwei statt einem Schritt.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     */
    public function correct(string $word, array $sourceIds = [], array $languages = []): ?string
    {
        $max = self::maxDistance($word);
        if (0 === $max) {
            return null;
        }

        $folded = WordFolder::fold($word);
        $length = mb_strlen($word);

        $beste = null;
        $besterAbstand = $max + 1;
        $besteHaeufigkeit = 0;

        foreach ($this->words->findByLength($length - $max, $length + $max, $sourceIds, $languages) as $row) {
            if ($row['folded'] === $folded) {
                // Das Wort gibt es. Dann war es kein Tippfehler.
                return null;
            }

            $abstand = levenshtein($folded, $row['folded']);
            if ($abstand > $max) {
                continue;
            }
            if ($abstand < $besterAbstand || ($abstand === $besterAbstand && $row['docs'] > $besteHaeufigkeit)) {
                $beste = $row['word'];
                $besterAbstand = $abstand;
                $besteHaeufigkeit = $row['docs'];
            }
        }

        return $beste;
    }

    /**
     * Der erlaubte Abstand fuer ein Wort dieser Laenge.
     */
    public static function maxDistance(string $word): int
    {
        $length = mb_strlen($word);
        foreach (self::DISTANCE as $bis => $abstand) {
            if ($length <= $bis) {
                return $abstand;
            }
        }

        return self::DISTANCE_MAX;
    }

    /**
     * Woerter, die mit dem eingegebenen eine Nachschlageform teilen.
     *
     * Gefragt wird mit den Formen des eingegebenen Wortes, gebildet je Sprache: dieselbe
     * Zeichenkette ergibt auf Deutsch und auf Englisch verschiedene Staemme, und die
     * Formtabelle traegt deshalb die Sprache mit.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<array{word: string, docs: int}>
     */
    private function analyzed(string $word, array $sourceIds, array $languages, int $max): array
    {
        $lookup = [];
        foreach ($this->languagesToAsk($languages) as $language) {
            foreach (Analyzer::forLanguage($language)?->forms($word) ?? [] as $form) {
                $lookup[$form] = true;
            }
        }

        if ([] === $lookup) {
            return [];
        }

        return $this->forms->findByForms(array_keys($lookup), $sourceIds, $languages, $max * 3);
    }

    /**
     * Die Sprachen, in denen nachgeschlagen wird.
     *
     * Ohne Einschraenkung an der Anfrage sind das die Sprachen, zu denen Formen vorliegen
     * -- nicht alle, die das AddOn kennt. Der Unterschied ist nicht nur Laufzeit: das
     * eingegebene Wort wuerde sonst von vierzehn Stammbildnern verschieden verstuemmelt,
     * und jeder dieser Staemme suchte in der Tabelle nach Zufallstreffern.
     *
     * Aussortiert wird hier nichts: zu einer Sprache ohne Analyse gibt es keinen
     * Analyzer, und die Abfrage danach faellt eine Zeile weiter von selbst aus.
     *
     * @param list<string> $languages
     * @return list<string>
     */
    private function languagesToAsk(array $languages): array
    {
        return [] === $languages ? $this->forms->languages() : $languages;
    }

    /**
     * Uebernimmt Kandidaten, bis die Obergrenze erreicht ist.
     *
     * Uebersprungen wird, was der Volltextindex mit "+wort*" ohnehin schon findet: das Wort
     * selbst und alles, was damit anfaengt. Gefragt ist hier, was der Index nicht kann --
     * die Form in der Wortmitte und die gebeugte Form mit anderem Wortanfang.
     *
     * @param list<string> $forms
     * @param list<array{word: string, docs: int}> $candidates
     */
    private function take(array &$forms, array $candidates, string $folded, int $max): void
    {
        foreach ($candidates as $row) {
            if (count($forms) >= $max) {
                return;
            }
            $candidate = WordFolder::fold($row['word']);
            if ($candidate === $folded || str_starts_with($candidate, $folded)) {
                continue;
            }
            if (!in_array($candidate, $forms, true)) {
                $forms[] = $candidate;
            }
        }
    }
}
