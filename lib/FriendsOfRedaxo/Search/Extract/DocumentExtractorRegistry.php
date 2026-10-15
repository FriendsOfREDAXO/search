<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

use FriendsOfRedaxo\Search\Extract\Extractor\HtmlExtractor;
use FriendsOfRedaxo\Search\Extract\Extractor\OfficeExtractor;
use FriendsOfRedaxo\Search\Extract\Extractor\PdfParserExtractor;
use FriendsOfRedaxo\Search\Extract\Extractor\PdftotextExtractor;
use FriendsOfRedaxo\Search\Extract\Extractor\TextExtractor;
use rex_exception;
use rex_extension;
use rex_extension_point;

/**
 * Kennt alle Extraktoren. Eingebaute werden hier angelegt, fremde AddOns ergaenzen ihre
 * ueber den Extension Point SEARCH_DOCUMENT_EXTRACTORS:
 *
 *     rex_extension::register('SEARCH_DOCUMENT_EXTRACTORS', static function (rex_extension_point $ep) {
 *         $extractors = $ep->getSubject();
 *         $extractors[] = new MyOcrExtractor();
 *         return $extractors;
 *     });
 *
 * Extraktoren, deren isAvailable() false liefert, werden uebergangen. Bei gleicher Endung
 * gewinnt die hoehere Prioritaet.
 */
final class DocumentExtractorRegistry
{
    /** @var list<DocumentExtractor>|null */
    private static ?array $extractors = null;

    /**
     * Alle verfuegbaren Extraktoren, nach Prioritaet absteigend.
     *
     * @return list<DocumentExtractor>
     */
    public static function all(): array
    {
        if (null !== self::$extractors) {
            return self::$extractors;
        }

        $extractors = [
            new TextExtractor(),
            new HtmlExtractor(),
            new OfficeExtractor(),
            new PdftotextExtractor(),
            new PdfParserExtractor(),
        ];
        $extractors = rex_extension::registerPoint(new rex_extension_point('SEARCH_DOCUMENT_EXTRACTORS', $extractors));

        $available = [];
        foreach ($extractors as $extractor) {
            if (!$extractor instanceof DocumentExtractor) {
                throw new rex_exception('SEARCH_DOCUMENT_EXTRACTORS expects instances of ' . DocumentExtractor::class . ', got ' . get_debug_type($extractor));
            }
            if (!$extractor->isAvailable()) {
                continue;
            }
            $available[] = $extractor;
        }

        // Stabile Reihenfolge: bei gleicher Prioritaet bleibt die Anmeldereihenfolge.
        usort($available, static fn (DocumentExtractor $a, DocumentExtractor $b): int => $b->getPriority() <=> $a->getPriority());

        return self::$extractors = array_values($available);
    }

    /**
     * Der zustaendige Extraktor fuer eine Endung, oder null.
     */
    public static function forFile(string $extension, string $mimeType = ''): ?DocumentExtractor
    {
        foreach (self::all() as $extractor) {
            if ($extractor->supports($extension, $mimeType)) {
                return $extractor;
            }
        }

        return null;
    }

    /**
     * Alle Endungen, die derzeit bedient werden, sortiert. Grundlage fuer die Auswahl
     * im Quellenformular.
     *
     * @return list<string>
     */
    public static function supportedExtensions(): array
    {
        $extensions = [];
        foreach (self::all() as $extractor) {
            foreach ($extractor->getExtensions() as $extension) {
                $extensions[mb_strtolower($extension)] = true;
            }
        }

        $list = array_keys($extensions);
        sort($list);

        return $list;
    }

    public static function reset(): void
    {
        self::$extractors = null;
    }
}
