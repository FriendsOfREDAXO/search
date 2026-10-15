<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract\Extractor;

use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Extract\LayoutTableParser;
use rex_dir;
use rex_file;
use rex_i18n;
use rex_path;

use function function_exists;
use function in_array;
use function ini_get;

/**
 * PDF ueber das Systemwerkzeug pdftotext aus den Poppler-Utilities.
 *
 * Der bevorzugte Weg, sofern vorhanden: gemessen an einem Haushaltsplan mit 386 Seiten
 * rund 0,8 Sekunden gegenueber gut fuenfzehn Sekunden mit der PHP-Bibliothek. Entscheidend
 * ist aber nicht die Geschwindigkeit, sondern der Schalter -layout: nur damit bleiben
 * Spaltenpositionen erhalten, und nur daraus lassen sich Tabellen zurueckgewinnen.
 *
 * Auf vielen Hostings fehlt das Werkzeug oder exec() ist gesperrt; dann uebernimmt
 * {@see PdfParserExtractor}.
 */
final class PdftotextExtractor extends DocumentExtractor
{
    private const BINARY = 'pdftotext';

    private static ?bool $available = null;

    public function getKey(): string
    {
        return 'pdftotext';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_extractor_pdftotext');
    }

    public function getExtensions(): array
    {
        return ['pdf'];
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function isAvailable(): bool
    {
        if (null !== self::$available) {
            return self::$available;
        }

        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return self::$available = false;
        }

        $output = [];
        $status = 1;
        @exec('command -v ' . escapeshellarg(self::BINARY) . ' 2>/dev/null', $output, $status);

        return self::$available = 0 === $status && [] !== $output;
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        $directory = rex_path::addonCache('search', 'tmp');
        rex_dir::create($directory);
        $target = @tempnam($directory, 'pdf');
        if (false === $target) {
            return null;
        }

        $status = 1;
        $output = [];
        @exec(
            self::BINARY . ' -layout -nopgbrk -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($target) . ' 2>/dev/null',
            $output,
            $status,
        );

        $text = 0 === $status ? rex_file::get($target) : null;
        @unlink($target);

        if (null === $text || '' === trim($text)) {
            return null;
        }

        $markdown = LayoutTableParser::toMarkdown($text);
        [$markdown, $truncated] = $context->truncate($markdown);

        return new ExtractedDocument($markdown, null, ['extractor' => $this->getKey()], $truncated);
    }

    /**
     * Nur fuer Tests: die gemerkte Verfuegbarkeit vergessen.
     */
    public static function reset(): void
    {
        self::$available = null;
    }
}
