<?php

use FriendsOfRedaxo\Search\Markdown;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_markdown_test extends TestCase
{
    public function testHtmlBecomesMarkdownWithStructure(): void
    {
        $markdown = Markdown::fromHtml('<h2>Der Spiegel</h2><p>Gold reflektiert <strong>Infrarot</strong>.</p><ul><li>Beryllium</li></ul>');

        self::assertStringContainsString('## Der Spiegel', $markdown);
        self::assertStringContainsString('**Infrarot**', $markdown);
        self::assertStringContainsString('- Beryllium', $markdown);
    }

    public function testTablesSurviveTheConversion(): void
    {
        $html = '<table><thead><tr><th>Posten</th><th>2026</th></tr></thead>'
            . '<tbody><tr><td>Erträge</td><td>45.553.857</td></tr></tbody></table>';

        // Ohne den TableConverter laesst die Bibliothek Tabellen als HTML stehen. Genau die
        // Struktur soll aber erhalten bleiben, sie traegt die Zuordnung Zeile zu Wert.
        $markdown = Markdown::fromHtml($html);

        self::assertStringContainsString('| Posten | 2026 |', $markdown);
        self::assertStringContainsString('| Erträge | 45.553.857 |', $markdown);
        self::assertStringNotContainsString('<td>', $markdown);
    }

    public function testImageAltTextIsKept(): void
    {
        // Beim blossen Entfernen der Tags ginge der Alt-Text verloren.
        $markdown = Markdown::fromHtml('<p>Vorher</p><img src="/media/x.jpg" alt="Der Spiegel im Reinraum">');

        self::assertStringContainsString('Der Spiegel im Reinraum', Markdown::toPlainText($markdown));
    }

    public function testScriptAndStyleAreRemoved(): void
    {
        $markdown = Markdown::fromHtml('<p>Text</p><script>var geheim = 1;</script><style>p{color:red}</style>');

        self::assertStringNotContainsString('geheim', $markdown);
        self::assertStringNotContainsString('color:red', $markdown);
    }

    public function testEmptyContainersDoNotLeaveBlankLines(): void
    {
        // Template-Huellen ohne Inhalt sind der Regelfall in REDAXO. Sie hinterlassen
        // Zeilen aus einem einzelnen Leerzeichen, die wie Inhalt aussehen, aber keiner sind.
        $markdown = Markdown::fromHtml("<h1>Titel</h1>\n<div>\n  <div>&nbsp;</div>\n</div>\n<h2>Abschnitt</h2>");

        self::assertSame("# Titel\n\n## Abschnitt", $markdown);
    }

    public function testPlainTextDropsSyntaxButKeepsText(): void
    {
        $markdown = "# Titel\n\nEin **fetter** und *schräger* Text mit [Link](/ziel/) und `Code`.\n\n- Punkt eins\n- Punkt zwei";

        $plain = Markdown::toPlainText($markdown);

        self::assertStringNotContainsString('#', $plain);
        self::assertStringNotContainsString('**', $plain);
        self::assertStringNotContainsString('](', $plain);
        self::assertStringContainsString('Titel', $plain);
        self::assertStringContainsString('fetter', $plain);
        self::assertStringContainsString('schräger', $plain);
        self::assertStringContainsString('Link', $plain);
        self::assertStringContainsString('Code', $plain);
        self::assertStringContainsString('Punkt eins', $plain);
    }

    public function testPlainTextKeepsTableCellsAndDropsTheRuleLine(): void
    {
        $markdown = "| Posten | 2026 |\n| --- | --- |\n| Erträge | 45.553.857 |";

        $plain = Markdown::toPlainText($markdown);

        self::assertSame("Posten 2026\nErträge 45.553.857", $plain);
    }

    public function testPlainTextKeepsUnderscoresInsideWords(): void
    {
        // Zwei Feldnamen in einer Zeile: ohne Wortgrenze frisst die Auszeichnungsregel
        // alles zwischen dem ersten und dem letzten Unterstrich.
        self::assertSame(
            'Feld art_description und med_copyright',
            Markdown::toPlainText('Feld art_description und med_copyright'),
        );
    }

    public function testPlainTextDropsAHeadingInsideAListItem(): void
    {
        // Eine Kachelliste: jeder Punkt eine verlinkte Ueberschrift.
        self::assertSame(
            'Heizkosten senken',
            Markdown::toPlainText('- ### [Heizkosten senken](/buergerinnen-und-buerger/heizkosten-senken/)'),
        );
    }

    public function testPlainTextUnwrapsAutolinks(): void
    {
        self::assertSame(
            'Fragen gerne unter solar@lea-hessen.de.',
            Markdown::toPlainText('Fragen gerne unter <solar@lea-hessen.de>.'),
        );
        self::assertSame('Siehe https://example.org/', Markdown::toPlainText('Siehe <https://example.org/>'));
    }

    public function testPlainTextSeparatesAnAutolinkFromTheTextBefore(): void
    {
        self::assertSame(
            '+49 611 95017 8649 evelyn.kierek@lea-hessen.de',
            Markdown::toPlainText('+49 611 95017 8649<evelyn.kierek@lea-hessen.de>'),
        );
    }

    public function testPlainTextDropsAHeadingBehindAnImage(): void
    {
        self::assertSame(
            'Passivhaus im Bestand Kostenplan',
            Markdown::toPlainText('- ![](/media/publikationen-nopic-small.png) ## Passivhaus im Bestand Kostenplan'),
        );
    }

    public function testPlainTextKeepsEscapedBracketsInsideALink(): void
    {
        self::assertSame(
            'Merkblatt zur Digitalisierung [PDF]',
            Markdown::toPlainText('[**Merkblatt zur Digitalisierung \\[PDF\\]**](https://www.wibank.de/merkblatt.pdf)'),
        );
    }

    public function testPlainTextDropsNestedBold(): void
    {
        // So setzt der Konverter ein <strong> in einem <strong> um.
        self::assertSame(
            'Energie sparen und Klimaschutz fördern',
            Markdown::toPlainText('**Energie sparen **und Klimaschutz fördern****'),
        );
    }

    public function testPlainTextCleansAPieceCutOutOfALink(): void
    {
        // Ein zu langer Abschnitt wird nach Zeichen geteilt, auch mitten in einem Link.
        self::assertSame(
            'Sprechstunde Online Mittwoch',
            Markdown::toPlainText('Sprechstunde Online](/termine/sprechstunde-2026) Mittwoch'),
        );
        self::assertSame(
            'Solarenergie Fördervereins e.V. Erleichterungen',
            Markdown::toPlainText('Solarenergie Fördervereins e.V.](https://www.sfv.de/solarbrief "Externer Link") Erleichterungen'),
        );
        self::assertSame(
            'Handreichung herunterladen (PDF, 1,75',
            Markdown::toPlainText('Handreichung [herunterladen (PDF, 1,75'),
        );
    }

    public function testPlainTextFlattensNestedTables(): void
    {
        // Mailings bauen ihr Layout aus Tabellen in Tabellen; der Konverter maskiert die
        // inneren Trennstriche.
        $markdown = "| \\| \\| \\| © Shutterstock/atk work \\| \\|--:\\| \\| \\|:-:\\| \\| \\|:--\\| |\n|:-:|";

        self::assertSame('© Shutterstock/atk work', Markdown::toPlainText($markdown));
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', Markdown::fromHtml(null));
        self::assertSame('', Markdown::fromHtml('   '));
        self::assertSame('', Markdown::toPlainText(null));
    }
}
