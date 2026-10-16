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
    private function candidates(array $scores, string $namespace = 'article', bool $direct = true): array
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
                'language' => 'de',
                'direct' => $direct,
                'score' => $score,
            ];
        }

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $ranked
     * @return list<float>
     */
    private function relevances(array $ranked): array
    {
        return array_map(static fn (array $c): float => round($c['relevance'], 3), CandidateList::rank($ranked));
    }

    public function testRelevanceIsTheShareOfTheBest(): void
    {
        self::assertSame([1.0, 0.5, 0.25], $this->relevances($this->candidates([8.0, 4.0, 2.0])));
    }

    /**
     * Der Kern der Sache: ein Treffer, der nur ueber eine verwandte Form kam, darf keinen
     * direkten ueberholen -- auch dann nicht, wenn seine rohe Bewertung hoeher ist. Genau
     * das passierte gemessen bei "Häuser": der Haushaltsplan kam auf 6,06, die Seite mit
     * dem gesuchten Wort auf 2,94.
     */
    public function testAnExpandedHitNeverOutranksADirectOne(): void
    {
        $ranked = array_merge($this->candidates([2.94, 1.76]), $this->candidates([6.06], 'media', false));

        $relevances = $this->relevances($ranked);

        self::assertSame([1.0, 0.599, 0.599], $relevances);
        self::assertGreaterThanOrEqual($relevances[2], $relevances[1]);
    }

    public function testExpandedHitsAreScaledAmongThemselves(): void
    {
        $ranked = array_merge($this->candidates([10.0, 5.0]), $this->candidates([8.0, 4.0], 'media', false));

        // Obergrenze ist der schwaechste direkte Treffer mit 0,5; die erweiterten teilen
        // sich die Spanne darunter im Verhaeltnis ihrer Bewertungen.
        self::assertSame([1.0, 0.5, 0.5, 0.25], $this->relevances($ranked));
    }

    public function testWithoutAnyDirectHitTheExpandedOnesGetTheFullRange(): void
    {
        self::assertSame([1.0, 0.5], $this->relevances($this->candidates([8.0, 4.0], 'article', false)));
    }

    public function testWithoutAThresholdNothingIsDropped(): void
    {
        $list = CandidateList::rank($this->candidates([10.0, 5.0, 1.0]));

        self::assertCount(3, CandidateList::dropBelowMinRelevance($list, 0.0));
    }

    public function testTheThresholdIsAShareOfTheBestNotAnAbsoluteValue(): void
    {
        $list = CandidateList::rank($this->candidates([10.0, 5.0, 2.0, 1.0]));

        self::assertCount(2, CandidateList::dropBelowMinRelevance($list, 0.5));
        self::assertCount(3, CandidateList::dropBelowMinRelevance($list, 0.2));
    }

    public function testTheSameShareOfADifferentBestKeepsADifferentAmount(): void
    {
        // Dieselben Abstaende, andere Groessenordnung: der Anteil entscheidet, nicht die Zahl.
        $gross = CandidateList::rank($this->candidates([100.0, 50.0, 20.0]));
        $klein = CandidateList::rank($this->candidates([1.0, 0.5, 0.2]));

        self::assertCount(2, CandidateList::dropBelowMinRelevance($gross, 0.5));
        self::assertCount(2, CandidateList::dropBelowMinRelevance($klein, 0.5));
    }

    public function testTheBestHitItselfIsAlwaysKept(): void
    {
        self::assertCount(1, CandidateList::dropBelowMinRelevance(CandidateList::rank($this->candidates([10.0, 1.0])), 1.0));
    }

    public function testWithoutAnyScoreNothingIsDropped(): void
    {
        $list = CandidateList::rank($this->candidates([0.0, 0.0]));

        self::assertCount(2, CandidateList::dropBelowMinRelevance($list, 0.0));
    }

    public function testFacetsAreCountedOverTheWholeList(): void
    {
        $list = array_merge($this->candidates([5.0, 4.0, 3.0]), $this->candidates([2.0], 'media'));

        $facets = CandidateList::countFacets($list, ['namespace', 'type', 'language']);

        self::assertSame(['article' => 3, 'media' => 1], $facets['namespace']);
        self::assertSame(['fullcontent' => 3, 'meta' => 1], $facets['type']);
        self::assertSame(['de' => 4], $facets['language']);
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

    public function testRelevanceNeverLeavesTheRangeZeroToOne(): void
    {
        self::assertSame(1.0, CandidateList::relevance(12.0, 8.0));
        self::assertSame(0.0, CandidateList::relevance(-3.0, 8.0));
        self::assertSame(0.0, CandidateList::relevance(4.0, 0.0));
    }
}
