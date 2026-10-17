<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Backend;

use FilesystemIterator;
use FriendsOfRedaxo\Search\Analysis\Analyzer;
use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use FriendsOfRedaxo\Search\Extract\DocumentExtractorRegistry;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Extract\Process;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Markdown;
use FriendsOfRedaxo\Search\Query\TermParser;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Vector\Embedder;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\QueryVectorCache;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use FriendsOfRedaxo\Search\Word\WordFormRepository;
use FriendsOfRedaxo\Search\Word\WordRepository;
use Loupe\Matcher\Tokenizer\Tokenizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use rex_i18n;
use rex_path;
use rex_sql;
use Smalot\PdfParser\Parser;
use SplFileInfo;
use Throwable;
use Wamania\Snowball\StemmerFactory;

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
 *
 * Alle Texte kommen unmaskiert heraus -- rawMsg statt msg. Maskiert wird in der Seite,
 * und zwar genau einmal: rex_i18n::msg() maskiert selbst, ein rex_escape() darueber
 * machte aus einem Anfuehrungszeichen ein sichtbares &quot;.
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
            rex_i18n::rawMsg('search_diagnosis_proc_open'),
            Process::isAvailable(),
            Process::isAvailable()
                ? rex_i18n::rawMsg('search_diagnosis_proc_open_ok')
                : rex_i18n::rawMsg('search_diagnosis_proc_open_missing'),
            self::WARNING,
        );

        $pdftotext = Process::find('pdftotext');
        $checks[] = self::check(
            'pdftotext',
            '' !== $pdftotext,
            '' !== $pdftotext
                ? $pdftotext . ' – ' . self::version($pdftotext)
                : rex_i18n::rawMsg('search_diagnosis_pdftotext_missing'),
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
                rex_i18n::rawMsg($message),
            );
        }

        $checks[] = self::check(
            'league/html-to-markdown',
            Markdown::isAvailable(),
            Markdown::isAvailable()
                ? rex_i18n::rawMsg('search_diagnosis_markdown_ok')
                : rex_i18n::rawMsg('search_diagnosis_markdown_missing'),
            self::WARNING,
        );

        $intl = extension_loaded('intl');
        $checks[] = self::check(
            'ext-intl',
            $intl,
            $intl ? rex_i18n::rawMsg('search_diagnosis_ext_intl') : rex_i18n::rawMsg('search_diagnosis_ext_intl_missing'),
            self::WARNING,
        );

        $stemmer = class_exists(StemmerFactory::class);
        $checks[] = self::check(
            'wamania/php-stemmer',
            $stemmer,
            $stemmer
                ? rex_i18n::rawMsg('search_diagnosis_stemmer_ok')
                : rex_i18n::rawMsg('search_diagnosis_stemmer_missing'),
            self::WARNING,
        );

        $matcher = class_exists(Tokenizer::class);
        $checks[] = self::check(
            'loupe/matcher',
            $matcher,
            $matcher
                ? rex_i18n::rawMsg('search_diagnosis_matcher_ok')
                : rex_i18n::rawMsg('search_diagnosis_matcher_missing'),
            self::WARNING,
        );

        $pdfParser = class_exists(Parser::class);
        $checks[] = self::check(
            'smalot/pdfparser',
            $pdfParser,
            $pdfParser
                ? rex_i18n::rawMsg('search_diagnosis_pdfparser_ok')
                : rex_i18n::rawMsg('search_diagnosis_pdfparser_missing'),
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
     * Welche Quelle in welcher Sprache steht und was davon analysiert werden kann.
     *
     * Der wichtigste Befund dieser Seite: eine Quelle ohne Sprachangabe wird ganz normal
     * durchsucht, aber nicht analysiert -- "Haeuser" findet dort kein "Haus". Das sieht man
     * der Suche nicht an, deshalb steht es hier.
     *
     * @return list<array{label: string, state: string, detail: string}>
     */
    public static function languages(): array
    {
        $checks = [];
        $withoutLanguage = 0;

        foreach ((new SourceRepository())->findAll() as $source) {
            $language = $source->getLanguage();
            if (Language::UNKNOWN === $language) {
                ++$withoutLanguage;
                $checks[] = self::check($source->name, false, rex_i18n::rawMsg('search_diagnosis_language_none'), self::WARNING);
                continue;
            }

            $checks[] = self::check(
                $source->name,
                true,
                Language::label($language) . ' – ' . rex_i18n::rawMsg(
                    Analyzer::canDecompound($language)
                        ? 'search_diagnosis_language_full'
                        : 'search_diagnosis_language_stem_only',
                ),
            );
        }

        if ([] === $checks) {
            $checks[] = self::check(rex_i18n::rawMsg('search_diagnosis_language_sources'), false, rex_i18n::rawMsg('search_diagnosis_language_no_sources'), self::WARNING);
        } elseif (0 === $withoutLanguage) {
            $checks[] = self::check(rex_i18n::rawMsg('search_diagnosis_language_forms'), true, rex_i18n::rawMsg('search_diagnosis_language_forms_detail', (new WordFormRepository())->countAll()));
        } else {
            $checks[] = self::check(rex_i18n::rawMsg('search_diagnosis_language_forms'), false, rex_i18n::rawMsg('search_diagnosis_language_forms_partial', (new WordFormRepository())->countAll(), $withoutLanguage), self::WARNING);
        }

        return $checks;
    }

    /**
     * Ob die semantische Suche moeglich ist und was dafuer schon bereitliegt.
     *
     * @return list<array{label: string, state: string, detail: string}>
     */
    public static function vectors(): array
    {
        $version = VectorSupport::getVersion();
        $available = VectorSupport::isAvailable();

        $checks = [self::check(
            rex_i18n::rawMsg('search_diagnosis_vector_support'),
            $available,
            $available
                ? rex_i18n::rawMsg('search_diagnosis_vector_ok', $version)
                : rex_i18n::rawMsg('search_diagnosis_vector_missing', $version, VectorSupport::MIN_MARIADB),
            self::WARNING,
        )];

        if (!$available) {
            return $checks;
        }

        $configured = EmbeddingClient::isConfigured();
        $checks[] = self::check(
            rex_i18n::rawMsg('search_diagnosis_embedding_profile'),
            $configured,
            $configured
                ? EmbeddingClient::getProfiles()[EmbeddingClient::getProfileId()] ?? ''
                : rex_i18n::rawMsg(
                    EmbeddingClient::isAvailable()
                        ? 'search_settings_embedding_missing'
                        : 'search_diagnosis_embedding_addon_missing',
                ),
            self::WARNING,
        );

        $chunks = (new ChunkRepository())->countAll();
        $sources = 0;
        foreach ((new SourceRepository())->findAll() as $source) {
            if ($source->createsChunks()) {
                ++$sources;
            }
        }

        $checks[] = self::check(
            rex_i18n::rawMsg('search_diagnosis_chunks'),
            $chunks > 0,
            $chunks > 0
                ? rex_i18n::rawMsg('search_diagnosis_chunks_detail', $chunks, $sources)
                : rex_i18n::rawMsg('search_diagnosis_chunks_none'),
            self::WARNING,
        );

        $store = new VectorStore();
        $vectors = $store->countAll();
        $open = (new Embedder())->countOpen();
        $checks[] = self::check(
            rex_i18n::rawMsg('search_diagnosis_vectors'),
            0 === $open && 0 < $vectors,
            0 === $vectors
                ? rex_i18n::rawMsg('search_diagnosis_vectors_none')
                : rex_i18n::rawMsg(
                    'search_diagnosis_vectors_detail',
                    $vectors,
                    (int) $store->getDimensions(),
                    self::formatBytes($store->getBytes()),
                    $open,
                ),
            self::WARNING,
        );

        // Steht hier nicht nur zur Information: ein Zwischenspeicher, der dauerhaft
        // leer bleibt, ist ein Hinweis auf einen Fehler, den niemand sieht -- er
        // schluckt seine Ausnahmen, damit er keine Suche stoert.
        $cached = (new QueryVectorCache())->countAll();
        $checks[] = self::check(
            rex_i18n::rawMsg('search_diagnosis_query_cache'),
            true,
            rex_i18n::rawMsg('search_diagnosis_query_cache_detail', $cached, QueryVectorCache::MAX_ENTRIES),
        );

        return $checks;
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
            rex_i18n::rawMsg('search_diagnosis_min_token_size', TermParser::getMinTokenSize()),
        );

        $stopwords = TermParser::getStopwords();
        $checks[] = self::check(
            rex_i18n::rawMsg('search_diagnosis_stopwords'),
            true,
            [] === $stopwords
                ? rex_i18n::rawMsg('search_diagnosis_stopwords_off')
                : rex_i18n::rawMsg('search_diagnosis_stopwords_on', count($stopwords), implode(', ', $stopwords)),
        );

        foreach (['fulltext_content', 'fulltext_title'] as $index) {
            $exists = self::hasIndex(IndexRepository::table(), $index);
            $checks[] = self::check(
                $index,
                $exists,
                $exists ? rex_i18n::rawMsg('search_diagnosis_index_ok') : rex_i18n::rawMsg('search_diagnosis_index_missing'),
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
            self::check(rex_i18n::rawMsg('search_diagnosis_documents'), $documents > 0, (string) $documents),
            self::check(
                rex_i18n::rawMsg('search_diagnosis_words'),
                $words > 0,
                $words > 0
                    ? rex_i18n::rawMsg('search_diagnosis_words_detail', $words)
                    : rex_i18n::rawMsg('search_diagnosis_words_missing'),
                self::WARNING,
            ),
            self::check(
                rex_i18n::rawMsg('search_diagnosis_cache'),
                true,
                rex_i18n::rawMsg('search_diagnosis_cache_detail', $files, self::formatBytes($bytes)),
            ),
            self::check(
                rex_i18n::rawMsg('search_diagnosis_max_characters'),
                true,
                rex_i18n::rawMsg('search_diagnosis_max_characters_detail', number_format(self::maxCharacters(), 0, ',', '.')),
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
            return rex_i18n::rawMsg('search_diagnosis_version_unknown');
        }

        $firstLine = strtok(trim($output), "\n");

        return false === $firstLine ? rex_i18n::rawMsg('search_diagnosis_version_unknown') : trim($firstLine);
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
