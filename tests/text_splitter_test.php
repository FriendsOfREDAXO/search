<?php

use FriendsOfRedaxo\Search\Chunk\TextSplitter;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_text_splitter_test extends TestCase
{
    public function testAShortTextStaysOnePiece(): void
    {
        self::assertSame(['Ein kurzer Satz.'], TextSplitter::split('Ein kurzer Satz.', 1500, 200));
    }

    public function testNothingComesOutOfNothing(): void
    {
        self::assertSame([], TextSplitter::split("   \n\n  ", 1500, 200));
    }

    /**
     * Absaetze bleiben ganz, solange sie zusammen in die Zielgroesse passen. Das ist der
     * Unterschied zum Schneiden alle n Zeichen: dort faellt ein Absatz auch dann
     * auseinander, wenn gar kein Grund dazu besteht.
     */
    public function testParagraphsAreCollectedUntilTheSizeIsReached(): void
    {
        $text = implode("\n\n", [str_repeat('a', 300), str_repeat('b', 300), str_repeat('c', 300)]);

        self::assertCount(1, TextSplitter::split($text, 1000, 0));
        self::assertCount(3, TextSplitter::split($text, 300, 0));
    }

    public function testAParagraphIsNotTornApartForAFewCharacters(): void
    {
        // 520 Zeichen bei Zielgroesse 500: die Toleranz von einem Fuenftel haelt es
        // zusammen, statt den zweiten Absatz fuer zwanzig Zeichen abzutrennen.
        $text = str_repeat('a', 250) . "\n\n" . str_repeat('b', 268);

        self::assertCount(1, TextSplitter::split($text, 500, 0));
    }

    public function testALongParagraphIsCutAtSentenceEnds(): void
    {
        $satz = 'Dies ist ein vollstaendiger Satz mit genug Zeichen darin. ';
        $pieces = TextSplitter::split(trim(str_repeat($satz, 10)), 200, 0);

        self::assertGreaterThan(1, count($pieces));
        foreach ($pieces as $piece) {
            self::assertStringEndsWith('.', $piece);
        }
    }

    /**
     * Eine Tabellenzeile aus einem PDF hat kein Satzende. Ohne die harte Trennung liefe
     * das Stueck ueber die Zielgroesse hinaus, bis der Absatz zu Ende ist.
     */
    public function testAVeryLongSentenceWithoutPunctuationIsStillCut(): void
    {
        $text = trim(str_repeat('wort ', 200));
        $pieces = TextSplitter::split($text, 300, 0);

        self::assertGreaterThan(2, count($pieces));
        foreach ($pieces as $piece) {
            self::assertLessThanOrEqual(300, mb_strlen($piece));
        }
    }

    public function testTheOverlapCarriesTheEndOfThePreviousPieceIntoTheNext(): void
    {
        $text = str_repeat('a', 400) . "\n\n" . 'Der letzte Satz des ersten Stuecks.' . "\n\n" . str_repeat('b', 400);
        $pieces = TextSplitter::split($text, 450, 60);

        self::assertGreaterThan(1, count($pieces));
        self::assertStringContainsString('Stuecks.', $pieces[1]);
    }

    public function testWithoutOverlapNothingIsRepeated(): void
    {
        $text = str_repeat('a', 400) . "\n\n" . 'Der letzte Satz des ersten Stuecks.' . "\n\n" . str_repeat('b', 400);
        $pieces = TextSplitter::split($text, 450, 0);

        self::assertStringNotContainsString('Stuecks.', $pieces[1]);
    }

    /**
     * Mehr Ueberlappung als die halbe Groesse hiesse, dass jedes Stueck zur Haelfte aus
     * dem vorigen besteht -- das vervielfacht die Abschnitte, ohne mehr Zusammenhang.
     */
    public function testTheOverlapIsCappedAtHalfTheSize(): void
    {
        $text = trim(str_repeat('wort ', 400));

        self::assertSame(
            TextSplitter::split($text, 400, 200),
            TextSplitter::split($text, 400, 2000),
        );
    }

    public function testUmlautsAreCountedAsCharactersNotBytes(): void
    {
        $pieces = TextSplitter::split(trim(str_repeat('üäöß ', 100)), 300, 0);

        foreach ($pieces as $piece) {
            self::assertLessThanOrEqual(300, mb_strlen($piece));
            self::assertSame($piece, mb_convert_encoding($piece, 'UTF-8', 'UTF-8'));
        }
    }
}
