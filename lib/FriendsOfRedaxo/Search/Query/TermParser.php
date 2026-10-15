<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use rex_extension;
use rex_extension_point;
use rex_sql;
use Throwable;

use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Macht aus freier Eingabe eine Abfrage im BOOLEAN MODE.
 *
 * Die Regeln stehen hier und nirgends sonst:
 *
 * 1. Operatorzeichen werden entfernt. Es gibt keine Abfragesprache, also auch keine
 *    Moeglichkeit, mit einem versehentlichen Minus die eigene Suche zu zerstoeren.
 * 2. Jedes Wort muss vorkommen. Das entspricht dem, was Menschen von einer Suche erwarten:
 *    mehr Woerter, weniger Treffer.
 * 3. Ein Wort zaehlt als Wortanfang: "Burg" findet auch "Burgmauer". Wie daraus ein
 *    BOOLEAN-Ausdruck wird, steht in {@see SearchTerms}; was in der Wortmitte steht,
 *    ergaenzt der WordExpander.
 * 4. Woerter, die der Index gar nicht kennt, fliegen raus statt alles zu blockieren -- zu
 *    kurze und Stoppwoerter. Welche das sind, sagt die Datenbank, nicht eine Liste hier.
 *
 * Regel 4 ist kein Feinschliff. InnoDB bringt eine englische Stoppwortliste mit, auf der
 * ausgerechnet "und" steht (neben "de", "en", "la", "com", "www"). Ohne diese Regel liefert
 * die Eingabe "Espresso und Boule" auf einer deutschen Seite null Treffer, weil das
 * geforderte "und" im Index nicht existiert.
 */
final class TermParser
{
    /**
     * Operator- und Platzhalterzeichen des BOOLEAN MODE. Sie werden zu Leerzeichen, damit
     * "rot/gruen" zwei Woerter ergibt statt eines unbekannten.
     */
    private const OPERATORS = '/[+\-<>()~*"@\/\\\\]+/u';

    /** Mehr Woerter bringen keine besseren Treffer, kosten aber Abfragezeit. */
    private const MAX_WORDS = 12;

    private static ?int $minTokenSize = null;

    /** @var list<string>|null */
    private static ?array $stopwords = null;

    public function parse(string $input): SearchTerms
    {
        $words = [];
        $ignored = [];

        foreach ($this->split($input) as $word) {
            if (count($words) >= self::MAX_WORDS) {
                break;
            }

            $reason = $this->rejectionReason($word);
            if (null !== $reason) {
                $ignored[$word] = $reason;
                continue;
            }

            if (!in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        $terms = new SearchTerms($words, $ignored);

        /**
         * Hier haengen spaeter Stemming, Synonyme und die unscharfe Suche ein: sie bekommen
         * die fertig zerlegten Begriffe und duerfen den Ausdruck ersetzen.
         *
         * @var SearchTerms $result
         */
        $result = rex_extension::registerPoint(new rex_extension_point('SEARCH_QUERY_TERMS', $terms, [
            'input' => $input,
        ]));

        return $result;
    }

    /**
     * @return list<string>
     */
    private function split(string $input): array
    {
        $cleaned = (string) preg_replace(self::OPERATORS, ' ', $input);
        $parts = preg_split('/\s+/u', trim($cleaned)) ?: [];

        $words = [];
        foreach ($parts as $part) {
            if ('' !== $part) {
                $words[] = $part;
            }
        }

        return $words;
    }

    private function rejectionReason(string $word): ?string
    {
        if (mb_strlen($word) < self::getMinTokenSize()) {
            return SearchTerms::REASON_TOO_SHORT;
        }

        if (in_array(mb_strtolower($word), self::getStopwords(), true)) {
            return SearchTerms::REASON_STOPWORD;
        }

        return null;
    }

    /**
     * Woerter unterhalb dieser Laenge stehen nicht im Volltextindex.
     */
    public static function getMinTokenSize(): int
    {
        if (null !== self::$minTokenSize) {
            return self::$minTokenSize;
        }

        try {
            $value = rex_sql::factory()->getArray('SELECT @@innodb_ft_min_token_size AS size')[0]['size'] ?? 3;
            self::$minTokenSize = max(1, (int) $value);
        } catch (Throwable) {
            self::$minTokenSize = 3;
        }

        return self::$minTokenSize;
    }

    /**
     * Die Stoppwortliste, die der Server tatsaechlich verwendet.
     *
     * Eine eigene Liste hier waere geraten; INNODB_FT_DEFAULT_STOPWORD ist die, nach der
     * beim Indizieren entschieden wurde. Ist das Stoppwortverhalten abgeschaltet, ist die
     * Liste leer und es wird nichts weggelassen.
     *
     * @return list<string>
     */
    public static function getStopwords(): array
    {
        if (null !== self::$stopwords) {
            return self::$stopwords;
        }

        self::$stopwords = [];

        try {
            $sql = rex_sql::factory();
            $enabled = $sql->getArray('SELECT @@innodb_ft_enable_stopword AS enabled')[0]['enabled'] ?? 1;
            if (!(bool) $enabled) {
                return self::$stopwords;
            }

            foreach ($sql->getArray('SELECT value FROM information_schema.INNODB_FT_DEFAULT_STOPWORD') as $row) {
                $value = $row['value'] ?? null;
                if (is_string($value) && '' !== $value) {
                    self::$stopwords[] = mb_strtolower($value);
                }
            }
        } catch (Throwable) {
            // Fehlt die Tabelle oder das Recht darauf, wird eben nichts weggelassen.
            self::$stopwords = [];
        }

        return self::$stopwords;
    }

    /**
     * Nur fuer Tests: die gemerkten Serverwerte vergessen oder vorgeben.
     *
     * @param list<string>|null $stopwords
     */
    public static function reset(?int $minTokenSize = null, ?array $stopwords = null): void
    {
        self::$minTokenSize = $minTokenSize;
        self::$stopwords = is_array($stopwords) ? array_values($stopwords) : null;
    }
}
