<?php

use FriendsOfRedaxo\Search\Chunk\ChunkBuilder;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Index\Indexer;
use FriendsOfRedaxo\Search\Index\IndexFilter;
use FriendsOfRedaxo\Search\Index\IndexFilterRegistry;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Index\IndexResult;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Source\SourceType;
use FriendsOfRedaxo\Search\Source\SourceTypeRegistry;
use FriendsOfRedaxo\Search\Word\WordCollector;
use FriendsOfRedaxo\Search\Word\WordFormRepository;
use FriendsOfRedaxo\Search\Word\WordRepository;
use PHPUnit\Framework\TestCase;

/** Doppel: merkt sich die geschriebenen Dokumente statt sie in die Datenbank zu legen. */
final class rex_search_index_repository_spy extends IndexRepository
{
    /** @var list<string> */
    public array $stored = [];
    /** @var list<string> */
    public array $deletedItems = [];

    public function store(Source $source, string $namespace, string $type, IndexDocument $document, DateTimeImmutable $indexedAt): bool
    {
        $this->stored[] = $document->indexId;

        return true;
    }

    public function deleteByItem(int $sourceId, string $itemId, array $keepIndexIds = []): int
    {
        $this->deletedItems[] = $itemId;

        return 0;
    }

    public function deleteStale(int $sourceId, DateTimeImmutable $runStartedAt): int
    {
        return 0;
    }
}

/** Doppel: schreibt keine Statistik. */
final class rex_search_source_repository_spy extends SourceRepository
{
    public function updateStats(int $id, int $itemCount, DateTimeImmutable $indexedAt): void {}
}

/**
 * Doppel: fasst die Wortliste nicht an.
 *
 * Noetig, weil der Indexer sonst die echte nimmt. Dieser Test laeuft gegen die
 * Datenbank der Installation, auf der er ausgefuehrt wird, und seine Testquelle traegt
 * die Id 1 -- auf einer echten Installation gehoert die einer echten Quelle. Ohne diese
 * Doppel loescht ein Testlauf deren Woerter, Abschnitte und Vektoren. Genau das ist
 * passiert: die Abschnitte der Quelle 1 verschwanden nach jedem Suitelauf, und die
 * Ursache sah lange nach einem Fehler im Aufbau aus.
 */
final class rex_search_word_repository_spy extends WordRepository
{
    public function replaceSource(WordCollector $collector): int
    {
        return 0;
    }

    public function deleteSource(int $sourceId): void {}
}

/** Doppel: fasst die Nachschlageformen nicht an. */
final class rex_search_word_form_repository_spy extends WordFormRepository
{
    public function replaceSource(WordCollector $collector): int
    {
        return 0;
    }

    public function deleteSource(int $sourceId): void {}
}

/** Doppel: fasst Abschnitte und Vektoren nicht an. */
final class rex_search_chunk_builder_spy extends ChunkBuilder
{
    /** @var list<string> */
    public array $rebuiltItems = [];

    public function rebuildSource(Source $source): array
    {
        return ['chunks' => 0, 'kept' => 0, 'written' => 0, 'deleted' => 0];
    }

    public function rebuildItem(Source $source, string $itemId): array
    {
        $this->rebuiltItems[] = $itemId;

        return ['chunks' => 0, 'kept' => 0, 'written' => 0, 'deleted' => 0];
    }
}

/** Quellentyp mit festen Datensaetzen 1 bis 6. */
final class rex_search_demo_source_type extends SourceType
{
    public function getKey(): string
    {
        return 'demo';
    }

    public function getLabel(): string
    {
        return 'Demo';
    }

    protected function getOwnConfigFields(array $config): array
    {
        return [];
    }

    public function getType(Source $source): string
    {
        return 'demo';
    }

    public function getIndexIdPattern(Source $source): string
    {
        return '{id}';
    }

    public function countItems(Source $source): ?int
    {
        return 6;
    }

    public function getItemIds(Source $source, int $offset, int $limit): array
    {
        return array_slice(['1', '2', '3', '4', '5', '6'], $offset, $limit);
    }

