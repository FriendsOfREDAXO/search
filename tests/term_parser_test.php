<?php

use FriendsOfRedaxo\Search\Query\SearchTerms;
use FriendsOfRedaxo\Search\Query\TermParser;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_term_parser_test extends TestCase
{
    protected function setUp(): void
    {
        // Feste Serverwerte, damit die Tests nicht von der Datenbank abhaengen.
        TermParser::reset(3, ['the', 'und', 'de', 'www']);
    }

    protected function tearDown(): void
    {
        TermParser::reset();
    }

    public function testEveryWordIsRequiredAndMatchesAsPrefix(): void
    {
        $terms = (new TermParser())->parse('Burg Eppstein');

        self::assertSame('+Burg* +Eppstein*', $terms->expression);
        self::assertSame(['Burg', 'Eppstein'], $terms->words);
    }

    public function testOperatorsAreStrippedInsteadOfInterpreted(): void
    {
        $terms = (new TermParser())->parse('-burg +"katze"* (hund)');

        self::assertSame('+burg* +katze* +hund*', $terms->expression);
    }

    public function testStopwordIsDroppedWithReasonInsteadOfBlockingTheQuery(): void
    {
        $terms = (new TermParser())->parse('Espresso und Boule');

        self::assertSame(['Espresso', 'Boule'], $terms->words);
        self::assertSame(['und' => SearchTerms::REASON_STOPWORD], $terms->ignored);
        self::assertStringNotContainsString('und', $terms->expression);
    }

    public function testWordShorterThanMinTokenSizeIsDroppedWithReason(): void
    {
        $terms = (new TermParser())->parse('zu Burg');

        self::assertSame(['Burg'], $terms->words);
        self::assertSame(['zu' => SearchTerms::REASON_TOO_SHORT], $terms->ignored);
    }

    public function testStopwordIsRecognisedRegardlessOfCase(): void
    {
        $terms = (new TermParser())->parse('Und Burg');

        self::assertSame(['Burg'], $terms->words);
        self::assertSame(['Und' => SearchTerms::REASON_STOPWORD], $terms->ignored);
    }

    public function testRepeatedWordIsUsedOnce(): void
    {
        $terms = (new TermParser())->parse('burg burg');

        self::assertSame(['burg'], $terms->words);
    }

    public function testBlankInputYieldsAnEmptyExpression(): void
    {
        $terms = (new TermParser())->parse('  +  -  ');

        self::assertTrue($terms->isEmpty());
        self::assertSame([], $terms->ignored);
        self::assertFalse($terms->isEmptyButNotBlank());
    }

    public function testInputOfOnlyUnusableWordsIsDistinguishableFromBlankInput(): void
    {
        $terms = (new TermParser())->parse('und the');

        self::assertTrue($terms->isEmpty());
        self::assertTrue($terms->isEmptyButNotBlank());
    }

    public function testNumberOfWordsIsCapped(): void
    {
        $terms = (new TermParser())->parse(implode(' ', array_map(static fn (int $i): string => 'wort' . $i, range(1, 30))));

        self::assertSame(12, $terms->countWords());
    }
}
