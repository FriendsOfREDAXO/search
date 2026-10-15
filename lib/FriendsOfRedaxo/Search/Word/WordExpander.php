<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Word;

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

    public function __construct(?WordRepository $words = null)
    {
        $this->words = $words ?? new WordRepository();
    }

    /**
     * Zusaetzliche Formen zu einem Wort: Wortanfang und enthaltene Teile.
     *
     * Der Wortanfang kommt zuerst, weil "+wort*" im Volltextindex ohnehin schon so sucht --
     * gefragt ist hier vor allem, was der Index nicht kann: die Form in der Wortmitte.
     *
     * @param list<int> $sourceIds
     * @param list<int> $clangIds
     * @return list<string>
     */
    public function expand(string $word, array $sourceIds = [], array $clangIds = [], int $max = self::MAX_FORMS): array
    {
        if (mb_strlen($word) < self::MIN_INFIX_LENGTH) {
            return [];
        }

        $forms = [];
        $folded = WordFolder::fold($word);

        foreach ($this->words->findByInfix($word, $sourceIds, $clangIds, $max * 3) as $row) {
            $candidate = WordFolder::fold($row['word']);
            // Was schon mit "+wort*" gefunden wird, muss nicht zusaetzlich aufgezaehlt werden.
            if ($candidate === $folded || str_starts_with($candidate, $folded)) {
                continue;
            }
            if (!in_array($candidate, $forms, true)) {
                $forms[] = $candidate;
            }
            if (count($forms) >= $max) {
                break;
            }
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
     * @param list<int> $clangIds
     */
    public function correct(string $word, array $sourceIds = [], array $clangIds = []): ?string
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

        foreach ($this->words->findByLength($length - $max, $length + $max, $sourceIds, $clangIds) as $row) {
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
}