    public function createDocuments(Source $source, string $itemId): iterable
    {
        return [new IndexDocument($itemId, $itemId, 'Titel ' . $itemId, 'Inhalt')];
    }
}

/** @internal */
final class rex_search_index_filter_test extends TestCase
{
    /** @var array<string, list<callable>> */
    private array $registered = [];

    protected function setUp(): void
    {
        IndexFilterRegistry::reset();
        SourceTypeRegistry::reset();

        // Der Indexer laesst sich den Quellentyp von der Registry geben, also muss der
        // Demo-Typ dort angemeldet sein.
        $extension = static function (rex_extension_point $ep) {
            $types = $ep->getSubject();
            $types[] = new rex_search_demo_source_type();

            return $types;
        };
        rex_extension::register('SEARCH_SOURCE_TYPES', $extension);
        $this->registered['SEARCH_SOURCE_TYPES'][] = $extension;
    }

    protected function tearDown(): void
    {
        foreach ($this->registered as $point => $extensions) {
            foreach ($extensions as $extension) {
                $this->unregister($point, $extension);
            }
        }
        $this->registered = [];
        IndexFilterRegistry::reset();
        SourceTypeRegistry::reset();
    }

    public function testAvailableHonoursAppliesTo(): void
    {
        $this->register($this->filter('nur_demo', static fn (string $id): bool => true, 'demo'));
        $this->register($this->filter('fuer_alle', static fn (string $id): bool => true));

        self::assertSame(['nur_demo', 'fuer_alle'], array_keys(IndexFilterRegistry::available($this->source())));
        self::assertSame(['fuer_alle'], array_keys(IndexFilterRegistry::available(new Source(1, 'x', 'andere'))));
    }

    public function testForSourceReturnsOnlySelectedFiltersAndIgnoresUnknownKeys(): void
    {
        $this->register($this->filter('a', static fn (string $id): bool => true));
        $this->register($this->filter('b', static fn (string $id): bool => true));

        $source = $this->source(['b' => [], 'gibt_es_nicht' => []]);

        self::assertSame(['b'], array_keys(IndexFilterRegistry::forSource($source)));
    }

    public function testWithoutFiltersEverythingIsIndexed(): void
    {
        [$result, $index] = $this->rebuild($this->source());

        self::assertSame(6, $result->items);
        self::assertSame(0, $result->skipped);
        self::assertSame(['1', '2', '3', '4', '5', '6'], $index->stored);
    }

    public function testFilterRejectsItemsAndCountsThem(): void
    {
        $this->register($this->filter('nur_gerade', static fn (string $id): bool => 0 === (int) $id % 2));

        [$result, $index] = $this->rebuild($this->source(['nur_gerade' => []]));

        self::assertSame(3, $result->items);
        self::assertSame(3, $result->skipped);
        self::assertSame(['2', '4', '6'], $index->stored);
    }

    public function testAllSelectedFiltersMustAgree(): void
    {
        $this->register($this->filter('nur_gerade', static fn (string $id): bool => 0 === (int) $id % 2));
        $this->register($this->filter('kleiner_fuenf', static fn (string $id): bool => (int) $id < 5));

        [$result, $index] = $this->rebuild($this->source(['nur_gerade' => [], 'kleiner_fuenf' => []]));

        self::assertSame(['2', '4'], $index->stored);
        self::assertSame(4, $result->skipped);
    }

    public function testFilterConfigReachesTheFilter(): void
    {
        $this->register($this->filter('ab_nummer', static fn (string $id): bool => true));

        [$result, $index] = $this->rebuild($this->source(['ab_nummer' => ['ab' => '4']]));

        self::assertSame(['4', '5', '6'], $index->stored);
        self::assertSame(3, $result->skipped);
    }

    public function testFilterNotSelectedHasNoEffect(): void
    {
        $this->register($this->filter('blockiert_alles', static fn (string $id): bool => false));

        [$result] = $this->rebuild($this->source());

        self::assertSame(6, $result->items);
    }

