<?php

use FriendsOfRedaxo\Search\Passage\Retriever;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\EmbeddingException;
use FriendsOfRedaxo\Search\Vector\QueryVectorCache;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use PHPUnit\Framework\TestCase;

/**
 * Doppel: legt die Naht frei, die sonst nur retrieve() benutzt, und beantwortet
 * die Profilfrage ohne ai_platform.
 */
final class rex_search_retriever_double extends Retriever
{
    public ?int $profile = 7;

    public function vectorForQuestion(string $question): ?string
    {
        return $this->vectorFor($question);
    }

    protected function profileId(): ?int
    {
        return $this->profile;
    }
}

/** Doppel: zaehlt die Aufrufe, statt den Dienst zu fragen. */
final class rex_search_retriever_client_double extends EmbeddingClient
{
    public int $calls = 0;

    public bool $fails = false;

    /** @var list<float> */
    public array $vector = [0.5, -0.25];

    public function embed(array $texts): array
    {
        ++$this->calls;

        if ($this->fails) {
            throw new EmbeddingException('Dienst antwortet nicht.');
        }

        return [$this->vector];
    }
}

/** Doppel: haelt die Eintraege im Speicher statt in der Datenbank. */
final class rex_search_retriever_cache_double extends QueryVectorCache
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

/**
 * Der Zwischenspeicher der Passagensuche.
 *
 * Die Nachbarsuche selbst gehoert MariaDB und steht hier nicht zur Debatte. Geprueft
 * wird allein, wann der Dienst gefragt wird und wann nicht -- der Unterschied kostet
 * einen Weg uebers Netz pro Frage.
 *
 * @internal
 */
final class rex_search_retriever_test extends TestCase
{
    public function testAQuestionIsEmbeddedOnceAndThenRemembered(): void
    {
        $client = new rex_search_retriever_client_double();
        $retriever = $this->retriever($client, $cache = new rex_search_retriever_cache_double());

        $first = $retriever->vectorForQuestion('Wie storniere ich eine Reise?');
        $second = $retriever->vectorForQuestion('Wie storniere ich eine Reise?');

        self::assertSame('[0.5,-0.25]', $first);
        self::assertSame($first, $second);
        self::assertSame(1, $client->calls, 'Die zweite Frage darf den Dienst nicht erneut kosten.');
        self::assertCount(1, $cache->entries);
    }

    /**
     * Die Textform ist, was gespeichert wird und was VEC_FromText erwartet -- in
     * keiner Richtung wird umgerechnet.
     */
    public function testARememberedVectorIsReturnedUnchangedAndWithoutAsking(): void
    {
        $cache = new rex_search_retriever_cache_double();
        $cache->entries['gemerkte Frage' . "\0" . 7] = '[0.125,0.875]';
        $client = new rex_search_retriever_client_double();

        self::assertSame('[0.125,0.875]', $this->retriever($client, $cache)->vectorForQuestion('gemerkte Frage'));
        self::assertSame(0, $client->calls);
    }

    /**
     * Antwortet der Dienst nicht, gibt es keine Passagen -- und nichts zu merken.
     * Ein leerer Eintrag wuerde die Frage dauerhaft unbeantwortbar machen.
     */
    public function testAFailingServiceStoresNothing(): void
    {
        $client = new rex_search_retriever_client_double();
        $client->fails = true;
        $cache = new rex_search_retriever_cache_double();

        self::assertNull($this->retriever($client, $cache)->vectorForQuestion('Frage'));
        self::assertSame([], $cache->entries);
    }

    public function testWithoutAProfileNothingIsAskedOrStored(): void
    {
        $client = new rex_search_retriever_client_double();
        $cache = new rex_search_retriever_cache_double();
        $retriever = $this->retriever($client, $cache);
        $retriever->profile = null;

        self::assertNull($retriever->vectorForQuestion('Frage'));
        self::assertSame(0, $client->calls);
        self::assertSame([], $cache->entries);
    }

    private function retriever(
        rex_search_retriever_client_double $client,
        rex_search_retriever_cache_double $cache,
    ): rex_search_retriever_double {
        return new rex_search_retriever_double($client, new VectorStore(), $cache);
    }
}
