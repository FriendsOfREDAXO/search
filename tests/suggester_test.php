<?php

use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Suggest\Suggester;
use FriendsOfRedaxo\Search\Suggest\Suggestion;
use FriendsOfRedaxo\Search\Word\WordFolder;
use FriendsOfRedaxo\Search\Word\WordRepository;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_suggester_test extends TestCase
{
    /** @var list<string> Die Suchbegriffe, die geprueft wurden */
    private array $checked = [];

    /**
     * Wortliste im Speicher; jede Suche hat Treffer, ausser fuer die Begriffe in $empty.
     *
     * @param array<string, int> $words Wort auf Dokumentzahl
     * @param list<string> $empty
     */
    private function suggester(array $words, array $empty = []): Suggester
    {
        $repository = new class($words) extends WordRepository {
            /** @param array<string, int> $words */
            public function __construct(
                private array $words,
            ) {}

            public function findByPrefix(string $prefix, array $sourceIds = [], array $languages = [], int $limit = 10): array
            {
                $needle = WordFolder::fold($prefix);

                return $this->rows(static fn (string $folded): bool => str_starts_with($folded, $needle), $limit);
            }

            public function findByInfix(string $part, array $sourceIds = [], array $languages = [], int $limit = 10): array
            {
                $needle = WordFolder::fold($part);

                return $this->rows(static fn (string $folded): bool => str_contains($folded, $needle), $limit);
            }

            public function findByLength(int $min, int $max, array $sourceIds = [], array $languages = [], int $limit = 20000): array
            {
                $found = [];
                foreach ($this->words as $word => $docs) {
                    $length = mb_strlen((string) $word);
                    if ($length >= $min && $length <= $max) {
                        $found[] = ['word' => (string) $word, 'folded' => WordFolder::fold((string) $word), 'docs' => $docs];
                    }
                }

                return $found;
            }

            /** @return list<array{word: string, docs: int}> */
            private function rows(Closure $matches, int $limit): array
            {
                $found = [];
                foreach ($this->words as $word => $docs) {
                    if ($matches(WordFolder::fold((string) $word))) {
                        $found[] = ['word' => (string) $word, 'docs' => $docs];
                    }
                }
                usort($found, static fn (array $a, array $b): int => $b['docs'] <=> $a['docs']);

                return array_slice($found, 0, $limit);
            }
        };

        $this->checked = [];

        return new Suggester($repository, function (SearchQuery $query) use ($empty): bool {
            $this->checked[] = $query->getTerm();

            return !in_array($query->getTerm(), $empty, true);
        });
    }

    /**
     * @param list<Suggestion> $suggestions
     * @return list<string>
     */
    private static function values(array $suggestions): array
    {
        return array_map(static fn (Suggestion $suggestion): string => $suggestion->value, $suggestions);
    }

    private static function scope(): SearchQuery
    {
        return SearchQuery::create('')->withLanguages('de');
    }

    public function testCompletesTheLastWordAndMarksWhatWasTyped(): void
    {
        $suggestions = $this->suggester(['Wärmepumpe' => 30, 'Wärmeplanung' => 10])->suggest('Wärmep', self::scope());

        self::assertSame(['Wärmepumpe', 'Wärmeplanung'], self::values($suggestions));
        self::assertSame([['text' => 'Wärmep', 'typed' => true], ['text' => 'umpe', 'typed' => false]], $suggestions[0]->parts);
    }

    public function testKeepsTheWordsBeforeAndChecksThemTogether(): void
    {
        $suggestions = $this->suggester(['Wärmepumpe' => 30])->suggest('Förderung wärmep', self::scope());

        self::assertSame(['Förderung Wärmepumpe'], self::values($suggestions));
        self::assertSame(['text' => 'Förderung ', 'typed' => true], $suggestions[0]->parts[0]);
        self::assertSame(['Förderung Wärmepumpe'], $this->checked);
    }

    public function testATrailingSpaceMeansTheWordIsFinished(): void
    {
        self::assertSame([], $this->suggester(['Wärmepumpe' => 30])->suggest('Wärmep ', self::scope()));
        self::assertSame([], $this->checked);
    }

    public function testOneLetterIsNotEnough(): void
    {
        self::assertSame([], $this->suggester(['Wärmepumpe' => 30])->suggest('W', self::scope()));
    }

    public function testASuggestionWithoutHitsIsDropped(): void
    {
        // Beide Woerter stehen in der Liste, aber nicht im selben Dokument wie "Förderung"
        $suggestions = $this->suggester(['Wärmepumpe' => 30, 'Wärmeplanung' => 10], ['Förderung Wärmepumpe'])
            ->suggest('Förderung wärmep', self::scope());

        self::assertSame(['Förderung Wärmeplanung'], self::values($suggestions));
    }

    public function testInflectedFormsOfTheSameWordAppearOnce(): void
    {
        $suggestions = $this->suggester(['Wärmepumpen' => 40, 'Wärmepumpe' => 30, 'Wärmepumpenförderung' => 5])
            ->suggest('wärmep', self::scope());

        self::assertSame(['Wärmepumpen', 'Wärmepumpenförderung'], self::values($suggestions));
    }

    public function testTheTypedWordItselfIsNotSuggested(): void
    {
        self::assertSame(['Solaranlage'], self::values($this->suggester(['Solar' => 50, 'Solaranlage' => 9])->suggest('Solar', self::scope())));
    }

    public function testFunctionWordsOfTheLanguageAreLeftOut(): void
    {
        $suggestions = $this->suggester(['dass' => 500, 'damit' => 400, 'Dachdämmung' => 7])->suggest('da', self::scope());

        self::assertSame(['Dachdämmung'], self::values($suggestions));
    }

    public function testWithoutALanguageAllFunctionWordListsApply(): void
    {
        $suggestions = $this->suggester(['there' => 500, 'Thermostat' => 7])->suggest('the', SearchQuery::create(''));

        self::assertSame(['Thermostat'], self::values($suggestions));
    }

    public function testATypedUmlautIsMeant(): void
    {
        // Gefaltet beginnen beide mit "wa"; wer "wä" tippt, meint nicht "Wasser"
        $suggestions = $this->suggester(['Wasser' => 90, 'Wärme' => 20])->suggest('wä', self::scope());

        self::assertSame(['Wärme'], self::values($suggestions));
    }

    public function testCapitalizedWordsComeFirst(): void
    {
        $suggestions = $this->suggester(['fördert' => 90, 'Förderung' => 20])->suggest('förd', self::scope());

        self::assertSame(['Förderung', 'fördert'], self::values($suggestions));
    }

    public function testNumbersAndRunTogetherWordsAreNotSuggested(): void
    {
        $suggestions = $this->suggester(['35644' => 90, 'PumpeFinden' => 50, 'Pumpe' => 5])->suggest('Pu', self::scope());

        self::assertSame(['Pumpe'], self::values($suggestions));
        self::assertSame([], $this->suggester(['35644' => 90])->suggest('356', self::scope()));
    }

    public function testFindsTheTypedPartInsideAWord(): void
    {
        $suggestions = $this->suggester(['Luftwärmepumpe' => 12])->suggest('pumpe', self::scope());

        self::assertSame(['Luftwärmepumpe'], self::values($suggestions));
        self::assertSame([
            ['text' => 'Luftwärme', 'typed' => false],
            ['text' => 'pumpe', 'typed' => true],
        ], $suggestions[0]->parts);
    }

    public function testATypoInTheBeginningIsForgivenWhenNothingElseMatches(): void
    {
        $suggestions = $this->suggester(['Energieberatung' => 30, 'Energie' => 90])->suggest('Energiber', self::scope());

        self::assertSame(['Energieberatung'], self::values($suggestions));
        self::assertSame([['text' => 'Energieberatung', 'typed' => false]], $suggestions[0]->parts);
    }

    public function testAnotherWordIsNotATypo(): void
    {
        // "Heizöl" und "Heizl" liegen einen Buchstaben auseinander, aber getippt endet es auf "öl"
        self::assertSame([], $this->suggester(['Heizlast' => 30])->suggest('Heizöl', self::scope()));
    }

    public function testNoTypoCorrectionOnceSomethingMatched(): void
    {
        $suggestions = $this->suggester(['Heizkosten' => 30, 'Heizkasten' => 5])->suggest('Heizkos', self::scope());

        self::assertSame(['Heizkosten'], self::values($suggestions));
    }

    public function testTheLimitIsKept(): void
    {
        $words = [];
        foreach (range(1, 20) as $i) {
            $words['Solar' . str_repeat('x', $i)] = 100 - $i;
        }

        self::assertCount(3, $this->suggester($words)->suggest('Sol', self::scope(), 3));
    }

    public function testFoldingChangesTheLengthButNotWhereTheTypedPartEnds(): void
    {
        // "ß" ist gefaltet "ss": "Stras" endet in "Straße" mit dem "ß"
        $suggestion = Suggestion::fromWord('', 'Straße', 0, WordFolder::fold('Stras'));

        self::assertSame([['text' => 'Straß', 'typed' => true], ['text' => 'e', 'typed' => false]], $suggestion->parts);
    }

    public function testTheArrayFormIsWhatTheEndpointSends(): void
    {
        self::assertSame(
            ['value' => 'Burg Eppstein', 'parts' => [['text' => 'Burg ', 'typed' => true], ['text' => 'Eppstein', 'typed' => false]]],
            Suggestion::replacing('Burg ', 'Eppstein')->toArray(),
        );
    }
}
