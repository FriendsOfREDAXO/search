<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

/**
 * Schneidet einen Text in Stuecke einer ungefaehren Groesse.
 *
 * Gebaut wird von unten nach oben, nicht von oben nach unten: der Text wird in Absaetze
 * zerlegt und daraus werden Stuecke zusammengesetzt, bis die Groesse erreicht ist. Der
 * naheliegende Weg -- alle n Zeichen schneiden und dann eine Bruchstelle in der Naehe
 * suchen -- hat zwei Nachteile. Er zerteilt Absaetze auch dann, wenn es nicht noetig
 * waere, und er laeuft auf einem halben Megabyte PDF in quadratische Laufzeit, weil
 * mb_substr jedes Mal vom Anfang an zaehlt.
 *
 * Ein Absatz, der fuer sich schon zu gross ist, wird an Satzenden geteilt und erst
 * danach notfalls an Leerzeichen.
 */
final class TextSplitter
{
    /**
     * Ein Stueck darf diesen Anteil ueber die Zielgroesse hinausgehen, wenn dadurch ein
     * Absatz ganz bleibt. Lieber ein Fuenftel zu lang als mitten im Satz getrennt.
     */
    private const TOLERANCE = 0.2;

    /**
     * @return list<string>
     */
    public static function split(string $text, int $size, int $overlap): array
    {
        $text = trim($text);
        if ('' === $text) {
            return [];
        }

        $size = max(200, $size);
        // Mehr Ueberlappung als die halbe Groesse hiesse, dass jedes Stueck zur Haelfte
        // aus dem vorigen besteht -- das vervielfacht die Zahl der Abschnitte, ohne mehr
        // Zusammenhang zu stiften.
        $overlap = max(0, min($overlap, intdiv($size, 2)));

        $limit = (int) ($size * (1 + self::TOLERANCE));

        $chunks = [];
        $buffer = '';
        foreach (self::paragraphs($text) as $paragraph) {
            foreach (self::fit($paragraph, $size) as $piece) {
                if ('' === $buffer) {
                    $buffer = $piece;
                    continue;
                }
                if (mb_strlen($buffer) + 2 + mb_strlen($piece) <= $limit) {
                    $buffer .= "\n\n" . $piece;
                    continue;
                }
                $chunks[] = $buffer;
                $buffer = self::tail(end($chunks), $overlap) . $piece;
            }
        }
        if ('' !== $buffer) {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * Absaetze, leere weggelassen.
     *
     * @return list<string>
     */
    private static function paragraphs(string $text): array
    {
        $parts = preg_split('/\n\s*\n+/u', $text) ?: [];

        $paragraphs = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $paragraphs[] = $part;
            }
        }

        return $paragraphs;
    }

    /**
     * Ein Absatz, auf Stuecke heruntergebrochen, die fuer sich passen.
     *
     * @return list<string>
     */
    private static function fit(string $paragraph, int $size): array
    {
        if (mb_strlen($paragraph) <= $size) {
            return [$paragraph];
        }

        $pieces = [];
        $buffer = '';
        foreach (self::sentences($paragraph) as $sentence) {
            if ('' === $buffer) {
                $buffer = $sentence;
            } elseif (mb_strlen($buffer) + 1 + mb_strlen($sentence) <= $size) {
                $buffer .= ' ' . $sentence;
            } else {
                $pieces[] = $buffer;
                $buffer = $sentence;
            }

            // Auch ein einzelner Satz kann zu lang sein -- eine Tabellenzeile aus einem
            // PDF etwa hat kein Satzende.
            while (mb_strlen($buffer) > $size) {
                $cut = self::lastSpaceBefore($buffer, $size);
                $pieces[] = trim(mb_substr($buffer, 0, $cut));
                $buffer = trim(mb_substr($buffer, $cut));
            }
        }
        if ('' !== $buffer) {
            $pieces[] = $buffer;
        }

        return $pieces;
    }

    /**
     * @return list<string>
     */
    private static function sentences(string $paragraph): array
    {
        // Geteilt wird hinter Satzzeichen, aber nur wenn ein Leerzeichen und ein
        // Grossbuchstabe oder eine Ziffer folgen: "z. B." und "Nr. 5" bleiben so ganz.
        $parts = preg_split('/(?<=[.!?:;])\s+(?=[\p{Lu}\p{N}])/u', $paragraph) ?: [];

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $sentences[] = $part;
            }
        }

        return [] === $sentences ? [$paragraph] : $sentences;
    }

    /**
     * Die Position des letzten Leerzeichens vor der Grenze, oder die Grenze selbst.
     */
    private static function lastSpaceBefore(string $text, int $limit): int
    {
        $head = mb_substr($text, 0, $limit);
        $position = mb_strrpos($head, ' ');

        // Ohne Leerzeichen im ganzen Fenster -- etwa eine lange Kennung -- wird hart
        // getrennt. Besser ein zerschnittenes Wort als ein Stueck, das nicht endet.
        return false === $position || $position < intdiv($limit, 2) ? $limit : $position;
    }

    /**
     * Die letzten Zeichen eines Stuecks als Ueberleitung in das naechste, an einer
     * Wortgrenze abgeschnitten.
     */
    private static function tail(string $chunk, int $overlap): string
    {
        if (0 >= $overlap || '' === $chunk) {
            return '';
        }

        $tail = mb_substr($chunk, -$overlap);
        $space = mb_strpos($tail, ' ');
        if (false !== $space) {
            $tail = mb_substr($tail, $space + 1);
        }
        $tail = trim($tail);

        return '' === $tail ? '' : $tail . "\n\n";
    }
}
