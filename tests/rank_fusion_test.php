<?php

use FriendsOfRedaxo\Search\Query\RankFusion;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_rank_fusion_test extends TestCase
{
    public function testAnEntryInBothListsBeatsOneInOnlyOne(): void
    {
        $scores = RankFusion::fuse([
            'volltext' => ['a', 'b'],
            'bedeutung' => ['b', 'c'],
        ]);

        self::assertSame(['b', 'a', 'c'], array_keys($scores));
    }

    /**
     * Der Kern des Verfahrens: verglichen werden Plaetze, keine Werte. Eine
     * Volltextbewertung von 453 und eine Kosinus-Aehnlichkeit von 0,68 lassen sich nicht
     * verrechnen, ihre Plaetze schon.
     */
    public function testOnlyThePositionCountsNotAnyValue(): void
    {
        $a = RankFusion::fuse(['x' => ['erst', 'zweit', 'dritt']]);
        $b = RankFusion::fuse(['y' => ['erst', 'zweit', 'dritt']]);

        self::assertSame($a, $b);
    }

    public function testTheDampingKeepsTheFirstPlaceFromDeciayingAlone(): void
    {
        $scores = RankFusion::fuse(['x' => ['erst', 'zehnt']]);

        // Mit k = 60 steht der erste Platz bei 1/61 und der zweite bei 1/62 -- knapp
        // zwei Prozent Unterschied. Ohne Daempfung waeren es fuenfzig.
        self::assertEqualsWithDelta(1 / 61, $scores['erst'], 1e-9);
        self::assertEqualsWithDelta(1 / 62, $scores['zehnt'], 1e-9);
    }

    public function testAListCanBeWeightedDown(): void
    {
        $scores = RankFusion::fuse(
            ['volltext' => ['a'], 'bedeutung' => ['b']],
            ['bedeutung' => 0.5],
        );

        self::assertGreaterThan($scores['b'], $scores['a']);
    }

    /**
     * Gewicht 0 heisst "nicht mitzaehlen" und nicht "mit Punktzahl 0 aufnehmen" --
     * sonst stuende ein Eintrag in der Liste, den niemand haben wollte.
     */
    public function testAListWeightedToZeroContributesNothingAtAll(): void
    {
        $scores = RankFusion::fuse(
            ['volltext' => ['a'], 'bedeutung' => ['b']],
            ['bedeutung' => 0.0],
        );

        self::assertSame(['a'], array_keys($scores));
    }

    public function testTheResultIsSortedByScoreDescending(): void
    {
        $scores = RankFusion::fuse(['x' => ['a', 'b', 'c', 'd'], 'y' => ['d', 'a', 'b', 'c']]);

        $werte = array_values($scores);
        $sortiert = $werte;
        rsort($sortiert);

        self::assertCount(4, $werte);
        self::assertSame($sortiert, $werte);
    }

    /**
     * Zwei Eintraege auf spiegelbildlichen Plaetzen kommen auf dieselbe Punktzahl. Dann
     * entscheidet die erste Liste -- die des Volltexts --, und nicht der Zufall der
     * Sortierung.
     */
    public function testATieIsBrokenByTheFirstList(): void
    {
        // c steht im Volltext vorn, a in der Bedeutung; beide kommen auf 1/61 + 1/63.
        $scores = RankFusion::fuse(['volltext' => ['c', 'b', 'a'], 'bedeutung' => ['a', 'b', 'c']]);

        self::assertEqualsWithDelta($scores['a'], $scores['c'], 1e-12);
        self::assertSame('c', array_key_first($scores));
    }

    public function testEmptyListsYieldNothing(): void
    {
        self::assertSame([], RankFusion::fuse([]));
        self::assertSame([], RankFusion::fuse(['x' => []]));
    }
}
