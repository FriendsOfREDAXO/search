<?php

use FriendsOfRedaxo\Search\Chunk\Chunk;
use FriendsOfRedaxo\Search\Chunk\ChunkBuilder;
use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategy;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use PHPUnit\Framework\TestCase;

/** Doppel: merkt sich die Abschnitte statt sie in die Datenbank zu legen. */
final class rex_search_chunk_repository_spy extends ChunkRepository
{
    /** @var array<int, list<Chunk>> */
    public array $synced = [];

    public array $deletedSources = [];

    public int $orphanRuns = 0;

    public function syncDocument(int $sourceId, int $documentId, array $chunks): array
    {
        $this->synced[$documentId] = $chunks;

        return ['kept' => 0, 'written' => count($chunks), 'deleted' => 0];
    }

    public function deleteBySource(int $sourceId): void
    {
        $this->deletedSources[] = $sourceId;
    }

    public function deleteOrphans(string $indexTable): int
    {
        ++$this->orphanRuns;

        return 0;
    }
}

/** Doppel: fasst die Vektortabelle nicht an. */
final class rex_search_vector_store_spy extends VectorStore
{
    public array $deletedSources = [];

    public function exists(): bool
    {
        return false;
    }

    public function deleteBySource(int $sourceId): void
    {
        $this->deletedSources[] = $sourceId;
    }

    public function deleteOrphans(): int
    {
        return 0;
    }
}

/** @internal */
final class rex_search_chunk_builder_test extends TestCase
{
    /**
     * @param list<array<string, mixed>> $documents
     */
    private function builder(array $documents, rex_search_chunk_repository_spy $chunks, rex_search_vector_store_spy $vectors): ChunkBuilder
    {
        return new class($documents, $chunks, $vectors) extends ChunkBuilder {
            /** @param list<array<string, mixed>> $documents */
            public function __construct(
                private array $documents,
                ChunkRepository $chunks,
                VectorStore $vectors,
            ) {
                parent::__construct($chunks, $vectors);
            }

            protected function readDocuments(int $sourceId, int $offset, int $limit): array
            {
                return array_slice($this->documents, $offset, $limit);
            }

            protected function readItemDocuments(int $sourceId, string $itemId): array
            {
                return array_values(array_filter($this->documents, static fn (array $row): bool => $itemId === ($row['item_id'] ?? null)));
            }
        };
    }

    private function source(bool $chunking, string $strategy = 'markdown'): Source
    {
        return new Source(7, 'Test', 'demo', [
            'create_chunks' => $chunking,
            'chunk_strategy' => $strategy,
        ]);
    }

    /**
     * Die Obergrenze gilt fuer alles, was in die Tabelle geht -- auch fuer ein Verfahren,
     * das sie nicht kennt. "Ganzes Dokument" darf sie nicht kennen, sonst waere es nicht
     * mehr das ganze Dokument, und ein Verfahren aus einem fremden AddOn kann sie nicht
     * kennen. Ein zu langer Abschnitt bekaeme nie einen Vektor: das Modell weist ihn ab.
     */
    public function testNoChunkLeavesTheBuilderOversized(): void
    {
        if (!VectorSupport::isAvailable()) {
            self::markTestSkipped('Ohne Vektoren legt die Quelle keine Abschnitte an.');
        }

        $chunks = new rex_search_chunk_repository_spy();
        $lang = trim(str_repeat('Ein Satz mit etwas Text darin. ', 2000));
        $builder = $this->builder(
            [['id' => 11, 'title' => 'Riesig', 'content' => $lang, 'content_raw' => null, 'content_raw_format' => null]],
            $chunks,
            new rex_search_vector_store_spy(),
        );

        $builder->rebuildSource($this->source(true, 'whole'));

        self::assertNotSame([], $chunks->synced[11]);
        foreach ($chunks->synced[11] as $chunk) {
            self::assertLessThanOrEqual(ChunkStrategy::MAX_CHUNK_CHARACTERS, mb_strlen($chunk->text));
        }
    }

