<?php

use FriendsOfRedaxo\Search\Word\WordCollector;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_word_collector_test extends TestCase
{
    public function testAWordIsCountedOncePerDocument(): void
    {
        $collector = new WordCollector(7, 'de');
        $collector->add('Burg Burg Burg');

        self::assertSame([['word' => 'Burg', 'docs' => 1]], $collector->toArray());
    }

    public function testTheSameWordInTwoDocumentsCountsTwice(): void
    {
        $collector = new WordCollector(7, 'de');
        $collector->add('Burg Eppstein');
        $collector->add('Burg Ruine');

        $docs = [];
        foreach ($collector->toArray() as $entry) {
            $docs[$entry['word']] = $entry['docs'];
        }

        self::assertSame(['Burg' => 2, 'Eppstein' => 1, 'Ruine' => 1], $docs);
    }

    /**
     * Die Sprache gehoert der Quelle, nicht dem einzelnen Text: dasselbe Wort zweimal
     * aufzunehmen ergibt einen Eintrag, keine zwei. Frueher trennte der Sammler nach clang
     * und zaehlte deshalb doppelt -- bei zwei REDAXO-Sprachen derselben Sprache zerfiel
     * der Wortschatz damit grundlos.
     */
    public function testTheSameWordInTwoDocumentsIsOneEntry(): void
    {
        $collector = new WordCollector(7, 'de');
        $collector->add('Burg');
        $collector->add('Burg');

        self::assertCount(1, $collector->toArray());
        self::assertSame(2, $collector->toArray()[0]['docs']);
    }

    public function testTheLanguageComesFromTheSource(): void
    {
        self::assertSame('de', (new WordCollector(7, 'de'))->getLanguage());
        self::assertSame('', (new WordCollector(7))->getLanguage());
    }

    public function testAllCapsIsReplacedByAMixedSpellingFromAnotherDocument(): void
    {
        $collector = new WordCollector(7, 'de');
        $collector->add('EPPSTEIN');
        $collector->add('Eppstein');

        self::assertSame('Eppstein', $collector->toArray()[0]['word']);
        self::assertSame(2, $collector->toArray()[0]['docs']);
    }

    public function testAFreshCollectorIsEmpty(): void
    {
        $collector = new WordCollector(7, 'de');

        self::assertTrue($collector->isEmpty());
        self::assertSame(7, $collector->getSourceId());
    }
}
