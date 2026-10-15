<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Markdown ist das Rohformat des Index: alles, was eine Quelle liefert, wird nach Markdown
 * gewandelt und der Klartext fuer den Volltext daraus abgeleitet.
 *
 * Der Grund ist die Weiterverarbeitung. Klartext verliert Ueberschriften, Listen und Tabellen,
 * und genau diese Struktur braucht eine semantische Suche, um sinnvoll zu zerlegen. Markdown
 * behaelt sie, bleibt aber lesbar genug, um daraus in einem Schritt Klartext zu machen.
 */
final class Markdown
{
    private static ?HtmlConverter $converter = null;

    /**
     * Ob die Umwandlung aus HTML zur Verfuegung steht. Ohne das vendor-Verzeichnis faellt
     * der Aufrufer auf {@see Text::fromHtml()} zurueck.
     */
    public static function isAvailable(): bool
    {
        return class_exists(HtmlConverter::class);
    }

    /**
     * HTML nach Markdown. Scheitert die Umwandlung, ist Klartext das Ergebnis; ein leerer
     * Index waere der schlechtere Ausgang als ein Dokument ohne Struktur.
     */
    public static function fromHtml(?string $html): string
    {
        $html = (string) $html;
        if ('' === trim($html)) {
            return '';
        }

        if (!self::isAvailable()) {
            return Text::fromHtml($html);
        }

        try {
            $markdown = self::converter()->convert($html);
        } catch (Throwable) {
            return Text::fromHtml($html);
        }

        // Der Konverter laesst Entities stehen; fuer Index und Embeddings wollen wir das Zeichen.
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(self::collapseBlankLines($markdown));
    }

    /**
     * Markdown nach Klartext fuer den Volltextindex. Syntaxzeichen fallen weg, der Text
     * dahinter bleibt: aus einem Link wird seine Beschriftung, aus einem Bild sein Alt-Text,
     * aus einer Tabellenzeile eine Zeile mit Leerzeichen zwischen den Zellen.
     */
    public static function toPlainText(?string $markdown): string
    {
        $text = (string) $markdown;
        if ('' === trim($text)) {
            return '';
        }

        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::plainLine($line);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        $text = implode("\n", $lines);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{2,}/', "\n", $text);

        return trim($text);
    }

    /**
     * Eine einzelne Zeile entkleiden. null bedeutet: die Zeile traegt keinen Text, etwa eine
     * Trennlinie oder die Strichzeile unter einem Tabellenkopf.
     */
    private static function plainLine(string $line): ?string
    {
        $trimmed = trim($line);
        if ('' === $trimmed) {
            return '';
        }

        // Trennlinie und Zaunzeile eines Codeblocks
        if (preg_match('/^(-{3,}|\*{3,}|_{3,}|`{3,}|~{3,})$/', $trimmed)) {
            return null;
        }
        // Strichzeile eines Tabellenkopfs: nur |, -, : und Leerzeichen
        if (preg_match('/^\|?[\s:|-]+\|[\s:|-]*$/', $trimmed) && str_contains($trimmed, '-')) {
            return null;
        }

        // Tabellenzeile: Zellen durch Leerzeichen trennen
        if (str_starts_with($trimmed, '|')) {
            $cells = array_map('trim', explode('|', trim($trimmed, '|')));
            $trimmed = implode(' ', array_filter($cells, static fn (string $c): bool => '' !== $c));
        }

        $trimmed = (string) preg_replace('/^#{1,6}\s+/', '', $trimmed);
        $trimmed = (string) preg_replace('/^>\s?/', '', $trimmed);
        $trimmed = (string) preg_replace('/^(?:[-*+]|\d+\.)\s+/', '', $trimmed);

        // Bild vor Link ersetzen, sonst bleibt das Ausrufezeichen stehen.
        $trimmed = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $trimmed);
        $trimmed = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $trimmed);

        $trimmed = (string) preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $trimmed);
        $trimmed = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '$1', $trimmed);
        $trimmed = (string) preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '$1', $trimmed);
        $trimmed = (string) preg_replace('/`([^`\n]+)`/', '$1', $trimmed);
        $trimmed = (string) preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!|])/', '$1', $trimmed);

        return trim($trimmed);
    }

    /**
     * Leere Absaetze aus dem Markup hinterlassen Zeilen aus einem einzelnen Leerzeichen.
     * Die zaehlen als leer, sonst bleiben zwischen den Abschnitten Luecken stehen.
     */
    private static function collapseBlankLines(string $text): string
    {
        $text = (string) preg_replace('/^[ \t\x{00A0}]+$/mu', '', $text);

        return (string) preg_replace('/\n{3,}/', "\n\n", $text);
    }

    private static function converter(): HtmlConverter
    {
        if (null !== self::$converter) {
            return self::$converter;
        }

        $converter = new HtmlConverter([
            // Unbekannte Tags verschwinden, ihr Text bleibt.
            'strip_tags' => true,
            'remove_nodes' => 'script style noscript iframe svg',
            'header_style' => 'atx',
            'bold_style' => '**',
            'italic_style' => '*',
            'hard_break' => false,
            'strip_placeholder_links' => true,
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter());

        return self::$converter = $converter;
    }
}
