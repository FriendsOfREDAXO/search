<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Word;

/**
 * Zerlegt Text in Woerter und bringt sie auf eine Vergleichsform.
 *
 * Die Faltung ist nicht Kosmetik, sondern notwendig: PHPs levenshtein() arbeitet auf Bytes,
 * nicht auf Zeichen. "Buerger" mit Umlaut gegen "Burger" ergibt deshalb Abstand 2 statt 1,
 * gegen "Burgar" sogar 3 -- ein Tippfehler auf einem Umlaut kostet doppelt und faellt bei
 * einer Schwelle von 2 durch. Verglichen wird darum immer die gefaltete Form, angezeigt
 * immer das Wort, wie es im Text steht.
 */
final class WordFolder
{
    /** Kuerzere Woerter stehen bei der Vorgabe von innodb_ft_min_token_size nicht im Index. */
    public const MIN_LENGTH = 3;

    /** Laengere sind in aller Regel keine Woerter mehr, sondern Kennungen oder Muell. */
    public const MAX_LENGTH = 40;

    /**
     * Nur Buchstaben und Ziffern gelten als Wortzeichen. Der Bindestrich bewusst nicht:
     * "Burg-Ruine" soll zwei Woerter ergeben, damit beide einzeln gefunden werden.
     *
     * Die Umschauer ringsum sind noetig, nicht schmueckend: ohne sie schneidet der Ausdruck
     * aus einer sechzig Zeichen langen Zeichenkette die ersten vierzig heraus und legt ein
     * Wort an, das es nicht gibt. Mit ihnen faellt sie ganz heraus, was gewollt ist -- so
     * lange Gebilde sind Kennungen oder Muell aus einem PDF.
     */
    private const TOKEN = '/(?<![\p{L}\p{N}])[\p{L}\p{N}]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}(?![\p{L}\p{N}])/u';

    /**
     * Westeuropaeische Zeichen auf ihre Grundform. Das deckt die Sprachen ab, in denen
     * REDAXO-Seiten ueblicherweise stehen; alles andere bleibt unveraendert und wird dadurch
     * nur zeichengenau verglichen, was kein Fehler ist, sondern weniger Toleranz.
     */
    private const FOLD = [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'š' => 's', 'ž' => 'z',
    ];

    /**
     * Die Vergleichsform: kleingeschrieben und ohne diakritische Zeichen.
     */
    public static function fold(string $word): string
    {
        return strtr(mb_strtolower($word), self::FOLD);
    }

    /**
     * Alle Woerter eines Textes, in der Schreibweise des Textes, jedes nur einmal.
     *
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        if (!preg_match_all(self::TOKEN, $text, $matches)) {
            return [];
        }

        $seen = [];
        foreach ($matches[0] as $word) {
            // Gross und klein geschrieben ist dasselbe Wort. Behalten wird die erste
            // Schreibweise -- ausser sie ist durchgehend gross: PDF-Ueberschriften bestehen
            // aus Versalien ("STADT EPPSTEIN"), und als Vorschlag saehe das falsch aus.
            $key = mb_strtolower($word);
            $seen[$key] = isset($seen[$key]) ? self::preferredSpelling($seen[$key], $word) : $word;
        }

        return array_values($seen);
    }

    /**
     * Von zwei Schreibweisen desselben Wortes die anzeigbarere: die bisherige, ausser sie
     * besteht aus Versalien und die neue nicht.
     */
    public static function preferredSpelling(string $current, string $candidate): string
    {
        return self::isAllCaps($current) && !self::isAllCaps($candidate) ? $candidate : $current;
    }

    private static function isAllCaps(string $word): bool
    {
        return 1 < mb_strlen($word) && $word === mb_strtoupper($word) && $word !== mb_strtolower($word);
    }
}
