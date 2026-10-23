<?php

use FriendsOfRedaxo\Search\Index\ArticleReindexer;
use FriendsOfRedaxo\Search\Source\Source;
use PHPUnit\Framework\TestCase;

/** Doppel: merkt sich, was indiziert wuerde, statt Datenbank und Indexer anzufassen. */
final class rex_search_article_reindexer_double extends ArticleReindexer
{
    /** @var list<string> "{source_id}:{item_id}" */
    public array $reindexed = [];

    public int $scheduled = 0;

    public int $categoryLookups = 0;

    /** @var list<string> */
    public array $reported = [];

    /**
     * @param list<Source> $sources
     * @param array<int, list<string>> $below Artikel unterhalb einer Kategorie
     * @param list<int> $failing Quellen, deren Indizierung scheitert
     */
    public function __construct(
        private array $sources,
        private array $below = [],
        private array $failing = [],
    ) {}

    protected function reindex(Source $source, string $itemId): void
    {
        if (in_array($source->requireId(), $this->failing, true)) {
            throw new RuntimeException('Quelle ' . $source->requireId() . ' ist kaputt.');
        }
        $this->reindexed[] = $source->requireId() . ':' . $itemId;
    }

    protected function findSources(): array
    {
        return $this->sources;
    }

    protected function findArticlesBelow(int $categoryId, ?int $clangId): array
    {
        ++$this->categoryLookups;

        return $this->below[$categoryId] ?? [];
    }

    protected function schedule(): void
    {
        ++$this->scheduled;
    }

    protected function report(Throwable $e): void
    {
        $this->reported[] = $e->getMessage();
    }
}

/** @internal */
final class rex_search_article_reindexer_test extends TestCase
{
    /**
     * Zehn gespeicherte Bloecke sind eine Aktualisierung, nicht zehn: gesammelt wird
     * waehrend des Requests, indiziert am Ende.
     */
    public function testEachArticleIsIndexedOncePerRequest(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1)]);

        $reindexer->addArticle(5, 1);
        $reindexer->addArticle(5, 1);
        $reindexer->addArticle(5, 2);
        $reindexer->flush();

        self::assertSame(['1:5-1', '1:5-2'], $reindexer->reindexed);
    }

    public function testEverySourceGetsTheChanges(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1), $this->source(2)]);

        $reindexer->addArticle(5, 1);
        $reindexer->flush();

        self::assertSame(['1:5-1', '2:5-1'], $reindexer->reindexed);
    }

    /**
     * Status, Name und Pfad einer Kategorie stecken in jedem Artikel darunter: wird sie
     * offline geschaltet, muessen alle aus dem Index.
     */
    public function testACategoryCoversItsStartArticleAndEverythingBelow(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1)], [3 => ['8-1', '9-1']]);

        $reindexer->addCategory(3, 1);
        $reindexer->flush();

        self::assertSame(['1:3-1', '1:8-1', '1:9-1'], $reindexer->reindexed);
    }

    /**
     * Ohne eine Quelle, die mitlaufen will, bleibt es beim Vormerken; die Kategorie wird
     * gar nicht erst aufgeloest.
     */
    public function testWithoutSourcesNothingIsLookedUp(): void
    {
        $reindexer = new rex_search_article_reindexer_double([], [3 => ['8-1']]);

        $reindexer->addCategory(3, 1);
        $reindexer->addArticle(5, 1);
        $reindexer->flush();

        self::assertSame([], $reindexer->reindexed);
        self::assertSame(0, $reindexer->categoryLookups);
    }

    /**
     * Beide Wege ans Ende des Requests rufen flush() auf; der zweite findet nichts mehr vor.
     */
    public function testASecondFlushFindsNothingLeft(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1)]);

        $reindexer->addArticle(5, 1);
        $reindexer->flush();
        $reindexer->flush();

        self::assertSame(['1:5-1'], $reindexer->reindexed);
    }

    public function testTheEndOfTheRequestIsBookedOnlyOnce(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1)]);

        $reindexer->addArticle(5, 1);
        $reindexer->addArticle(6, 1);
        $reindexer->addCategory(3, 1);

        self::assertSame(1, $reindexer->scheduled);
    }

    public function testNothingIsBookedForInvalidIds(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1)]);

        $reindexer->addArticle(0, 1);
        $reindexer->addCategory(0, 1);

        self::assertSame(0, $reindexer->scheduled);
    }

    /**
     * Wer gerade gespeichert hat, soll von einer kaputten Quelle nichts merken, und die
     * anderen Quellen sollen trotzdem aktuell werden.
     */
    public function testAFailingSourceDoesNotStopTheOthers(): void
    {
        $reindexer = new rex_search_article_reindexer_double([$this->source(1), $this->source(2)], failing: [1]);

        $reindexer->addArticle(5, 1);
        $reindexer->flush();

        self::assertSame(['2:5-1'], $reindexer->reindexed);
        self::assertSame(['Quelle 1 ist kaputt.'], $reindexer->reported);
    }

    public function testOnlyArticleSourcesThatAskForItTakePart(): void
    {
        $selected = ArticleReindexer::selectSources([
            new Source(1, 'Inhalt', 'article', ['reindex_on_change' => true]),
            new Source(2, 'Metadaten', 'article_meta', ['reindex_on_change' => false]),
            new Source(3, 'Metadaten ohne Angabe', 'article_meta', []),
            new Source(4, 'Medien', 'media', ['reindex_on_change' => true]),
            new Source(5, 'Unbekannt', 'gibt_es_nicht', ['reindex_on_change' => true]),
        ]);

        self::assertSame([1], array_map(static fn (Source $source): int => $source->requireId(), $selected));
    }

    private function source(int $id): Source
    {
        return new Source($id, 'Quelle ' . $id, 'article', ['reindex_on_change' => true]);
    }
}
