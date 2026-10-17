<?php

use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\EmbeddingException;
use FriendsOfRedaxo\Search\Vector\QueryVectorCache;
use FriendsOfRedaxo\Search\Vector\SemanticSearch;
use PHPUnit\Framework\TestCase;

/**
 * Doppel: liefert vorgegebene Abschnitte statt die Datenbank zu fragen.
 *
 * Die Nachbarsuche selbst gehoert MariaDB. Hier geht es um das, was danach passiert:
 * Abschnitte zu Dokumenten zusammenfassen, die Schwelle anwenden, begrenzen.
 */
final class rex_search_semantic_search_double extends SemanticSearch
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public ?string $vector = 'VEKTOR';

    /** @var array<string, list<int|string>> */
    public array $seenFilters = [];

    public int $seenLimit = 0;

    public int $reads = 0;

    protected function vectorFor(string $question): ?string
    {
        return $this->vector;
    }

    protected function readRows(string $vector, array $filters, int $limit): array
    {
        ++$this->reads;
        $this->seenFilters = $filters;
        $this->seenLimit = $limit;

        return $this->rows;
    }
}

/** Doppel: antwortet ohne Dienst und zaehlt die Aufrufe. */
final class rex_search_embedding_client_double extends EmbeddingClient
{
    public int $calls = 0;

    /** @param list<list<float>>|null $answer null heisst: der Dienst faellt aus */
    public function __construct(
        private readonly ?array $answer = [[0.1, 0.2]],
    ) {}

    public function embed(array $texts): array
    {
        ++$this->calls;
        if (null === $this->answer) {
            throw new EmbeddingException('Dienst nicht erreichbar.');
        }

        return $this->answer;
    }
}

/** Doppel: haelt die gemerkten Vektoren im Speicher. */
final class rex_search_query_vector_cache_double extends QueryVectorCache
{
    /** @var array<string, string> */
    public array $entries = [];

    public function get(string $question, int $profileId): ?string
    {
        return $this->entries[$question . "\0" . $profileId] ?? null;
    }

    public function put(string $question, int $profileId, string $vector): void
    {
        $this->entries[$question . "\0" . $profileId] = $vector;
    }
}

/** Doppel fuer den Weg zum Dienst: festes Profil, Einbettung von aussen pruefbar. */
final class rex_search_semantic_vector_double extends SemanticSearch
{
    public ?int $profile = 16;

    public function vectorForQuestion(string $question): ?string
    {
        return $this->vectorFor($question);
    }

    protected function profileId(): ?int
    {
        return $this->profile;
    }
}

/** @internal */
final class rex_search_semantic_search_test extends TestCase
{
    /**
     * Eine Zeile der Nachbarsuche: ein Abschnitt samt der Indexzeile, zu der er gehoert.
     *
     * @return array<string, mixed>
     */
    private function row(float $distance, string $itemId, string $content, array $overrides = []): array
    {
        return $overrides + [
            'chunk_id' => random_int(1, 999999),
            'document_id' => 1,
            'content' => $content,
            'heading_path' => '',
            'chunk_no' => 0,
            'chunk_total' => 1,
            'namespace' => 'article',
            'item_id' => $itemId,
            'type' => 'fullcontent',
            'source_id' => 19,
            'clang_id' => 1,
            'language' => 'de',
            'distance' => $distance,
        ];
    }

    private function search(array $rows): rex_search_semantic_search_double
    {
        $search = new rex_search_semantic_search_double();
        $search->rows = $rows;

        return $search;
    }

    public function testADocumentIsAsSimilarAsItsBestSection(): void
    {
        // Nicht der Mittelwert: der bestrafte ein langes Dokument dafuer, dass es auch
        // anderes enthaelt, und genau das soll es duerfen.
        $treffer = $this->search([
            $this->row(0.2, '42-1', 'Der passende Abschnitt'),
            $this->row(0.6, '42-1', 'Ein anderes Thema im selben Dokument'),
        ])->findDocuments('frage', 0.0, 10);

        self::assertCount(1, $treffer);
        self::assertEqualsWithDelta(0.8, $treffer[0]['similarity'], 1e-9);
    }

    public function testTheBestSectionBecomesTheSnippet(): void
    {
        $treffer = $this->search([
            $this->row(0.2, '42-1', 'Der passende Abschnitt'),
            $this->row(0.6, '42-1', 'Ein anderes Thema'),
        ])->findDocuments('frage', 0.0, 10);

        self::assertSame('Der passende Abschnitt', $treffer[0]['snippet']);
    }

