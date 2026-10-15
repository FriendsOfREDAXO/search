<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract\Extractor;

use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Extract\LayoutTableParser;
use FriendsOfRedaxo\Search\Extract\Process;
use rex_i18n;

/**
 * PDF ueber das Systemwerkzeug pdftotext aus den Poppler-Utilities.
 *
 * Der bevorzugte Weg, sofern vorhanden: gemessen an einem Haushaltsplan mit 386 Seiten
 * rund 0,8 Sekunden gegenueber gut fuenfzehn Sekunden mit der PHP-Bibliothek. Entscheidend
 * ist aber nicht die Geschwindigkeit, sondern zweierlei: nur mit dem Schalter -layout
 * bleiben Spaltenpositionen erhalten, und nur daraus lassen sich Tabellen zurueckgewinnen.
 * Und poppler oeffnet kopiergeschuetzte PDFs mit leerem Benutzerpasswort, waehrend die
 * PHP-Bibliothek sie mit "Secured pdf file are currently not supported" ablehnt.
 *
 * Auf vielen Hostings fehlt das Werkzeug oder proc_open ist gesperrt; dann uebernimmt
 * {@see PdfParserExtractor}.
 */
final class PdftotextExtractor extends DocumentExtractor
{
    private const BINARY = 'pdftotext';

    /**
     * Abbruch nach dieser Zeit. Ein Haushaltsplan mit 386 Seiten braucht eine Sekunde;
     * was nach zwei Minuten noch laeuft, laeuft nicht mehr zu Ende.
     */
    private const TIMEOUT = 120;

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

    /**
     * 2 seit dem Aufloesen der Silbentrennung: alte Ergebnisse im Zwischenspeicher
     * enthalten noch die Bruchstuecke und muessen neu gelesen werden.
     */
    public function getVersion(): int
    {
        return 2;
    }

    public function isAvailable(): bool
    {
        return '' !== Process::find(self::BINARY);
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        $binary = Process::find(self::BINARY);
        if ('' === $binary) {
            return null;
        }

        // "-" als Ziel schreibt auf die Standardausgabe, es braucht also keine temporaere
        // Datei und kein Aufraeumen danach.
        $text = Process::run([$binary, '-q', '-layout', '-nopgbrk', '-enc', 'UTF-8', '-eol', 'unix', $path, '-'], self::TIMEOUT);
        if (null === $text || '' === trim($text)) {
            return null;
        }

        $markdown = self::dehyphenate(LayoutTableParser::toMarkdown($text));
        [$markdown, $truncated] = $context->truncate($markdown);

        return new ExtractedDocument($markdown, null, ['extractor' => $this->getKey()], $truncated);
    }

    /**
     * Fuegt am Zeilenende getrennte Woerter wieder zusammen.
     *
     * Noetig, weil -layout die Silbentrennung stehen laesst. Am Haushaltsplan gemessen:
     * 770 Trennungen, und 724 davon ergeben ein Wort, das im Text sonst nirgends steht --
     * "Ergebnishaushalts" waere also unauffindbar, waehrend "Ergebnis" und "haushalts"
     * als Bruchstuecke in der Wortliste landen.
     *
     * Zusammengezogen wird ausschliesslich Kleinbuchstabe-Bindestrich-Umbruch-
     * Kleinbuchstabe. Nach Ziffer oder Grossbuchstabe bleibt der Bindestrich, damit aus
     * "2026-\n2027" oder "EU-\nRichtlinie" nichts Falsches wird.
     *
     * Erst nach {@see LayoutTableParser::toMarkdown()} angewandt, nicht davor: das
     * Zusammenziehen verschiebt alles Nachfolgende um eine Zeile nach oben und wuerde die
     * Spaltenerkennung zerstoeren. In einer fertigen Markdown-Tabellenzeile folgt auf
     * einen Bindestrich kein Umbruch mehr, dort kann die Ersetzung also nicht greifen.
     */
    private static function dehyphenate(string $text): string
    {
        return (string) preg_replace('/(\p{Ll})-[ \t]*\n[ \t]*(\p{Ll})/u', '$1$2', $text);
    }

    /**
     * Nur fuer Tests: die gemerkte Verfuegbarkeit vergessen.
     */
    public static function reset(): void
    {
        Process::reset();
    }
}
