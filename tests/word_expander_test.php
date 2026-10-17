<?php

use FriendsOfRedaxo\Search\Analysis\Analyzer;
use FriendsOfRedaxo\Search\Word\WordExpander;
use FriendsOfRedaxo\Search\Word\WordFolder;
use FriendsOfRedaxo\Search\Word\WordFormRepository;
use FriendsOfRedaxo\Search\Word\WordRepository;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_word_expander_test extends TestCase
{
    protected function setUp(): void
    {
        Analyzer::setCacheDirectory(sys_get_temp_dir() . '/rex-search-test-dictionaries');
    }

    /**
     * Wortliste und Formtabelle im Speicher statt in der Datenbank.
     *
     * @param array<string, int> $words Wort auf Dokumentzahl
     * @param array<string, array<string, int>> $forms Form auf Wort auf Dokumentzahl
     * @param list<string> $languages Sprachen, zu denen Formen vorliegen
     */
    private function expander(array $words, array $forms = [], array $languages = ['de']): WordExpander
    {
        $repository = new class($words) extends WordRepository {
            /** @param array<string, int> $words */
            public function __construct(
                private array $words,
            ) {}

            public function findByInfix(string $part, array $sourceIds = [], array $languages = [], int $limit = 10): array
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

            public function findByLength(int $min, int $max, array $sourceIds = [], array $languages = [], int $limit = 20000): array
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

        $formRepository = new class($forms, $languages) extends WordFormRepository {
            /**
             * @param array<string, array<string, int>> $forms
             * @param list<string> $languages
             */
            public function __construct(
                private array $forms,
                private array $languages,
            ) {}

            public function languages(): array
            {
                return $this->languages;
            }

            public function findByForms(array $forms, array $sourceIds = [], array $languages = [], int $limit = 20): array
            {
                $found = [];
                foreach ($forms as $form) {
                    foreach ($this->forms[$form] ?? [] as $word => $docs) {
                        $found[] = ['word' => (string) $word, 'docs' => $docs];
                    }
                }
                usort($found, static fn (array $a, array $b): int => $b['docs'] <=> $a['docs']);

                return array_slice($found, 0, $limit);
            }
        };

        return new WordExpander($repository, $formRepository);
    }

    /**
     * Die Analyse liefert, was gemeint ist, die Suche nach enthaltenen Teilen alles, was
     * die Zeichenfolge enthaelt. Hier steht beides nebeneinander: "Burgruine" kommt ueber
     * den Stamm "ruin", "Ruinenbegeisterung" nur ueber den Teilstring -- und zwar danach.
     */
    public function testAnalysedFormsComeFirstAndTheSubstringSearchFillsUp(): void
    {
        $expander = $this->expander(
            ['Ruine' => 4, 'Burgruine' => 3, 'Ruinenbegeisterung' => 9],
            ['ruin' => ['Burgruine' => 3]],
        );

        // Ohne die Analyse stuende das haeufigere "Ruinenbegeisterung" vorn -- es faellt
        // aber unter "+Ruine*" und wird gar nicht erst aufgezaehlt.
        self::assertSame(['burgruine'], $expander->expand('Ruine'));
    }

    public function testTheSubstringSearchStillBringsWhatTheAnalysisMisses(): void
    {
        // Gemessen: das Woerterbuch kennt "Ganerben" nicht und laesst "Ganerbenburg"
        // deshalb ganz. Ohne das Auffuellen ginge der Treffer verloren.
        $expander = $this->expander(['Burg' => 9, 'Ganerbenburg' => 2], ['burg' => ['Burg' => 9]]);

        self::assertSame(['ganerbenburg'], $expander->expand('Burg'));
    }

    public function testAnalysedFormsPushOutTheSubstringFindingsAtTheCap(): void
    {
        $words = ['Xruine1' => 50, 'Xruine2' => 40];
        $forms = ['ruin' => ['Burgruine' => 1]];

        // Obwohl die Teilstring-Treffer um ein Vielfaches haeufiger sind, bleibt bei
        // einer Form Platz nur fuer die analysierte.
        self::assertSame(['burgruine'], $this->expander($words, $forms)->expand('Ruine', [], [], 1));
    }

    /**
     * Eine Sprache, zu der es keine Analyse gibt, darf nicht dazu fuehren, dass gar nichts
     * mehr erweitert wird: dann gilt das Verhalten von vorher.
     */
    public function testWithoutAnAnalysisTheSubstringSearchAloneIsUsed(): void
    {
        $expander = $this->expander(['Ruine' => 4, 'Burgruine' => 3], [], []);

        self::assertSame(['burgruine'], $expander->expand('Ruine'));
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

    /**
     * Quellen ohne Sprachangabe gehoeren in jede sprachbezogene Auswahl. Sonst fielen
     * sie aus jeder Suche, die nach Sprache filtert -- und auf einer Installation, auf
     * der niemand eine Sprache eingetragen hat, bliebe die Wortliste wirkungslos.
     */
    public function testSourcesWithoutALanguageAreAlwaysIncluded(): void
    {
        self::assertSame(['de', ''], WordRepository::withUnknown(['de']));
        self::assertSame(['de', 'en', ''], WordRepository::withUnknown(['de', 'en']));
    }

    public function testTheUnknownLanguageIsNotAddedTwice(): void
    {
        self::assertSame(['de', ''], WordRepository::withUnknown(['de', '']));
        self::assertSame([''], WordRepository::withUnknown(['']));
    }
}
