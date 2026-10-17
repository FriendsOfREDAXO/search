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
    private function candidates(array $scores, string $namespace = 'article', bool $direct = true, int $offset = 0): array
    {
        $list = [];
        foreach ($scores as $i => $score) {
            $list[] = [
                'id' => $offset + $i + 1,
                'namespace' => $namespace,
                'item_id' => (string) ($offset + $i + 1),
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

    public function testFusionKeepsTheKeywordRowForADocumentBothFound(): void
    {
        // Die Volltextzeile traegt die Kennung, mit der der Ausschnitt geladen wird --
        // die semantische Zeile kennt nur das Dokument. Die falsche zu behalten hiesse,
        // den Ausschnitt am falschen Ort zu suchen.
        $keyword = $this->candidates([5.0]);
        $semantic = $this->candidates([0.0], 'article', false);
        $semantic[0]['similarity'] = 0.9;
        $semantic[0]['snippet'] = 'Abschnitt';

        $fused = CandidateList::fuse($keyword, $semantic, 1.0);

        self::assertCount(1, $fused);
        self::assertTrue($fused[0]['direct']);
        self::assertSame(0.9, $fused[0]['similarity']);
        self::assertArrayNotHasKey('snippet', $fused[0]);
    }

    /**
     * Was die Bedeutung beigesteuert hat, geht dabei nicht verloren. Ohne die Abschnitte
     * waere an einem Treffer, den beide Wege gefunden haben, nicht mehr abzulesen, woran
     * es lag -- und die aufklappbare Ansicht der Testsuche bliebe leer.
     */
    public function testTheContributingPassagesSurviveTheFusion(): void
    {
        $semantic = $this->candidates([0.0], 'article', false);
        $semantic[0]['similarity'] = 0.9;
        $semantic[0]['passages'] = [
            ['chunk_id' => 7, 'chunk_no' => 1, 'chunk_total' => 3, 'heading_path' => 'A', 'text' => 'T', 'similarity' => 0.9],
        ];

        $fused = CandidateList::fuse($this->candidates([5.0]), $semantic, 1.0);

        self::assertSame($semantic[0]['passages'], $fused[0]['passages']);
    }

    /**
     * Zusammengefuehrt wird ueber Namensraum und item_id, nicht ueber die Zeilen-Id.
     * Erfassen zwei Quellen denselben Artikel -- Volltext und Metadaten --, liegen zwei
     * Zeilen im Index. Die Volltextsuche behaelt beim Zusammenfassen die eine, die
     * Bedeutung findet womoeglich die andere; ueber die Zeilen-Id stuende derselbe
     * Artikel danach zweimal in der Liste.
     */
    public function testTwoRowsOfTheSameItemAreOneHit(): void
    {
        $keyword = [[
            'id' => 1, 'namespace' => 'article', 'item_id' => '42-1', 'type' => 'fullcontent',
            'source_id' => 19, 'clang_id' => 1, 'language' => 'de', 'direct' => true, 'score' => 5.0,
        ]];
        $semantic = [[
            'id' => 99, 'namespace' => 'article', 'item_id' => '42-1', 'type' => 'meta',
            'source_id' => 4, 'clang_id' => 1, 'language' => 'de', 'direct' => false, 'score' => 0.0,
            'similarity' => 0.8, 'snippet' => 'Abschnitt',
        ]];

        $fused = CandidateList::fuse($keyword, $semantic, 1.0);

        self::assertCount(1, $fused);
        self::assertSame(1, $fused[0]['id']);
    }

    public function testADocumentOnlyTheMeaningFoundIsStillAHit(): void
    {
        $semantic = $this->candidates([0.0], 'media', false, 100);
        $semantic[0]['similarity'] = 0.8;
        $semantic[0]['snippet'] = 'Abschnitt';

        $fused = CandidateList::fuse($this->candidates([5.0]), $semantic, 1.0);

        self::assertCount(2, $fused);
        self::assertSame('Abschnitt', $fused[1]['snippet']);
    }

    /**
     * Die Relevanz misst sich am Erreichbaren -- Platz eins in beiden Listen --, nicht
     * am besten Treffer dieser Suche. Sonst stuende sie immer bei fast hundert Prozent:
     * die Punktzahlen der Verschmelzung liegen eng beieinander, und am Bestand gemessen
     * bekamen vier voellig verschiedene Treffer 100, 97, 96 und 95 Prozent.
     */
    public function testRelevanceIsMeasuredAgainstWhatIsAchievableNotAgainstTheBest(): void
    {
        $nurVolltext = CandidateList::fuse($this->candidates([5.0]), [], 1.0);
        self::assertEqualsWithDelta(0.5, $nurVolltext[0]['relevance'], 1e-9);

        $semantic = $this->candidates([0.0], 'article', false);
        $semantic[0]['similarity'] = 0.9;
        $beides = CandidateList::fuse($this->candidates([5.0]), $semantic, 1.0);
        self::assertEqualsWithDelta(1.0, $beides[0]['relevance'], 1e-9);
    }

    public function testFusedRelevanceNeverRisesDownTheList(): void
    {
        $keyword = $this->candidates([9.0, 8.0, 7.0, 6.0]);
        $semantic = $this->candidates([0.0, 0.0], 'media', false, 100);
        foreach ($semantic as $i => $_) {
            $semantic[$i]['similarity'] = 0.9 - $i / 10;
        }

        $vorher = 1.0;
        foreach (CandidateList::fuse($keyword, $semantic, 1.0) as $candidate) {
            self::assertLessThanOrEqual($vorher, $candidate['relevance']);
            $vorher = $candidate['relevance'];
        }
    }

    public function testTheThresholdStillWorksAfterFusion(): void
    {
        $keyword = $this->candidates([9.0, 8.0, 7.0]);
        // Dasselbe Dokument wie der erste Volltexttreffer -- Namensraum und item_id
        // sind der Schluessel, unter dem beide Listen dasselbe meinen.
        $semantic = $this->candidates([0.0], 'article', false);
        $semantic[0]['similarity'] = 0.9;

        $fused = CandidateList::fuse($keyword, $semantic, 1.0);

        // Nur der Treffer, den beide Wege gefunden haben, kommt ueber die Haelfte.
        self::assertCount(3, $fused);
        self::assertCount(1, CandidateList::dropBelowMinRelevance($fused, 0.6));
    }
}
