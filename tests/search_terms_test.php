<?php

use FriendsOfRedaxo\Search\Query\SearchTerms;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_search_terms_test extends TestCase
{
    public function testEachWordBecomesAMandatoryPrefix(): void
    {
        self::assertSame('+Burg* +Eppstein*', (new SearchTerms(['Burg', 'Eppstein']))->expression);
    }

    public function testAnExpandedWordBecomesAGroupThatOnlyWidens(): void
    {
        $terms = (new SearchTerms(['Ruine']))->withExpansions(['Ruine' => ['burgruine', 'ruinenbegeisterung']]);

        self::assertSame('+(Ruine* burgruine ruinenbegeisterung)', $terms->expression);
    }

    public function testOnlyTheExpandedWordGetsAGroup(): void
    {
        $terms = (new SearchTerms(['Ruine', 'Eppstein']))->withExpansions(['Ruine' => ['burgruine']]);

        self::assertSame('+(Ruine* burgruine) +Eppstein*', $terms->expression);
    }

    public function testHighlightingCoversTheExpandedFormsToo(): void
    {
        $terms = (new SearchTerms(['Ruine']))->withExpansions(['Ruine' => ['burgruine']]);

        self::assertSame(['Ruine', 'burgruine'], $terms->getHighlightWords());
    }

    public function testHighlightingListsEachFormOnce(): void
    {
        $terms = (new SearchTerms(['Ruine', 'Burg']))->withExpansions(['Ruine' => ['burg'], 'Burg' => ['burg']]);

        self::assertSame(['Ruine', 'Burg', 'burg'], $terms->getHighlightWords());
    }

    public function testCorrectedWordsKeepTheReasonsButLoseTheExpansions(): void
    {
        $terms = (new SearchTerms(['Eppstien'], ['und' => SearchTerms::REASON_STOPWORD]))
            ->withExpansions(['Eppstien' => ['irgendwas']]);

        $corrected = $terms->withWords(['Eppstein']);

        self::assertSame('+Eppstein*', $corrected->expression);
        self::assertSame(['und' => SearchTerms::REASON_STOPWORD], $corrected->ignored);
        self::assertSame([], $corrected->expansions);
    }

    public function testWithoutWordsTheExpressionIsEmpty(): void
    {
        self::assertTrue((new SearchTerms([]))->isEmpty());
    }

    public function testAnExtensionMayProvideItsOwnExpression(): void
    {
        $terms = new SearchTerms(['Burg'], [], [], '+Burg* +Schloss*');

        self::assertSame('+Burg* +Schloss*', $terms->expression);
    }

    public function testAnExpandedExpressionKnowsItsPlainForm(): void
    {
        $terms = new SearchTerms(['ruine'], [], ['ruine' => ['burgruine']]);

        self::assertTrue($terms->isExpanded());
        self::assertSame('+(ruine* burgruine)', $terms->expression);
        self::assertSame('+ruine*', $terms->getPlainExpression());
    }

    public function testWithoutFormsTheExpressionIsAlreadyThePlainOne(): void
    {
        $terms = new SearchTerms(['ruine', 'burg']);

        self::assertFalse($terms->isExpanded());
        self::assertSame($terms->expression, $terms->getPlainExpression());
    }

    /**
     * Ein leerer Eintrag ist keine Erweiterung. Sonst baute die Suche eine zweite,
     * ueberfluessige Volltextabfrage auf, nur um festzustellen, dass alle Treffer direkte
     * sind.
     */
    public function testAnEmptyFormListDoesNotCountAsExpanded(): void
    {
        self::assertFalse((new SearchTerms(['ruine'], [], ['ruine' => []]))->isExpanded());
    }
}
