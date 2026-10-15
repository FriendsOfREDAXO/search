<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Backend;

use FilesystemIterator;
use FriendsOfRedaxo\Search\Extract\DocumentExtractorRegistry;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Extract\Process;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Markdown;
use FriendsOfRedaxo\Search\Query\TermParser;
use FriendsOfRedaxo\Search\Word\WordRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use rex_i18n;
use rex_path;
use rex_sql;
use Smalot\PdfParser\Parser;
use SplFileInfo;
use Throwable;

use function class_exists;
use function count;
use function extension_loaded;

/**
 * Sagt, was auf diesem Server vorhanden ist und was deshalb geht.
 *
 * Das AddOn arbeitet an mehreren Stellen mit dem, was da ist: pdftotext wenn vorhanden,
 * sonst die PHP-Bibliothek; die Bibliothek wenn vendor/ mitgeliefert wurde, sonst der
 * alte Weg. Jede dieser Weichen faellt lautlos, und von aussen ist dann nicht erkennbar,
 * warum ein PDF leer bleibt oder warum ein Artikel kein Markdown liefert. Diese Klasse
 * macht die Weichen sichtbar.
 */
final class Diagnosis
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const WARNING = 'warning';

    /**
     * Ein Befund: Bezeichnung, Zustand, Erlaeuterung.
     *
     * @return list<array{label: string, state: string, detail: string}>
     */
    public static function tools(): array
    {
        $checks = [];

        $checks[] = self::check(
            rex_i18n::msg('search_diagnosis_proc_open'),
            Process::isAvailable(),
            Process::isAvailable()
                ? rex_i18n::msg('search_diagnosis_proc_open_ok')
                : rex_i18n::msg('search_diagnosis_proc_open_missing'),
            self::WARNING,
        );

        $pdftotext = Process::find('pdftotext');
        $checks[] = self::check(
            'pdftotext',
            '' !== $pdftotext,
            '' !== $pdftotext
                ? $pdftotext . ' – ' . self::version($pdftotext)
                : rex_i18n::msg('search_diagnosis_pdftotext_missing'),
            self::WARNING,
        );

        return $checks;
    }

    /**
     * @return list<array{label: string, state: string, detail: string}>
     */
    public static function php(): array
    {
        $checks = [];
        foreach ([
            'zip' => 'search_diagnosis_ext_zip',
            'dom' => 'search_diagnosis_ext_dom',
            'mbstring' => 'search_diagnosis_ext_mbstring',
        ] as $extension => $message) {
            $checks[] = self::check(
                'ext-' . $extension,
                extension_loaded($extension),
                rex_i18n::msg($message),
            );
        }

        $checks[] = self::check(
            'league/html-to-markdown',
            Markdown::isAvailable(),
            Markdown::isAvailable()
                ? rex_i18n::msg('search_diagnosis_markdown_ok')
                : rex_i18n::msg('search_diagnosis_markdown_missing'),
            self::WARNING,
        );

        $pdfParser = class_exists(Parser::class);
        $checks[] = self::check(
            'smalot/pdfparser',
            $pdfParser,
            $pdfParser
                ? rex_i18n::msg('search_diagnosis_pdfparser_ok')
                : rex_i18n::msg('search_diagnosis_pdfparser_missing'),
            self::WARNING,
        );

        return $checks;
    }

    /**
     * Die Extraktoren in der Reihenfolge, in der die Registry sie fragt.
     *
     * @return list<array{key: string, label: string, extensions: string, available: bool, priority: int}>
     */
    public static function extractors(): array
    {
        $rows = [];
        foreach (DocumentExtractorRegistry::registered() as $extractor) {
            $rows[] = [
                'key' => $extractor->getKey(),
                'label' => $extractor->getLabel(),
                'extensions' => implode(', ', $extractor->getExtensions()),
                'available' => $extractor->isAvailable(),
                'priority' => $extractor->getPriority(),
            ];
        }

        return $rows;
    }

    /**
     * Was die Datenbank beim Volltext tut -- die Werte, an denen sich die Suche
     * ausrichtet und die sie nicht selbst bestimmen kann.
     *
     * @return list<array{label: string, state: string, detail: string}>
     */
    public static function database(): array
    {
        $checks = [];

        $checks[] = self::check(
            'innodb_ft_min_token_size',
            true,
            rex_i18n::msg('search_diagnosis_min_token_size', TermParser::getMinTokenSize()),
        );

        $stopwords = TermParser::getStopwords();
        $checks[] = self::check(
            rex_i18n::msg('search_diagnosis_stopwords'),
            true,
            [] === $stopwords
                ? rex_i18n::msg('search_diagnosis_stopwords_off')
                : rex_i18n::msg('search_diagnosis_stopwords_on', count($stopwords), implode(', ', $stopwords)),
        );

        foreach (['fulltext_content', 'fulltext_title'] as $index) {
            $exists = self::hasIndex(IndexRepository::table(), $index);
            $checks[] = self::check(
                $index,
                $exists,
                $exists ? rex_i18n::msg('search_diagnosis_index_ok') : rex_i18n::msg('search_diagnosis_index_missing'),
            );
        }

        return $checks;
    }

    /**
     * Was im Index und daneben liegt.
     *
     * @return list<array{label: string, state: string, detail: string}>
     */
    public static function storage(): array
    {
        $documents = (new IndexRepository())->countAll();
        $words = (new WordRepository())->countAll();
        [$files, $bytes] = self::cacheSize();

        return [
            self::check(rex_i18n::msg('search_diagnosis_documents'), $documents > 0, (string) $documents),
            self::check(
                rex_i18n::msg('search_diagnosis_words'),
                $words > 0,
                $words > 0
                    ? rex_i18n::msg('search_diagnosis_words_detail', $words)
                    : rex_i18n::msg('search_diagnosis_words_missing'),
                self::WARNING,
            ),
            self::check(
                rex_i18n::msg('search_diagnosis_cache'),
                true,
                rex_i18n::msg('search_diagnosis_cache_detail', $files, self::formatBytes($bytes)),
            ),
            self::check(
                rex_i18n::msg('search_diagnosis_max_characters'),
                true,
                rex_i18n::msg('search_diagnosis_max_characters_detail', number_format(self::maxCharacters(), 0, ',', '.')),
            ),
        ];
    }

    public static function cachePath(): string
    {
        return rex_path::addonData('search', 'documents');
    }

    /**
     * Dateien und Bytes im Zwischenspeicher der Extraktion.
     *
     * @param string|null $path Nur fuer Tests, damit nicht der echte Zwischenspeicher
     *                          die Aussage des Tests bestimmt
     * @return array{int, int}
     */
    public static function cacheSize(?string $path = null): array
    {
        $path ??= self::cachePath();
        if (!is_dir($path)) {
            return [0, 0];
        }

        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                ++$files;
                $bytes += $file->getSize();
            }
        }

        return [$files, $bytes];
    }

    public static function maxCharacters(): int
    {
        return ExtractionContext::DEFAULT_MAX_CHARACTERS;
    }

    /**
     * @return array{label: string, state: string, detail: string}
     */
    private static function check(string $label, bool $ok, string $detail, string $failState = self::MISSING): array
    {
        return ['label' => $label, 'state' => $ok ? self::OK : $failState, 'detail' => $detail];
    }

    /**
     * Die Versionszeile eines Programms. pdftotext schreibt sie auf die Fehlerausgabe.
     */
    private static function version(string $binary): string
    {
        $output = Process::run([$binary, '-v'], 5, true);
        if (null === $output || '' === trim($output)) {
            return rex_i18n::msg('search_diagnosis_version_unknown');
        }

        $firstLine = strtok(trim($output), "\n");

        return false === $firstLine ? rex_i18n::msg('search_diagnosis_version_unknown') : trim($firstLine);
    }

    private static function hasIndex(string $table, string $name): bool
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1',
                ['t' => $table, 'i' => $name],
            );
        } catch (Throwable) {
            return false;
        }

        return [] !== $rows;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
