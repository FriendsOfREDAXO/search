<?php

use FriendsOfRedaxo\Search\Text;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_text_test extends TestCase
{
    public function testFromHtmlStripsMarkupAndNormalizesWhitespace(): void
    {
        $html = '<h1>Titel</h1><p>Erster&nbsp;Absatz <strong>fett</strong>.</p><script>alert(1)</script><ul><li>eins</li><li>zwei</li></ul>';

        self::assertSame("Titel\nErster Absatz fett.\neins\nzwei", Text::fromHtml($html));
    }

    public function testFromHtmlDecodesEntities(): void
    {
        self::assertSame('Müller & Söhne <GmbH>', Text::fromHtml('M&uuml;ller &amp; S&ouml;hne &lt;GmbH&gt;'));
    }

    public function testJoinSkipsEmptyParts(): void
    {
        self::assertSame("a\nb", Text::join(['a', '', null, '  ', 'b']));
    }

    public function testTruncate(): void
    {
        self::assertSame('abc', Text::truncate('abc', 3));
        self::assertSame('ab…', Text::truncate('abcd', 3));
    }
}
