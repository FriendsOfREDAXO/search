<?php

use FriendsOfRedaxo\Search\Source\Type\ArticleFrontend;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_article_frontend_test extends TestCase
{
    public function testExtractReturnsMarkedContentOnly(): void
    {
        $html = '<nav>Menue</nav>'
            . self::mark(5, 1, '<h1>Teich anlegen</h1><p>Folie auslegen.</p>')
            . '<footer>Impressum</footer>';

        self::assertSame('<h1>Teich anlegen</h1><p>Folie auslegen.</p>', ArticleFrontend::extract($html, 5, 1));
    }

    public function testExtractJoinsSeveralMarkedParts(): void
    {
        $html = self::mark(5, 1, '<p>Spalte links</p>') . '<aside>Werbung</aside>' . self::mark(5, 1, '<p>Spalte rechts</p>');

        self::assertSame("<p>Spalte links</p>\n<p>Spalte rechts</p>", ArticleFrontend::extract($html, 5, 1));
    }

    public function testExtractIgnoresOtherArticlesAndLanguages(): void
    {
        // Etwa die Fehlerseite, die statt des abgerufenen Artikels mit Status 200 kommt.
        $html = self::mark(9, 1, '<p>Seite nicht gefunden</p>') . self::mark(5, 2, '<p>Pond</p>');

        self::assertNull(ArticleFrontend::extract($html, 5, 1));
        self::assertSame('<p>Pond</p>', ArticleFrontend::extract($html, 5, 2));
    }

    public function testExtractWithoutMarkersReturnsNull(): void
    {
        self::assertNull(ArticleFrontend::extract('<html><body><p>Ohne Markierung</p></body></html>', 5, 1));
    }

    public function testExtractIgnoresUnclosedMarker(): void
    {
        $html = self::mark(5, 1, '<p>Vollstaendig</p>') . ArticleFrontend::startMarker(5, 1) . '<p>Abgeschnitten';

        self::assertSame('<p>Vollstaendig</p>', ArticleFrontend::extract($html, 5, 1));
        self::assertNull(ArticleFrontend::extract(ArticleFrontend::startMarker(5, 1) . '<p>Abgeschnitten', 5, 1));
    }

    public function testExtractKeepsEmptyMarkedPart(): void
    {
        self::assertSame('', ArticleFrontend::extract(self::mark(5, 1, ''), 5, 1));
    }

    public function testStartMarkerDoesNotMatchLongerIds(): void
    {
        self::assertNull(ArticleFrontend::extract(self::mark(15, 1, '<p>Andere Seite</p>'), 5, 1));
        self::assertNull(ArticleFrontend::extract(self::mark(5, 11, '<p>Andere Sprache</p>'), 5, 1));
    }

    private static function mark(int $articleId, int $clangId, string $content): string
    {
        return ArticleFrontend::startMarker($articleId, $clangId) . $content . ArticleFrontend::MARK_END;
    }
}
