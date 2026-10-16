<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

/**
 * Textausschnitt um die Fundstelle und das Hervorheben der Suchwoerter.
 *
 * Die Datenbank schneidet bereits ein Fenster um die erste Fundstelle heraus, damit nicht
 * ein halbes Megabyte PDF-Text durch die Verbindung muss. Hier wird daraus ein lesbarer
 * Ausschnitt: an Wortgrenzen beschnitten, mit Auslassungszeichen versehen.
 */
final class Snippet
{
    public const DEFAULT_LENGTH = 220;

    /** So viel Text holt die Abfrage um die Fundstelle herum. */
    public const WINDOW = 600;

    /** So weit vor der Fundstelle beginnt das Fenster. */
    public const LEAD = 120;

    private const ELLIPSIS = '…';

    /**
     * Beschneidet das Rohfenster aus der Datenbank auf einen lesbaren Ausschnitt.
     *
     * @param bool $cutAtStart Das Fenster begann mitten im Text
     */
    public static function fromWindow(string $window, bool $cutAtStart, int $length = self::DEFAULT_LENGTH): string
    {
        $text = self::normalize($window);
        if ('' === $text) {
            return '';
        }

        $prefix = '';
        if ($cutAtStart) {
            // Ein angeschnittenes erstes Wort stiftet mehr Verwirrung als es nuetzt.
            $space = mb_strpos($text, ' ');
            if (false !== $space && $space < 40) {
                $text = ltrim(mb_substr($text, $space + 1));
            }
            $prefix = self::ELLIPSIS . ' ';
        }

        if (mb_strlen($text) <= $length) {
            return $prefix . $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space > $length / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return $prefix . rtrim($cut, " \t.,;:-") . ' ' . self::ELLIPSIS;
    }

    /**
     * Maskiert den Text und setzt die Suchwoerter in mark-Elemente.
     *
     * Reihenfolge ist entscheidend: erst maskieren, dann markieren. Andersherum wuerden die
     * eigenen mark-Elemente mitmaskiert und als Text sichtbar.
     *
     * @param list<string> $words
     */
    public static function highlight(string $text, array $words): string
    {
        $escaped = rex_escape($text);
        if ([] === $words || '' === $escaped) {
            return $escaped;
        }

        $patterns = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ('' === $word) {
                continue;
            }
            // Das Wort wurde als Wortanfang gesucht, also wird auch der Wortanfang markiert.
            $patterns[] = preg_quote(rex_escape($word), '/') . '\p{L}*';
        }

        if ([] === $patterns) {
            return $escaped;
        }

        $result = preg_replace(
            '/(?<![\p{L}\p{N}])(' . implode('|', $patterns) . ')/iu',
            '<mark>$1</mark>',
            $escaped,
        );

        // Bei sehr langem Text oder vielen Woertern kann PCRE aussteigen; dann lieber
        // unmarkiert ausgeben als gar nichts.
        return null === $result ? $escaped : $result;
    }

    /**
     * Vereinheitlicht Zeilenumbrueche und Mehrfachleerzeichen, damit aus Markdown-Text ein
     * einzeiliger Ausschnitt wird.
     */
    private static function normalize(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
