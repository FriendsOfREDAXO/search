<?php

use FriendsOfRedaxo\Search\Word\WordCollector;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_word_collector_test extends TestCase
{
    public function testAWordIsCountedOncePerDocument(): void
    {
        $collector = new WordCollector(7);
        $collector->add('Burg Burg Burg', 1);

        self::assertSame([['word' => 'Burg', 'clang' => 1, 'docs' => 1]], $collector->toArray());
    }

    public function testTheSameWordInTwoDocumentsCountsTwice(): void
    {
        $collector = new WordCollector(7);
        $collector->add('Burg Eppstein', 1);
        $collector->add('Burg Ruine', 1);

        $docs = [];
        foreach ($collector->toArray() as $entry) {
            $docs[$entry['word']] = $entry['docs'];
        }

        self::assertSame(['Burg' => 2, 'Eppstein' => 1, 'Ruine' => 1], $docs);
    }

    public function testLanguagesAreCountedSeparately(): void
    {
        $collector = new WordCollector(7);
        $collector->add('Burg', 1);
        $collector->add('Burg', 2);

        self::assertCount(2, $collector->toArray());
    }

    public function testDocumentsWithoutALanguageLandInZero(): void
    {
        $collector = new WordCollector(7);
        $collector->add('Burg', null);

        self::assertSame(0, $collector->toArray()[0]['clang']);
    }

    public function testAllCapsIsReplacedByAMixedSpellingFromAnotherDocument(): void
    {
        $collector = new WordCollector(7);
        $collector->add('EPPSTEIN', 1);
        $collector->add('Eppstein', 1);

        self::assertSame('Eppstein', $collector->toArray()[0]['word']);
        self::assertSame(2, $collector->toArray()[0]['docs']);
    }

    public function testAFreshCollectorIsEmpty(): void
    {
        $collector = new WordCollector(7);

        self::assertTrue($collector->isEmpty());
        self::assertSame(7, $collector->getSourceId());
    }
}
