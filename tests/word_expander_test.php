<?php

use FriendsOfRedaxo\Search\Word\WordExpander;
use FriendsOfRedaxo\Search\Word\WordFolder;
use FriendsOfRedaxo\Search\Word\WordRepository;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_word_expander_test extends TestCase
{
    /**
     * Wortliste im Speicher statt in der Datenbank.
     *
     * @param array<string, int> $words Wort auf Dokumentzahl
     */
    private function expander(array $words): WordExpander
    {
        $repository = new class($words) extends WordRepository {
            /** @param array<string, int> $words */
            public function __construct(
                private array $words,
            ) {}

            public function findByInfix(string $part, array $sourceIds = [], array $clangIds = [], int $limit = 10): array
            {
                $needle = WordFolder::fold($part);
                $found = [];
                foreach ($this->words as $word => $docs) {
                    if (str_contains(WordFolder::fold($word), $needle)) {
                        $found[] = ['word' => $word, 'docs' => $docs];
                    }
                }
                usort($found, static fn (array $a, array $b): int => $b['docs'] <=> $a['docs']);

                return array_slice($found, 0, $limit);
            }

            public function findByLength(int $min, int $max, array $sourceIds = [], array $clangIds = [], int $limit = 20000): array
            {
                $found = [];
                foreach ($this->words as $word => $docs) {
                    if (mb_strlen((string) $word) >= $min && mb_strlen((string) $word) <= $max) {
                        $found[] = ['word' => (string) $word, 'folded' => WordFolder::fold((string) $word), 'docs' => $docs];
                    }
                }

                return $found;
            }
        };

        return new WordExpander($repository);
    }

    public function testAWordInTheMiddleIsFoundWhichThePrefixSearchCannotDo(): void
    {
        $expander = $this->expander(['Ruine' => 4, 'Burgruine' => 3, 'Ruinen' => 2]);

        // "Ruinen" faellt weg: das findet "+Ruine*" ohnehin schon.
        self::assertSame(['burgruine'], $expander->expand('Ruine'));
    }

    public function testTheWordItselfIsNotListedAsItsOwnExpansion(): void
    {
        self::assertSame([], $this->expander(['Ruine' => 4])->expand('Ruine'));
    }

    public function testShortWordsAreNotExpandedAtAll(): void
    {
        self::assertSame([], $this->expander(['Burg' => 9, 'Einbuergerung' => 2])->expand('urg'));
    }

    public function testExpansionIsCappedAndTakesTheMostFrequentFirst(): void
    {
        $words = ['Ruine' => 1];
        foreach (range(1, 12) as $i) {
            $words['Xruine' . $i] = $i;
        }

        $forms = $this->expander($words)->expand('Ruine', [], [], 3);

        self::assertCount(3, $forms);
        self::assertSame(['xruine12', 'xruine11', 'xruine10'], $forms);
    }

    public function testATypoIsCorrectedToTheExistingSpelling(): void
    {
        self::assertSame('Burgruine', $this->expander(['Burgruine' => 3, 'Burgfest' => 2])->correct('Burgruihne'));
    }

    public function testCorrectionComparesFoldedSoAnUmlautTypoCostsOneStepNotTwo(): void
    {
        self::assertSame('Bürger', $this->expander(['Bürger' => 5])->correct('Burgar'));
    }

    public function testAWordThatExistsIsNotCorrected(): void
    {
        self::assertNull($this->expander(['Burgruine' => 3, 'Burgruinen' => 1])->correct('Burgruine'));
    }

    public function testShortWordsAreNeverCorrected(): void
    {
        // Gemessen: bei vier Zeichen traefe Abstand 2 fast alles, "burg" wurde zu "fuer".
        self::assertNull($this->expander(['für' => 113, 'nur' => 48, 'Berg' => 3])->correct('burg'));
        self::assertSame(0, WordExpander::maxDistance('burg'));
    }

    public function testTheAllowedDistanceGrowsWithTheWordLength(): void
    {
        self::assertSame(0, WordExpander::maxDistance('abcd'));
        self::assertSame(1, WordExpander::maxDistance('abcde'));
        self::assertSame(1, WordExpander::maxDistance('abcdefg'));
        self::assertSame(2, WordExpander::maxDistance('abcdefgh'));
    }

    public function testOfTwoEquallyCloseWordsTheMoreFrequentWins(): void
    {
        // Das seltenere Wort steht bewusst vorn: sonst gewinnt es schon dadurch, dass es
        // zuerst geprueft wird, und der Vergleich der Haeufigkeit bliebe ungeprueft.
        self::assertSame('Espresso', $this->expander(['Espressi' => 1, 'Espresso' => 9])->correct('Espressa'));
    }

    public function testNothingCloseEnoughYieldsNoCorrection(): void
    {
        self::assertNull($this->expander(['Weltraum' => 5])->correct('Slackline'));
    }
}
