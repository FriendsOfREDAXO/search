<?php

use FriendsOfRedaxo\Search\Query\SearchQuery;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_search_query_test extends TestCase
{
    public function testWithMethodsLeaveTheOriginalUntouched(): void
    {
        $query = SearchQuery::create('burg');
        $narrowed = $query->withLimit(5)->withNamespaces('article');

        self::assertSame(SearchQuery::DEFAULT_LIMIT, $query->getLimit());
        self::assertSame([], $query->getNamespaces());
        self::assertSame(5, $narrowed->getLimit());
        self::assertSame(['article'], $narrowed->getNamespaces());
    }

    public function testWithTermKeepsTheOtherOptions(): void
    {
        $query = SearchQuery::create('burg')->withLimit(7)->withNamespaces('article')->withGrouping(false);

        $other = $query->withTerm('eppstein');

        self::assertSame('eppstein', $other->getTerm());
        self::assertSame(7, $other->getLimit());
        self::assertSame(['article'], $other->getNamespaces());
        self::assertFalse($other->isGrouped());
    }

    public function testPageTwoBecomesAnOffset(): void
    {
        $query = SearchQuery::create('burg')->withPage(3, 10);

        self::assertSame(10, $query->getLimit());
        self::assertSame(20, $query->getOffset());
    }

    public function testFirstPageHasNoOffsetEvenForInvalidInput(): void
    {
        self::assertSame(0, SearchQuery::create('burg')->withPage(0, 10)->getOffset());
        self::assertSame(0, SearchQuery::create('burg')->withPage(-5, 10)->getOffset());
    }

    public function testFiltersOnlyContainWhatWasSet(): void
    {
        $query = SearchQuery::create('burg')->withNamespaces('article')->withClangIds(1, 2);

        self::assertSame(['namespace' => ['article'], 'clang_id' => [1, 2]], $query->getFilters());
        self::assertTrue($query->hasFilters());
    }

    public function testQueryWithoutFiltersReportsNone(): void
    {
        self::assertSame([], SearchQuery::create('burg')->getFilters());
        self::assertFalse(SearchQuery::create('burg')->hasFilters());
    }

    public function testDuplicateFilterValuesAreCollapsed(): void
    {
        $query = SearchQuery::create('burg')->withSourceIds(19, 19, 20);

        self::assertSame([19, 20], $query->getSourceIds());
    }

    public function testLimitIsAtLeastOneAndOffsetNeverNegative(): void
    {
        $query = SearchQuery::create('burg')->withLimit(0)->withOffset(-10);

        self::assertSame(1, $query->getLimit());
        self::assertSame(0, $query->getOffset());
    }
}
