<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

use function array_slice;
use function count;
use function in_array;

/**
 * Macht aus spaltenweise ausgerichtetem Text Markdown mit Tabellen.
 *
 * Grundlage ist die Ausgabe von "pdftotext -layout": dort bleiben die Spaltenpositionen
 * erhalten, und eine Spaltengrenze ist eine Zeichenposition, an der in allen Zeilen eines
 * Blocks ein Leerzeichen steht.
 *
 * Bewusste Beschraenkung: Zahlenspalten stehen rechtsbuendig, ihre Ueberschrift beginnt
 * deshalb an einer anderen Stelle als die Werte darunter. Eine Kopfzeile laesst sich damit
 * nicht zuverlaessig zuordnen. Statt eine falsche Zuordnung zu behaupten, bleibt der Kopf
 * in solchen Faellen leer und die erste Zeile wird als Datenzeile ausgegeben. Fuer Volltext
 * und Embeddings zaehlt, dass Beschriftung und Werte einer Zeile beieinanderbleiben.
 */
final class LayoutTableParser
{
    /** Mindestbreite einer Luecke, damit sie als Spaltengrenze zaehlt. */
    private const MIN_GAP = 2;

    /** Weniger Zeilen ergeben keine Tabelle, sondern Fliesstext mit Einzug. */
    private const MIN_ROWS = 3;

    /** Weniger Spalten sind eine Aufzaehlung, keine Tabelle. */
    private const MIN_COLUMNS = 3;

    /** Anteil der Zeilen, die mehr als eine gefuellte Zelle haben muessen. */
    private const MIN_FILLED_RATIO = 0.6;

    public static function toMarkdown(string $layoutText): string
    {
        $out = [];
        foreach (self::blocks($layoutText) as $block) {
            $table = self::blockToTable($block);
            $out[] = null !== $table ? $table : self::blockToText($block);
        }

        return trim(implode("\n\n", array_filter($out, static fn (string $part): bool => '' !== $part)));
    }

    /**
     * Zusammenhaengende, nicht leere Zeilen bilden einen Block.
     *
     * @return list<list<string>>
     */
    private static function blocks(string $text): array
    {
        $blocks = [];
        $current = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = rtrim((string) $line);
            // Seitenumbrueche von pdftotext trennen ebenfalls
            $line = str_replace("\f", '', $line);
            if ('' === trim($line)) {
                if ([] !== $current) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = $line;
        }
        if ([] !== $current) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * @param list<string> $block
     */
    private static function blockToText(array $block): string
    {
        $lines = array_map(static fn (string $line): string => trim($line), $block);

        return implode("\n", array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    /**
     * @param list<string> $block
     */
    private static function blockToTable(array $block): ?string
    {
        if (count($block) < self::MIN_ROWS) {
            return null;
        }

        $width = 0;
        foreach ($block as $line) {
            $width = max($width, mb_strlen($line));
        }
        if (0 === $width) {
            return null;
        }

        $bounds = self::columnBounds($block, $width);
        if (count($bounds) - 1 < self::MIN_COLUMNS) {
            return null;
        }

        $rows = self::splitRows($block, $bounds, $width);
        if (count($rows) < self::MIN_ROWS) {
            return null;
        }

        // Zeilen mit nur einer gefuellten Zelle sind Fliesstext, der zufaellig eingerueckt ist.
        $filled = 0;
        foreach ($rows as $row) {
            if (count(array_filter($row, static fn (string $cell): bool => '' !== $cell)) >= 2) {
                ++$filled;
            }
        }
        if ($filled / count($rows) < self::MIN_FILLED_RATIO) {
            return null;
        }

        return self::renderTable($rows);
    }

    /**
     * Spaltengrenzen: Positionen, an denen in allen Zeilen des Blocks Leerzeichen stehen.
     *
     * @param list<string> $block
     * @return list<int>
     */
    private static function columnBounds(array $block, int $width): array
    {
        $blank = array_fill(0, $width, true);
        foreach ($block as $line) {
            $padded = self::pad($line, $width);
            for ($i = 0; $i < $width; ++$i) {
                if (' ' !== mb_substr($padded, $i, 1)) {
                    $blank[$i] = false;
                }
            }
        }

        $bounds = [0];
        $run = 0;
        for ($i = 0; $i < $width; ++$i) {
            if ($blank[$i]) {
                ++$run;
                continue;
            }
            if ($run >= self::MIN_GAP && $i - $run > 0) {
                $bounds[] = $i - intdiv($run, 2);
            }
            $run = 0;
        }
        $bounds[] = $width;

        return $bounds;
    }

    /**
     * @param list<string> $block
     * @param list<int> $bounds
     * @return list<list<string>>
     */
    private static function splitRows(array $block, array $bounds, int $width): array
    {
        $rows = [];
        foreach ($block as $line) {
            $padded = self::pad($line, $width);
            $cells = [];
            for ($i = 0, $n = count($bounds) - 1; $i < $n; ++$i) {
                $cells[] = trim(mb_substr($padded, $bounds[$i], $bounds[$i + 1] - $bounds[$i]));
            }
            if ([] !== array_filter($cells, static fn (string $cell): bool => '' !== $cell)) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Die erste Zeile gilt nur dann als Kopf, wenn sie genauso viele Zellen fuellt wie der
     * Rest. Sonst bleibt der Kopf leer und alle Zeilen sind Daten.
     *
     * @param list<list<string>> $rows
     */
    private static function renderTable(array $rows): string
    {
        $columns = count($rows[0]);
        $countFilled = static fn (array $row): int => count(array_filter($row, static fn (string $cell): bool => '' !== $cell));

        $bodyCounts = [];
        foreach (array_slice($rows, 1) as $row) {
            $bodyCounts[] = $countFilled($row);
        }
        $hasHeader = [] !== $bodyCounts && in_array($countFilled($rows[0]), $bodyCounts, true);

        $header = $hasHeader ? array_shift($rows) : array_fill(0, $columns, '');

        $lines = [self::renderRow($header), '|' . str_repeat(' --- |', $columns)];
        foreach ($rows as $row) {
            $lines[] = self::renderRow($row);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     */
    private static function renderRow(array $cells): string
    {
        $escaped = array_map(static fn (string $cell): string => str_replace('|', '\\|', $cell), $cells);

        return '| ' . implode(' | ', $escaped) . ' |';
    }

    private static function pad(string $line, int $width): string
    {
        $length = mb_strlen($line);

        return $length >= $width ? $line : $line . str_repeat(' ', $width - $length);
    }
}
