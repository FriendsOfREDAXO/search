<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract\Extractor;

use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use rex_i18n;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDF in reinem PHP, ueber smalot/pdfparser.
 *
 * Der Rueckfall fuer alle Installationen ohne pdftotext. Zwei Einschraenkungen, die man
 * kennen sollte: die Extraktion ist deutlich langsamer, und die Bibliothek liefert Text in
 * Lesereihenfolge mit uneinheitlichen Trennzeichen. Spaltenpositionen gibt es nicht, also
 * auch keine Tabellen. Fuer den Volltext reicht das, fuer eine strukturtreue Weiter-
 * verarbeitung ist pdftotext der bessere Weg.
 *
 * Die Bibliothek steht unter LGPL-3.0 und liegt unveraendert im vendor-Verzeichnis.
 */
final class PdfParserExtractor extends DocumentExtractor
{
    public function getKey(): string
    {
        return 'pdfparser';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_extractor_pdfparser');
    }

    public function getExtensions(): array
    {
        return ['pdf'];
    }

    public function isAvailable(): bool
    {
        return class_exists(Parser::class);
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        try {
            $config = new Config();
            // Ohne Begrenzung laeuft ein einzelnes grosses PDF in das Speicherlimit.
            $config->setRetainImageContent(false);

            $parser = new Parser([], $config);
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
            $details = $pdf->getDetails();
        } catch (Throwable) {
            return null;
        }

        if ('' === trim($text)) {
            return null;
        }

        $markdown = $this->normalize($text);
        [$markdown, $truncated] = $context->truncate($markdown);

        $meta = ['extractor' => $this->getKey()];
        if (isset($details['Pages'])) {
            $meta['pages'] = (int) $details['Pages'];
        }

        return new ExtractedDocument($markdown, $this->readTitle($details), $meta, $truncated);
    }

    private function normalize(string $text): string
    {
        // Die Bibliothek trennt Zellen mal mit Tabulator, mal mit Leerzeichen; fuer den
        // Klartext ist beides ein Trenner.
        $text = str_replace("\t", ' ', $text);
        $text = (string) preg_replace('/\R/u', "\n", $text);
        $text = (string) preg_replace('/[ \x{00A0}]{2,}/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function readTitle(array $details): ?string
    {
        $title = trim((string) ($details['Title'] ?? ''));

        return '' === $title ? null : $title;
    }
}
