<?php

use FriendsOfRedaxo\Search\Query\ScoreExpression;
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Query\SearchTerms;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_score_expression_test extends TestCase
{
    private function terms(): SearchTerms
    {
        return new SearchTerms(['burg', 'eppstein']);
    }

    private function build(SearchQuery $query): ScoreExpression
    {
        $expression = new ScoreExpression($query, $this->terms());
        $expression->build();

        return $expression;
    }

    public function testDefaultScoreSaturatesCountsAndAddsBothBonuses(): void
    {
        $sql = (new ScoreExpression(SearchQuery::create('burg eppstein'), $this->terms()))->build();

        self::assertStringContainsString('LN(1 + MATCH (title, content) AGAINST (:term_content IN BOOLEAN MODE))', $sql);
        self::assertStringContainsString(':exact_boost * LN(1 + MATCH (title, content) AGAINST (:term_exact IN BOOLEAN MODE))', $sql);
        self::assertStringContainsString(':title_boost * LN(1 + MATCH (title) AGAINST (:term_title IN BOOLEAN MODE))', $sql);
    }

    public function testExactBonusUsesTheBareWordsSoItCannotNarrowTheResult(): void
    {
        $params = $this->build(SearchQuery::create('burg eppstein'))->getParams();

        self::assertSame('+burg* +eppstein*', $params['term_content']);
        self::assertSame('burg eppstein', $params['term_exact']);
    }

    public function testExactBoostOfZeroRemovesTheTermEntirely(): void
    {
        $expression = $this->build(SearchQuery::create('burg eppstein')->withExactBoost(0.0));

        self::assertStringNotContainsString('term_exact', $expression->build());
        self::assertArrayNotHasKey('exact_boost', $expression->getParams());
    }

    public function testTitleBoostOfZeroRemovesTheTermEntirely(): void
    {
        $expression = $this->build(SearchQuery::create('burg eppstein')->withTitleBoost(0.0));

        self::assertStringNotContainsString('term_title', $expression->build());
        self::assertArrayNotHasKey('title_boost', $expression->getParams());
    }

    public function testLengthDampingLeavesShortDocumentsAlone(): void
    {
        $sql = (new ScoreExpression(SearchQuery::create('burg'), $this->terms()))->build();

        // GREATEST(1, ...) ist der Schwellwert: unterhalb davon ist der Teiler genau 1.
        self::assertStringContainsString('/ (1 + LN(GREATEST(1, CHAR_LENGTH(content) / :length_scale)))', $sql);
    }

    public function testLengthScaleOfZeroRemovesTheDivision(): void
    {
        $expression = $this->build(SearchQuery::create('burg')->withLengthScale(0));

        self::assertStringNotContainsString('CHAR_LENGTH', $expression->build());
        self::assertArrayNotHasKey('length_scale', $expression->getParams());
    }

    public function testSourceWeightsBecomeACaseWithANeutralDefault(): void
    {
        $expression = $this->build(SearchQuery::create('burg')->withSourceWeights([19 => 1.0, 20 => 0.5]));
        $params = $expression->getParams();

        self::assertStringContainsString('* CASE source_id WHEN :weight_source_0 THEN :weight_factor_0', $expression->build());
        self::assertStringContainsString('ELSE 1 END', $expression->build());
        self::assertSame(19, $params['weight_source_0']);
        self::assertSame(0.5, $params['weight_factor_1']);
    }

    public function testWithoutSourceWeightsThereIsNoCase(): void
    {
        self::assertStringNotContainsString('CASE', $this->build(SearchQuery::create('burg'))->build());
    }

    public function testDampingAppliesToTheWholeSumNotOnlyTheLastTerm(): void
    {
        $sql = (new ScoreExpression(SearchQuery::create('burg'), $this->terms()))->build();

        // Die Summe muss geklammert sein, sonst traefe die Division nur den Titelzuschlag.
        self::assertStringStartsWith('(LN(1 + MATCH', $sql);
        self::assertStringContainsString('BOOLEAN MODE))) / (1 + LN(GREATEST(', $sql);
    }

    public function testWordsCloseTogetherGetABonusViaAPhraseWithDistance(): void
    {
        $expression = $this->build(SearchQuery::create('burg eppstein'));
        $params = $expression->getParams();

        self::assertStringContainsString(':proximity_boost * LN(1 + MATCH (title, content) AGAINST (:term_proximity IN BOOLEAN MODE))', $expression->build());
        self::assertSame('"burg eppstein" @20', $params['term_proximity']);
    }

    public function testASingleWordGetsNoProximityBonusBecauseThereIsNoDistance(): void
    {
        $expression = new ScoreExpression(SearchQuery::create('burg'), new SearchTerms(['burg']));

        self::assertStringNotContainsString('term_proximity', $expression->build());
    }

    public function testProximityBoostOfZeroRemovesTheTermEntirely(): void
    {
        $expression = $this->build(SearchQuery::create('burg eppstein')->withProximityBoost(0.0));

        self::assertStringNotContainsString('term_proximity', $expression->build());
        self::assertArrayNotHasKey('proximity_boost', $expression->getParams());
    }

    public function testTheProximityDistanceIsConfigurable(): void
    {
        $expression = $this->build(SearchQuery::create('burg eppstein')->withProximityBoost(0.8, 5));

        self::assertSame('"burg eppstein" @5', $expression->getParams()['term_proximity']);
    }

    public function testWithoutAnExpansionThereIsNoSecondMatch(): void
    {
        $expression = new ScoreExpression(SearchQuery::create('burg'), new SearchTerms(['burg']));
        $expression->build();

        self::assertSame('', $expression->directMatch());
        self::assertArrayNotHasKey('term_direct', $expression->getParams());
    }

    /**
     * Die Vorsortierung fragt nach dem eingegebenen Wort allein. Faende sie mit dem
     * erweiterten Ausdruck, waere jeder Treffer ein direkter und die Unterscheidung
     * wirkungslos.
     */
    public function testTheDirectMatchAsksForTheTypedWordAlone(): void
    {
        $terms = new SearchTerms(['ruine'], [], ['ruine' => ['burgruine']]);
        $expression = new ScoreExpression(SearchQuery::create('ruine'), $terms);
        $expression->build();

        self::assertStringContainsString(':term_direct', $expression->directMatch());
        self::assertSame('+ruine*', $expression->getParams()['term_direct']);
        self::assertStringEndsWith('> 0)', $expression->directMatch());
    }
}