    public function testTheConfiguredStrategyIsTheOneThatRuns(): void
    {
        if (!VectorSupport::isAvailable()) {
            self::markTestSkipped('Ohne Vektoren legt die Quelle keine Abschnitte an.');
        }

        $markdown = "# Erstens\n\n" . str_repeat('Inhalt dazu. ', 40) . "\n\n# Zweitens\n\n" . str_repeat('Mehr Inhalt. ', 40);
        $document = ['id' => 11, 'title' => 'T', 'content' => strip_tags($markdown), 'content_raw' => $markdown, 'content_raw_format' => IndexDocument::FORMAT_MARKDOWN];

        $nachGliederung = new rex_search_chunk_repository_spy();
        $this->builder([$document], $nachGliederung, new rex_search_vector_store_spy())->rebuildSource($this->source(true, 'markdown'));

        $ganz = new rex_search_chunk_repository_spy();
        $this->builder([$document], $ganz, new rex_search_vector_store_spy())->rebuildSource($this->source(true, 'whole'));

        self::assertCount(2, $nachGliederung->synced[11]);
        self::assertCount(1, $ganz->synced[11]);
    }

    /**
     * Ist die Zerlegung aus, wird nicht nur nichts angelegt -- was dasteht, muss weg.
     * Sonst bliebe nach dem Abschalten ein Wortschatz an Abschnitten stehen, fuer den
     * weiter Vektoren erzeugt wuerden.
     */
    public function testSwitchingTheChunkingOffClearsWhatIsThere(): void
    {
        $chunks = new rex_search_chunk_repository_spy();
        $vectors = new rex_search_vector_store_spy();

        $result = $this->builder([], $chunks, $vectors)->rebuildSource($this->source(false));

        self::assertSame([7], $chunks->deletedSources);
        self::assertSame([7], $vectors->deletedSources);
        self::assertSame(0, $result['chunks']);
    }

    /**
     * Nach einer Einzelaktualisierung werden nur die Dokumente dieses Datensatzes neu
     * zerlegt; was der Datensatz nicht mehr liefert, raeumt derselbe Weg auf wie beim
     * vollstaendigen Lauf.
     */
    public function testRebuildItemOnlyTouchesTheDocumentsOfThatItem(): void
    {
        if (!VectorSupport::isAvailable()) {
            self::markTestSkipped('Ohne Vektoren legt die Quelle keine Abschnitte an.');
        }

        $chunks = new rex_search_chunk_repository_spy();
        $builder = $this->builder(
            [
                ['id' => 11, 'item_id' => '5-1', 'title' => 'Eins', 'content' => 'Erster Text.', 'content_raw' => null, 'content_raw_format' => null],
                ['id' => 12, 'item_id' => '6-1', 'title' => 'Zwei', 'content' => 'Zweiter Text.', 'content_raw' => null, 'content_raw_format' => null],
            ],
            $chunks,
            new rex_search_vector_store_spy(),
        );

        $result = $builder->rebuildItem($this->source(true, 'whole'), '5-1');

        self::assertSame([11], array_keys($chunks->synced));
        self::assertSame(1, $result['chunks']);
        self::assertSame(1, $chunks->orphanRuns);
    }

    /**
     * Anders als rebuildSource() raeumt die Einzelaktualisierung bei abgeschalteter
     * Zerlegung nicht die ganze Quelle ab -- das gehoert zum vollstaendigen Lauf.
     */
    public function testRebuildItemWithoutChunkingLeavesTheSourceAlone(): void
    {
        $chunks = new rex_search_chunk_repository_spy();
        $vectors = new rex_search_vector_store_spy();

        $result = $this->builder([], $chunks, $vectors)->rebuildItem($this->source(false), '5-1');

        self::assertSame([], $chunks->deletedSources);
        self::assertSame([], $vectors->deletedSources);
        self::assertSame(0, $result['chunks']);
    }

    public function testAnEmptyDocumentYieldsNoChunks(): void
    {
        $chunks = new rex_search_chunk_repository_spy();
        $builder = $this->builder(
            [['id' => 11, 'title' => '', 'content' => '  ', 'content_raw' => null, 'content_raw_format' => null]],
            $chunks,
            new rex_search_vector_store_spy(),
        );

        $builder->rebuildSource($this->source(true));

        self::assertSame([], $chunks->synced[11] ?? []);
    }
}
