<?php

use FriendsOfRedaxo\Search\Passage\PassageQuery;
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Vector\Similarity;
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

    public function testOnlyKnownColumnsAreAcceptedAsFacets(): void
    {
        $query = SearchQuery::create('burg')->withFacets('namespace', 'content', 'clang_id', 'drop table');

        self::assertSame(['namespace', 'clang_id'], $query->getFacets());
    }

    public function testTheRelevanceThresholdStaysBetweenZeroAndOne(): void
    {
        self::assertSame(0.0, SearchQuery::create('burg')->withMinRelevance(-1.0)->getMinRelevance());
        self::assertSame(1.0, SearchQuery::create('burg')->withMinRelevance(5.0)->getMinRelevance());
        self::assertSame(0.3, SearchQuery::create('burg')->withMinRelevance(0.3)->getMinRelevance());
    }

    public function testProximityDistanceIsAtLeastOneAndKeptWhenOmitted(): void
    {
        $query = SearchQuery::create('burg')->withProximityBoost(1.0, 0);
        self::assertSame(1, $query->getProximityDistance());

        $other = $query->withProximityBoost(0.5);
        self::assertSame(1, $other->getProximityDistance());
        self::assertSame(0.5, $other->getProximityBoost());
    }

    public function testWithTermKeepsTheNewOptionsToo(): void
    {
        $query = SearchQuery::create('burg')
            ->withFacets('namespace')
            ->withMinRelevance(0.4)
            ->withProximityBoost(0.2, 7);

        $other = $query->withTerm('eppstein');

        self::assertSame(['namespace'], $other->getFacets());
        self::assertSame(0.4, $other->getMinRelevance());
        self::assertSame(7, $other->getProximityDistance());
    }

    public function testLanguagesBecomeAFilterOfTheirOwnBesideTheClang(): void
    {
        $query = SearchQuery::create('burg')->withLanguages('de', 'en')->withClangIds(1);

        self::assertSame(['de', 'en'], $query->getLanguages());
        self::assertSame(['clang_id' => [1], 'language' => ['de', 'en']], $query->getFilters());
    }

    /**
     * Was hereinkommt, ist oft ein REDAXO-Sprachcode und kein ISO-Code. Unbekanntes wird
     * zur leeren Angabe -- die trifft dann Quellen ohne Sprache, was ehrlicher ist, als
     * stillschweigend alles durchzulassen.
     */
    public function testLanguagesAreNormalizedAndDeduplicated(): void
    {
        $query = SearchQuery::create('burg')->withLanguages('de_de', 'DE', 'de-CH');

        self::assertSame(['de'], $query->getLanguages());
    }

    public function testWithoutLanguagesNoLanguageFilterIsBuilt(): void
    {
        self::assertArrayNotHasKey('language', SearchQuery::create('burg')->getFilters());
    }

    public function testTheLanguageSurvivesANewTerm(): void
    {
        $query = SearchQuery::create('burg')->withLanguages('de')->withTerm('ruine');

        self::assertSame(['de'], $query->getLanguages());
    }

    public function testTheLanguageCanBeCountedAsAFacet(): void
    {
        self::assertSame(['language'], SearchQuery::create('burg')->withFacets('language')->getFacets());
    }

    /**
     * Aus ist die Vorgabe, und zwar aus zwei Gruenden: es kostet einen Aufruf beim
     * KI-Dienst, und es setzt voraus, dass fuer die Quellen ueberhaupt Vektoren erzeugt
     * wurden. Eine Suche, die beides stillschweigend annimmt, waere auf der Haelfte der
     * Installationen langsamer, ohne besser zu sein.
     */
    public function testSemanticSearchIsOffUntilItIsAskedFor(): void
    {
        self::assertFalse(SearchQuery::create('burg')->isSemantic());
        self::assertTrue(SearchQuery::create('burg')->withSemantic()->isSemantic());
        self::assertFalse(SearchQuery::create('burg')->withSemantic()->withSemantic(false)->isSemantic());
    }

    /**
     * Ohne Schwelle liefert die Nachbarsuche immer die naechsten Abschnitte, auch zu
     * einer Frage, zu der nichts dasteht -- dann haengte an jeder Suche dieselbe
     * Handvoll Dokumente.
     */
    public function testTheSemanticThresholdIsSetAndComesFromOnePlace(): void
    {
        self::assertGreaterThan(0.0, SearchQuery::create('burg')->getSemanticMinSimilarity());
        self::assertSame(Similarity::DEFAULT_MINIMUM, SearchQuery::create('burg')->getSemanticMinSimilarity());
        self::assertSame(Similarity::DEFAULT_MINIMUM, PassageQuery::create('burg')->getMinSimilarity());
    }

    public function testTheSemanticSettingsStayWithinBounds(): void
    {
        self::assertSame(1.0, SearchQuery::create('b')->withSemantic(true, 3.0)->getSemanticMinSimilarity());
        self::assertSame(0.0, SearchQuery::create('b')->withSemanticWeight(-2.0)->getSemanticWeight());
        self::assertSame(1, SearchQuery::create('b')->withSemanticLimit(0)->getSemanticLimit());
    }

    public function testExpansionStartsBelowTheLimitUnlessToldOtherwise(): void
    {
        $query = SearchQuery::create('burg')->withLimit(100);

        self::assertSame(100, $query->getExpandBelow());
        self::assertSame(20, $query->withExpansion(true, 20)->getExpandBelow());
        self::assertSame(1, $query->withExpansion(true, -5)->getExpandBelow());
    }

    public function testTheExpansionThresholdSurvivesANewTermAndASwitchOff(): void
    {
        $query = SearchQuery::create('burg')->withExpansion(true, 20)->withExpansion(false)->withTerm('ruine');

        self::assertFalse($query->isExpanding());
        self::assertSame(20, $query->withExpansion()->getExpandBelow());
    }

    public function testTheSemanticSettingsSurviveANewTerm(): void
    {
        $query = SearchQuery::create('burg')->withSemantic(true, 0.3)->withSemanticWeight(0.5)->withTerm('ruine');

        self::assertTrue($query->isSemantic());
        self::assertSame(0.3, $query->getSemanticMinSimilarity());
        self::assertSame(0.5, $query->getSemanticWeight());
    }
}
