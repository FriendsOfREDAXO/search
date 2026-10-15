<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract\Extractor;

use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use rex_i18n;

use function array_slice;
use function count;
use function in_array;

/**
 * Klartext, Markdown und Trennzeichendateien.
 *
 * Markdown wird unveraendert uebernommen, Klartext ist schon Markdown genug. Aus CSV wird
 * eine Markdown-Tabelle, weil die Spaltenzuordnung sonst verloren geht.
 */
final class TextExtractor extends DocumentExtractor
{
    private const MAX_CSV_COLUMNS = 50;

    public function getKey(): string
    {
        return 'text';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_extractor_text');
    }

    public function getExtensions(): array
    {
        return ['txt', 'text', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml'];
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        $raw = $this->toUtf8($raw);
        $extension = $context->getExtension();

        $markdown = in_array($extension, ['csv', 'tsv'], true)
            ? $this->csvToMarkdown($raw, 'tsv' === $extension ? "\t" : $this->guessDelimiter($raw))
            : $this->normalize($raw);

        [$markdown, $truncated] = $context->truncate($markdown);

        return new ExtractedDocument($markdown, $this->readTitle($markdown), ['extractor' => $this->getKey()], $truncated);
    }

    private function normalize(string $text): string
    {
        $text = (string) preg_replace('/\R/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Erste Markdown-Ueberschrift als Titelvorschlag.
     */
    private function readTitle(string $markdown): ?string
    {
        if (preg_match('/^#{1,6}\s+(.+)$/m', $markdown, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function guessDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\n");
        if (false === $firstLine) {
            return ',';
        }

        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($firstLine, $candidate);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }

    private function csvToMarkdown(string $raw, string $delimiter): string
    {
        $handle = fopen('php://memory', 'r+');
        if (false === $handle) {
            return $this->normalize($raw);
        }
        fwrite($handle, $raw);
        rewind($handle);

        $rows = [];
        $columns = 0;
        while (false !== ($row = fgetcsv($handle, 0, $delimiter, '"', ''))) {
            if ([null] === $row) {
                continue;
            }
            $row = array_slice(array_map(static fn ($cell): string => trim((string) $cell), $row), 0, self::MAX_CSV_COLUMNS);
            if ([] === array_filter($row, static fn (string $cell): bool => '' !== $cell)) {
                continue;
            }
            $columns = max($columns, count($row));
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) < 2 || $columns < 2) {
            return $this->normalize($raw);
        }

        $render = static function (array $cells) use ($columns): string {
            $cells = array_pad($cells, $columns, '');
            $cells = array_map(static fn (string $cell): string => str_replace('|', '\\|', $cell), $cells);

            return '| ' . implode(' | ', $cells) . ' |';
        };

        // Die erste Zeile einer CSV ist praktisch immer die Kopfzeile.
        $lines = [$render(array_shift($rows)), '|' . str_repeat(' --- |', $columns)];
        foreach ($rows as $row) {
            $lines[] = $render($row);
        }

        return implode("\n", $lines);
    }

    /**
     * Dateien ohne Kodierungsangabe sind in der Praxis UTF-8 oder Windows-1252.
     */
    private function toUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

        return false === $converted ? $raw : $converted;
    }
}
