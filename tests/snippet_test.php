<?php

use FriendsOfRedaxo\Search\Query\Snippet;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_snippet_test extends TestCase
{
    public function testWindowBecomesASingleLineWithoutMarkdownWhitespace(): void
    {
        $snippet = Snippet::fromWindow("## Die Burg\n\nErstmals   1122\nerwähnt.", false);

        self::assertSame('## Die Burg Erstmals 1122 erwähnt.', $snippet);
    }

    public function testCutWindowGetsALeadingEllipsisAndLosesTheBrokenFirstWord(): void
    {
        $snippet = Snippet::fromWindow('aunus liegt die Burg', true);

        self::assertStringStartsWith('… liegt die Burg', $snippet);
        self::assertStringNotContainsString('aunus', $snippet);
    }

    public function testLongTextIsCutAtAWordBoundary(): void
    {
        $text = str_repeat('Eppstein ', 60);

        $snippet = Snippet::fromWindow($text, false, 50);

        self::assertStringEndsWith(' …', $snippet);
        self::assertStringNotContainsString('Eppst …', $snippet);
        self::assertLessThanOrEqual(52, mb_strlen($snippet));
    }

    public function testShortTextIsReturnedUnchanged(): void
    {
        self::assertSame('Die Burg', Snippet::fromWindow('Die Burg', false, 200));
    }

    public function testHighlightMarksTheWordAndItsPrefixMatches(): void
    {
        $html = Snippet::highlight('Die Burg und die Burgmauer', ['burg']);

        self::assertSame('Die <mark>Burg</mark> und die <mark>Burgmauer</mark>', $html);
    }

    public function testHighlightDoesNotMarkInsideAWord(): void
    {
        $html = Snippet::highlight('Vorburg und Burg', ['burg']);

        self::assertSame('Vorburg und <mark>Burg</mark>', $html);
    }

    public function testHighlightEscapesBeforeMarking(): void
    {
        $html = Snippet::highlight('<script>burg</script>', ['burg']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('<mark>burg</mark>', $html);
    }

    public function testHighlightWithoutWordsStillEscapes(): void
    {
        self::assertSame('&lt;b&gt;', Snippet::highlight('<b>', []));
    }
}
