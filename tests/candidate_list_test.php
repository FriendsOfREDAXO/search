<?php

use FriendsOfRedaxo\Search\Query\CandidateList;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_candidate_list_test extends TestCase
{
    /**
     * @param list<float> $scores
     * @return list<array<string, mixed>>
     */
    private function candidates(array $scores, string $namespace = 'article'): array
    {
        $list = [];
        foreach ($scores as $i => $score) {
            $list[] = [
                'id' => $i + 1,
                'namespace' => $namespace,
                'item_id' => (string) ($i + 1),
                'type' => 0 === $i % 2 ? 'fullcontent' : 'meta',
                'source_id' => 19,
                'clang_id' => 1,
                'score' => $score,
            ];
        }

        return $list;
    }

    public function testWithoutAThresholdNothingIsDropped(): void
    {
        $list = $this->candidates([10.0, 5.0, 1.0]);

        self::assertCount(3, CandidateList::dropBelowMinRelevance($list, 10.0, 0.0));
    }

    public function testTheThresholdIsAShareOfTheBestNotAnAbsoluteValue(): void
    {
        $list = $this->candidates([10.0, 5.0, 2.0, 1.0]);

        self::assertCount(2, CandidateList::dropBelowMinRelevance($list, 10.0, 0.5));
        self::assertCount(3, CandidateList::dropBelowMinRelevance($list, 10.0, 0.2));
    }

    public function testTheSameShareOfADifferentBestKeepsADifferentAmount(): void
    {
        // Dieselben Abstaende, andere Groessenordnung: der Anteil entscheidet, nicht die Zahl.
        $gross = $this->candidates([100.0, 50.0, 20.0]);
        $klein = $this->candidates([1.0, 0.5, 0.2]);

        self::assertCount(2, CandidateList::dropBelowMinRelevance($gross, 100.0, 0.5));
        self::assertCount(2, CandidateList::dropBelowMinRelevance($klein, 1.0, 0.5));
    }

    public function testTheBestHitItselfIsAlwaysKept(): void
    {
        self::assertCount(1, CandidateList::dropBelowMinRelevance($this->candidates([10.0, 1.0]), 10.0, 1.0));
    }

    public function testWithoutAnyScoreNothingIsDropped(): void
    {
        $list = $this->candidates([0.0, 0.0]);

        self::assertCount(2, CandidateList::dropBelowMinRelevance($list, 0.0, 0.9));
    }

    public function testFacetsAreCountedOverTheWholeList(): void
    {
        $list = array_merge($this->candidates([5.0, 4.0, 3.0]), $this->candidates([2.0], 'media'));

        $facets = CandidateList::countFacets($list, ['namespace', 'type']);

        self::assertSame(['article' => 3, 'media' => 1], $facets['namespace']);
        self::assertSame(['fullcontent' => 3, 'meta' => 1], $facets['type']);
    }

    public function testFacetsAreSortedByFrequencyNotByFirstAppearance(): void
    {
        // Der seltene Wert steht bewusst vorn: sonst stimmte die Reihenfolge auch ohne
        // Sortierung, und der Test bewiese nichts.
        $list = array_merge($this->candidates([9.0], 'media'), $this->candidates([5.0, 4.0, 3.0]));

        self::assertSame(['article' => 3, 'media' => 1], CandidateList::countFacets($list, ['namespace'])['namespace']);
    }

    public function testWithoutFacetsNothingIsCounted(): void
    {
        self::assertSame([], CandidateList::countFacets($this->candidates([1.0]), []));
    }

    public function testAnEmptyResultYieldsNoFacets(): void
    {
        self::assertSame([], CandidateList::countFacets([], ['namespace']));
    }

    public function testRelevanceIsTheShareOfTheBest(): void
    {
        self::assertSame(1.0, CandidateList::relevance(8.0, 8.0));
        self::assertSame(0.5, CandidateList::relevance(4.0, 8.0));
        self::assertSame(0.0, CandidateList::relevance(4.0, 0.0));
    }

    public function testRelevanceNeverLeavesTheRangeZeroToOne(): void
    {
        self::assertSame(1.0, CandidateList::relevance(12.0, 8.0));
        self::assertSame(0.0, CandidateList::relevance(-3.0, 8.0));
    }
}
