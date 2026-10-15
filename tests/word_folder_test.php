<?php

use FriendsOfRedaxo\Search\Word\WordFolder;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_word_folder_test extends TestCase
{
    public function testFoldingMakesByteBasedLevenshteinUsableForGerman(): void
    {
        // Ohne Faltung zählt levenshtein() Bytes: "Bürger" gegen "Burger" ergibt 2 statt 1,
        // gegen "Burgar" sogar 3 — ein Tippfehler auf einem Umlaut fiele durch.
        self::assertSame(2, levenshtein('bürger', 'burger'));
        self::assertSame(1, levenshtein(WordFolder::fold('Bürger'), WordFolder::fold('Burgar')));
    }

    public function testFoldingLowercasesAndRemovesDiacritics(): void
    {
        self::assertSame('burgruine', WordFolder::fold('Burgruine'));
        self::assertSame('grosse', WordFolder::fold('Größe'));
        self::assertSame('cafe', WordFolder::fold('Café'));
    }

    public function testTokenizeKeepsOnlyWordsOfUsableLength(): void
    {
        self::assertSame(['Die', 'Burg', 'Eppstein'], WordFolder::tokenize('Die Burg zu Eppstein!'));
    }

    public function testTokenizeSplitsAtHyphenSoBothPartsAreFindable(): void
    {
        self::assertSame(['Burg', 'Ruine'], WordFolder::tokenize('Burg-Ruine'));
    }

    public function testTokenizeReturnsEachWordOnce(): void
    {
        self::assertSame(['Burg'], WordFolder::tokenize('Burg burg BURG'));
    }

    public function testTokenizePrefersAMixedCaseSpellingOverAllCaps(): void
    {
        // PDF-Überschriften bestehen aus Versalien; als Vorschlag sähe das falsch aus.
        self::assertSame(['Eppstein'], WordFolder::tokenize('EPPSTEIN Eppstein'));
    }

    public function testTokenizeKeepsAnAcronymThatOnlyEverAppearsInCaps(): void
    {
        self::assertSame(['PDF'], WordFolder::tokenize('PDF PDF'));
    }

    public function testTokenizeIgnoresWordsThatAreTooLong(): void
    {
        self::assertSame([], WordFolder::tokenize(str_repeat('a', WordFolder::MAX_LENGTH + 1)));
    }

    public function testEmptyTextYieldsNoWords(): void
    {
        self::assertSame([], WordFolder::tokenize('   ,.;  '));
    }
}