    /**
     * Die weiteren Abschnitte entscheiden nichts mehr, aber sie belegen, woran es lag.
     * Ohne sie stuende bei einem Treffer, den nur die Bedeutung gefunden hat, der Anfang
     * des Dokuments -- und niemand saehe, warum er in der Liste steht.
     */
    public function testTheFurtherSectionsRideAlongAsEvidence(): void
    {
        $treffer = $this->search([
            $this->row(0.2, '42-1', 'Erster'),
            $this->row(0.3, '42-1', 'Zweiter'),
        ])->findDocuments('frage', 0.0, 10);

        self::assertSame(['Erster', 'Zweiter'], array_column($treffer[0]['passages'], 'text'));
        self::assertSame([0.8, 0.7], array_column($treffer[0]['passages'], 'similarity'));
    }

    public function testASeventyPageDocumentDoesNotHangSeventyPassagesOnOneHit(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; ++$i) {
            $rows[] = $this->row(0.2 + $i / 100, '42-1', 'Abschnitt ' . $i);
        }

        $treffer = $this->search($rows)->findDocuments('frage', 0.0, 10);

        self::assertCount(SemanticSearch::MAX_PASSAGES_PER_DOCUMENT, $treffer[0]['passages']);
    }

    /**
     * Der Kern der Sache. Erfassen vier Quellen denselben Artikel, liegen vier Zeilen im
     * Index -- verschiedene document_id, derselbe Artikel. Ueber die Zeilen-Id
     * verbrauchten sie vier der angeforderten Plaetze und lieferten am Ende doch nur
     * einen Treffer. Am Bestand gemessen war genau das der Fall: "Hasen" steht in vier
     * Quellen.
     */
    public function testTheSameItemInFourSourcesIsOneHit(): void
    {
        $treffer = $this->search([
            $this->row(0.2, '42-1', 'Aus Quelle A', ['document_id' => 1, 'source_id' => 1]),
            $this->row(0.3, '42-1', 'Aus Quelle B', ['document_id' => 2, 'source_id' => 2]),
            $this->row(0.4, '42-1', 'Aus Quelle C', ['document_id' => 3, 'source_id' => 3]),
            $this->row(0.5, '42-1', 'Aus Quelle D', ['document_id' => 4, 'source_id' => 4]),
        ])->findDocuments('frage', 0.0, 10);

        self::assertCount(1, $treffer);
        self::assertSame(1, $treffer[0]['document_id']);
    }

    public function testTheSameItemIdInAnotherNamespaceIsAnotherHit(): void
    {
        // Artikel 7 und Medium 7 haben dieselbe item_id und nichts miteinander zu tun.
        $treffer = $this->search([
            $this->row(0.2, '7', 'Der Artikel'),
            $this->row(0.3, '7', 'Das Medium', ['namespace' => 'media']),
        ])->findDocuments('frage', 0.0, 10);

        self::assertCount(2, $treffer);
    }

    public function testSectionsBelowTheThresholdAreDropped(): void
    {
        $treffer = $this->search([
            $this->row(0.3, '1', 'Nah genug'),
            $this->row(0.7, '2', 'Zu fern'),
        ])->findDocuments('frage', 0.5, 10);

        self::assertSame(['1'], array_column($treffer, 'item_id'));
    }

    public function testTheThresholdAlsoStopsAFurtherSectionOfADocumentAlreadyFound(): void
    {
        $treffer = $this->search([
            $this->row(0.3, '1', 'Nah genug'),
            $this->row(0.9, '1', 'Derselbe Artikel, aber weit weg'),
        ])->findDocuments('frage', 0.5, 10);

        self::assertCount(1, $treffer[0]['passages']);
    }

    public function testNothingOverTheThresholdIsAnEmptyResult(): void
    {
        self::assertSame([], $this->search([$this->row(0.9, '1', 'Zu fern')])->findDocuments('frage', 0.5, 10));
    }

    /**
     * Die Begrenzung gilt fuer Dokumente, nicht fuer Zeilen -- und sie greift erst am
     * Ende. Wer beim Erreichen der Zahl abbricht, verliert die Abschnitte, die weiter
     * unten noch zu den bereits gefundenen Dokumenten gehoeren.
     */
    public function testTheLimitCountsDocumentsAndStillCollectsLaterSections(): void
    {
        $treffer = $this->search([
            $this->row(0.2, '1', 'Erstes Dokument'),
            $this->row(0.3, '2', 'Zweites Dokument'),
            $this->row(0.4, '1', 'Noch ein Abschnitt des ersten'),
        ])->findDocuments('frage', 0.0, 1);

        self::assertCount(1, $treffer);
        self::assertSame('1', $treffer[0]['item_id']);
        self::assertCount(2, $treffer[0]['passages']);
    }

    public function testFiltersAndLimitReachTheQueryUnchanged(): void
    {
        $search = $this->search([]);
        $search->findDocuments('frage', 0.4, 7, ['source_id' => [1, 2], 'language' => ['de']]);

        self::assertSame(['source_id' => [1, 2], 'language' => ['de']], $search->seenFilters);
        self::assertSame(7, $search->seenLimit);
    }

    /**
     * clang_id ist im Index nullable -- ein Medium gehoert zu keiner Sprache. Als null
     * weitergereicht, scheiterte spaeter die Typpruefung des Treffers.
     */
    public function testAMissingClangBecomesZero(): void
    {
        $treffer = $this->search([$this->row(0.2, '7', 'Ein Medium', ['clang_id' => null])])->findDocuments('frage', 0.0, 10);

        self::assertSame(0, $treffer[0]['clang_id']);
    }

    public function testWithoutAVectorNothingIsAskedOfTheDatabase(): void
    {
        // Faellt der Dienst aus, soll die Volltextsuche weiterlaufen -- aber keine
        // Nachbarsuche ohne Vektor starten.
        $search = $this->search([$this->row(0.1, '1', 'Egal')]);
        $search->vector = null;

        self::assertSame([], $search->findDocuments('frage', 0.0, 10));
        self::assertSame(0, $search->reads);
    }

    // ---------- Der Weg zum Dienst ----------

    private function vectorSearch(
        rex_search_embedding_client_double $client,
        rex_search_query_vector_cache_double $cache,
    ): rex_search_semantic_vector_double {
        return new rex_search_semantic_vector_double($client, null, $cache);
    }

    public function testAKnownQuestionDoesNotReachTheService(): void
    {
        $client = new rex_search_embedding_client_double();
        $cache = new rex_search_query_vector_cache_double();
        $cache->entries["gemerkte Frage\0" . 16] = '[0.5,0.5]';

        self::assertSame('[0.5,0.5]', $this->vectorSearch($client, $cache)->vectorForQuestion('gemerkte Frage'));
        self::assertSame(0, $client->calls);
    }

    public function testANewQuestionIsEmbeddedAndRemembered(): void
    {
        $client = new rex_search_embedding_client_double([[0.1, 0.2]]);
        $cache = new rex_search_query_vector_cache_double();

        $vektor = $this->vectorSearch($client, $cache)->vectorForQuestion('neue Frage');

        self::assertSame(1, $client->calls);
        self::assertSame($vektor, $cache->entries["neue Frage\0" . 16]);
    }

    /**
     * Antwortet der Dienst nicht, liefert die Suche keinen Vektor -- aber sie wirft auch
     * nicht. Die Volltextsuche laeuft weiter; eine Suche, die gar nichts liefert, waere
     * die schlechtere Antwort.
     */
    public function testAFailingServiceYieldsNoVectorInsteadOfAnError(): void
    {
        $cache = new rex_search_query_vector_cache_double();

        self::assertNull($this->vectorSearch(new rex_search_embedding_client_double(null), $cache)->vectorForQuestion('frage'));
        self::assertSame([], $cache->entries);
    }

    public function testAnEmptyAnswerIsNotRemembered(): void
    {
        $cache = new rex_search_query_vector_cache_double();

        self::assertNull($this->vectorSearch(new rex_search_embedding_client_double([]), $cache)->vectorForQuestion('frage'));
        self::assertSame([], $cache->entries);
    }

    public function testWithoutAProfileTheServiceIsNotAsked(): void
    {
        $client = new rex_search_embedding_client_double();
        $search = $this->vectorSearch($client, new rex_search_query_vector_cache_double());
        $search->profile = null;

        self::assertNull($search->vectorForQuestion('frage'));
        self::assertSame(0, $client->calls);
    }

    public function testAnEmptyQuestionIsNotEmbedded(): void
    {
        $client = new rex_search_embedding_client_double();

        self::assertNull($this->vectorSearch($client, new rex_search_query_vector_cache_double())->vectorForQuestion('   '));
        self::assertSame(0, $client->calls);
    }
}
