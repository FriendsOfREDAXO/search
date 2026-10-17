<?php

use FriendsOfRedaxo\Search\Query\SearchHit;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_search_hit_test extends TestCase
{
    /**
     * @param list<array<string, mixed>> $passages
     * @param list<array<string, mixed>> $group
     */
    private function hit(?float $similarity = null, array $passages = [], array $group = []): SearchHit
    {
        return new SearchHit(1, 19, 'article', 'fullcontent', '42-1', 'Hasen', '/hasen/', 1, 'de', 2.5, 0.8, $similarity, $passages, $group, 'Auszug');
    }

    /**
     * Ohne semantische Suche bleibt die Aehnlichkeit null -- und nicht 0.0. Der
     * Unterschied zaehlt: 0.0 hiesse "geprueft und unaehnlich", null heisst "gar nicht
     * gefragt", und nur daran ist abzulesen, ob die Bedeutung an einem Treffer beteiligt
     * war.
     */
    public function testWithoutSemanticSearchThereIsNoSimilarityAtAll(): void
    {
        self::assertNull($this->hit()->similarity);
        self::assertFalse($this->hit()->isSemantic());
        self::assertTrue($this->hit(0.0)->isSemantic());
        self::assertTrue($this->hit(0.62)->isSemantic());
    }

    public function testAHitWithoutAGroupStillCountsAsOne(): void
    {
        // Ohne Gruppierung gibt es keine Liste, der Treffer ist trotzdem eine Zeile.
        self::assertSame(1, $this->hit()->countGrouped());
        self::assertSame(1, $this->hit(null, [], [['id' => 1, 'source_id' => 19, 'type' => 'a', 'score' => 1.0]])->countGrouped());
        self::assertSame(3, $this->hit(null, [], [
            ['id' => 1, 'source_id' => 19, 'type' => 'a', 'score' => 1.0],
            ['id' => 2, 'source_id' => 4, 'type' => 'b', 'score' => 0.5],
            ['id' => 3, 'source_id' => 5, 'type' => 'c', 'score' => 0.2],
        ])->countGrouped());
    }

    /**
     * toArray() ist die Form, die ein fremdes AddOn und die Testsuche zu sehen bekommen.
     * Was am Treffer haengt, muss darin vorkommen -- sonst zeigt die aufklappbare
     * Ansicht weniger, als die API liefert.
     */
    public function testEverythingThatHangsOnTheHitIsInTheArray(): void
    {
        $passages = [['chunk_id' => 7, 'chunk_no' => 2, 'chunk_total' => 5, 'heading_path' => 'A', 'text' => 'T', 'similarity' => 0.62]];
        $group = [['id' => 1, 'source_id' => 19, 'type' => 'fullcontent', 'score' => 2.5]];

        $array = $this->hit(0.62, $passages, $group)->toArray();

        self::assertSame($passages, $array['passages']);
        self::assertSame($group, $array['group']);
        self::assertSame(0.62, $array['similarity']);
        foreach (['id', 'source_id', 'namespace', 'type', 'item_id', 'title', 'url', 'language', 'score', 'relevance', 'snippet'] as $key) {
            self::assertArrayHasKey($key, $array);
        }
    }

    public function testTheArrayIsJsonSerialisable(): void
    {
        // Die Testsuche gibt es als JSON aus; ein nicht kodierbarer Wert faellt sonst
        // erst dort auf, und zwar als leeres Aufklappfeld.
        $json = json_encode($this->hit(0.62)->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"similarity": 0.62', json_encode($this->hit(0.62)->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        self::assertIsString($json);
    }
}
