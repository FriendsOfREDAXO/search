<?php

use FriendsOfRedaxo\Search\Passage\Passage;
use FriendsOfRedaxo\Search\Passage\PassageQuery;
use FriendsOfRedaxo\Search\Passage\PassageResult;
use FriendsOfRedaxo\Search\Passage\PassageSelection;
use FriendsOfRedaxo\Search\Vector\Similarity;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_passage_test extends TestCase
{
    private function passage(float $similarity, string $text = 'Text', string $path = 'Pfad', int $no = 0, int $total = 1): Passage
    {
        return new Passage(1, 2, 19, 'article', 'fullcontent', '42-1', 'Titel', '/url', 'de', $path, $text, $no, $total, $similarity);
    }

    /**
     * Die wichtigste Zahl der ganzen Schnittstelle. Eine Nachbarsuche liefert immer die
     * naechsten Abschnitte, auch zu einer Frage, zu der nichts dasteht -- ohne Schwelle
     * bekaeme ein Sprachmodell zu jeder Frage einen Kontext und baute daraus etwas.
     *
     * Gemessen mit zwoelf Fragen gegen denselben Bestand und text-embedding-3-small:
     * beantwortbare Fragen erreichten 0,409 bis 0,741, Fragen zu Themen, die gar nicht
     * vorkommen, hoechstens 0,393.
     */
    public function testPassagesBelowTheThresholdAreDropped(): void
    {
        $passages = [$this->passage(0.72), $this->passage(0.41, 'B'), $this->passage(0.393, 'C')];
        $query = PassageQuery::create('Frage');

        self::assertSame(Similarity::DEFAULT_MINIMUM, $query->getMinSimilarity());
        self::assertCount(2, PassageSelection::select($passages, $query));
    }

    public function testWithoutAThresholdEvenTheFarthestPassageComesAlong(): void
    {
        $passages = [$this->passage(0.3), $this->passage(0.1, 'B')];

        self::assertCount(2, PassageSelection::select($passages, PassageQuery::create('F')->withMinSimilarity(0.0)));
    }

    /**
     * Erfassen zwei Quellen dieselbe Datei, steht derselbe Text zweimal im Index. Im
     * Kontext saehe das aus wie die Bestaetigung durch eine zweite Quelle.
     */
    public function testTheSameTextIsNotHandedOverTwice(): void
    {
        $passages = [$this->passage(0.8, 'Gleicher Text'), $this->passage(0.7, 'Gleicher Text'), $this->passage(0.6, 'Anderer')];

        $selected = PassageSelection::select($passages, PassageQuery::create('F'));

        self::assertCount(2, $selected);
        self::assertSame(['Gleicher Text', 'Anderer'], array_map(static fn (Passage $p): string => $p->text, $selected));
    }

    public function testTheNumberOfPassagesIsCapped(): void
    {
        $passages = array_map(fn (int $i): Passage => $this->passage(0.9, 'Text ' . $i), range(1, 10));

        self::assertCount(3, PassageSelection::select($passages, PassageQuery::create('F')->withMaxPassages(3)));
    }

    /**
     * Eine Passage, die nicht mehr ganz hineinpasst, faellt weg statt gekuerzt zu
     * werden: ein abgeschnittener Satz ist schlimmer als ein fehlender Absatz, weil ein
     * Modell ihn trotzdem zu Ende denkt.
     */
    public function testAPassageThatNoLongerFitsIsLeftOutWholeNotCut(): void
    {
        $passages = [$this->passage(0.9, str_repeat('a', 400)), $this->passage(0.8, str_repeat('b', 400)), $this->passage(0.7, 'kurz')];

        $selected = PassageSelection::select($passages, PassageQuery::create('F')->withMaxCharacters(500));

        // Die zweite passt nicht mehr, die dritte schon -- uebersprungen wird, nicht
        // abgebrochen.
        self::assertSame([400, 4], array_map(static fn (Passage $p): int => $p->getCharacters(), $selected));
    }

    /**
     * Sonst bliebe bei einer knappen Grenze gar nichts uebrig, und der Aufrufer bekaeme
     * eine leere Antwort auf eine Frage, zu der sehr wohl etwas dasteht.
     */
    public function testTheBestPassageComesAlongEvenIfItExceedsTheLimitAlone(): void
    {
        $passages = [$this->passage(0.9, str_repeat('a', 5000))];

        self::assertCount(1, PassageSelection::select($passages, PassageQuery::create('F')->withMaxCharacters(1000)));
    }

    public function testEligibleCountsWhatThePassedTheThresholdNotWhatFitted(): void
    {
        $passages = [$this->passage(0.9, 'A'), $this->passage(0.8, 'B'), $this->passage(0.2, 'C')];

        self::assertSame(2, PassageSelection::countEligible($passages, 0.5));
        self::assertSame(3, PassageSelection::countEligible($passages, 0.0));
        self::assertSame(0, PassageSelection::countEligible($passages, 0.95));
    }

    public function testTheLabelNamesTheHeadingPathAndThePosition(): void
    {
        self::assertSame('Burg › Öffnungszeiten (2/5)', $this->passage(0.9, 'T', 'Burg › Öffnungszeiten', 1, 5)->getLabel());
        self::assertSame('Titel', $this->passage(0.9, 'T', '')->getLabel());
    }

    public function testThePromptContextNumbersThePassagesSoAModelCanCiteThem(): void
    {
        $result = new PassageResult([$this->passage(0.9, 'Erster', 'A'), $this->passage(0.8, 'Zweiter', 'B')], PassageQuery::create('F'));

        self::assertSame("[1] A\nErster\n\n[2] B\nZweiter", $result->toPromptContext());
        self::assertSame(13, $result->getCharacters());
    }

    public function testAnEmptyResultIsEmptyAndSaysHowCloseItWas(): void
    {
        $result = PassageResult::empty(PassageQuery::create('F'), 0.497);

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->count());
        self::assertSame('', $result->toPromptContext());
        self::assertSame(0.497, $result->bestSimilarity);
    }

    public function testTheQueryHoldsItsValuesWithinBounds(): void
    {
        $query = PassageQuery::create('F')->withMinSimilarity(5.0)->withMaxPassages(0)->withMaxCharacters(1);

        self::assertSame(1.0, $query->getMinSimilarity());
        self::assertSame(1, $query->getMaxPassages());
        self::assertSame(200, $query->getMaxCharacters());
        self::assertSame(0.0, PassageQuery::create('F')->withMinSimilarity(-1.0)->getMinSimilarity());
    }

    public function testFiltersAreNamedWithTheirTableSoTheJoinStaysUnambiguous(): void
    {
        $query = PassageQuery::create('F')->withSourceIds(19, 20)->withLanguages('de_de')->withNamespaces('article');

        self::assertSame([
            'v.source_id' => [19, 20],
            'i.namespace' => ['article'],
            'i.language' => ['de'],
        ], $query->getFilters());
    }

    public function testWithoutFiltersNothingIsRestricted(): void
    {
        self::assertSame([], PassageQuery::create('F')->getFilters());
    }
}