    public function testReindexItemRemovesDocumentsOfRejectedItem(): void
    {
        $this->register($this->filter('nur_gerade', static fn (string $id): bool => 0 === (int) $id % 2));

        $index = new rex_search_index_repository_spy();
        $indexer = self::indexer($index);

        self::assertSame(0, $indexer->reindexItem($this->source(['nur_gerade' => []]), '3'));
        self::assertSame(['3'], $index->deletedItems);
        self::assertSame([], $index->stored);
    }

    /**
     * Ohne die Abschnitte fuehrte die Suche nach Bedeutung weiter auf den alten Text, oder
     * auf ein Dokument, das es nicht mehr gibt -- in beiden Faellen, ob der Datensatz
     * geschrieben oder abgelehnt wurde.
     */
    public function testReindexItemAlsoRebuildsTheChunksOfTheItem(): void
    {
        $this->register($this->filter('nur_gerade', static fn (string $id): bool => 0 === (int) $id % 2));

        $chunks = new rex_search_chunk_builder_spy();
        $indexer = new Indexer(
            new rex_search_index_repository_spy(),
            new rex_search_source_repository_spy(),
            new rex_search_word_repository_spy(),
            new rex_search_word_form_repository_spy(),
            $chunks,
        );

        $indexer->reindexItem($this->source(['nur_gerade' => []]), '4');
        $indexer->reindexItem($this->source(['nur_gerade' => []]), '3');

        self::assertSame(['4', '3'], $chunks->rebuiltItems);
    }

    /**
     * @return array{IndexResult, rex_search_index_repository_spy}
     */
    private function rebuild(Source $source): array
    {
        $index = new rex_search_index_repository_spy();
        $result = self::indexer($index)->rebuild($source);

        return [$result, $index];
    }

    /**
     * Ein Indexer, der nirgends hinschreibt.
     */
    private static function indexer(rex_search_index_repository_spy $index): Indexer
    {
        return new Indexer(
            $index,
            new rex_search_source_repository_spy(),
            new rex_search_word_repository_spy(),
            new rex_search_word_form_repository_spy(),
            new rex_search_chunk_builder_spy(),
        );
    }

    /** @param array<string, array<string, mixed>> $filters */
    private function source(array $filters = []): Source
    {
        return new Source(1, 'Testquelle', 'demo', [], $filters);
    }

    private function filter(string $key, callable $accepts, ?string $onlyTypeKey = null): IndexFilter
    {
        return new class($key, $accepts, $onlyTypeKey) extends IndexFilter {
            public function __construct(
                private string $key,
                private $accepts,
                private ?string $onlyTypeKey,
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return $this->key;
            }

            public function appliesTo(Source $source): bool
            {
                return null === $this->onlyTypeKey || $this->onlyTypeKey === $source->typeKey;
            }

            public function getConfigFields(array $config): array
            {
                return [new ConfigField('ab', 'Ab welcher Nummer')];
            }

            public function accepts(Source $source, string $itemId, array $config): bool
            {
                if ('' !== (string) ($config['ab'] ?? '')) {
                    return (int) $itemId >= (int) $config['ab'];
                }

                return ($this->accepts)($itemId);
            }
        };
    }

    private function register(IndexFilter $filter): void
    {
        $extension = static function (rex_extension_point $ep) use ($filter) {
            $filters = $ep->getSubject();
            $filters[] = $filter;

            return $filters;
        };
        rex_extension::register('SEARCH_INDEX_FILTERS', $extension);
        $this->registered['SEARCH_INDEX_FILTERS'][] = $extension;
        IndexFilterRegistry::reset();
    }

    private function unregister(string $point, callable $extension): void
    {
        $class = new ReflectionClass(rex_extension::class);
        /** @var array<string, array<int, list<array{callable, array}>>> $extensions */
        $extensions = $class->getStaticPropertyValue('extensions');
        foreach ($extensions[$point] ?? [] as $level => $registered) {
            foreach ($registered as $i => $entry) {
                if ($entry[0] === $extension) {
                    unset($extensions[$point][$level][$i]);
                }
            }
        }
        $class->setStaticPropertyValue('extensions', $extensions);
    }
}
