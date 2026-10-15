<?php

use FriendsOfRedaxo\Search\Extract\LayoutTableParser;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_layout_table_parser_test extends TestCase
{
    public function testColumnsBecomeATable(): void
    {
        $layout = implode("\n", [
            'Posten              2025          2026',
            'Erträge       44.100.000    45.553.857',
            'Aufwendungen  45.900.000    46.402.644',
            'Saldo         -1.800.000      -848.786',
        ]);

        $markdown = LayoutTableParser::toMarkdown($layout);

        self::assertStringContainsString('| Posten | 2025 | 2026 |', $markdown);
        self::assertStringContainsString('| Erträge | 44.100.000 | 45.553.857 |', $markdown);
        self::assertStringContainsString('| Saldo | -1.800.000 | -848.786 |', $markdown);
    }

    public function testFlowingTextStaysText(): void
    {
        $layout = implode("\n", [
            'Der Finanzhaushalt schließt im Jahr 2026 mit einem Fehlbedarf ab.',
            'Die Stundung der Beiträge wird auch für 2026 möglich sein.',
            'Hierzu wurde eine entsprechende Regelung aufgenommen.',
        ]);

        $markdown = LayoutTableParser::toMarkdown($layout);

        self::assertStringNotContainsString('|', $markdown);
        self::assertStringContainsString('Der Finanzhaushalt schließt', $markdown);
    }

    public function testIndentedTextIsNoTable(): void
    {
        // Zwei Spalten mit nur einer gefuellten Zelle je Zeile sind eingerueckter
        // Fliesstext, keine Tabelle.
        $layout = implode("\n", [
            '        Ein eingerückter Absatz über mehrere',
            '        Zeilen, wie ihn ein Zitat erzeugt, und',
            '        der keine Spalten enthält.',
        ]);

        $markdown = LayoutTableParser::toMarkdown($layout);

        self::assertStringNotContainsString('| --- |', $markdown);
    }

    public function testHeaderStaysEmptyWhenColumnCountDiffers(): void
    {
        // Rechtsbuendige Zahlenspalten: die Ueberschriftzeile hat keine Zelle in der
        // Beschriftungsspalte. Eine behauptete Zuordnung waere falsch, deshalb bleibt der
        // Kopf leer und die Zeile wird als Datenzeile ausgegeben.
        $layout = implode("\n", [
            '                        Haushalt 2026   Plan 2027   Plan 2028',
            'Betriebsvorricht.             -10.000      -5.000      -5.000',
            'Wasseranschluesse                   0           0           0',
            'Notstromaggregate             -50.000     -10.000     -10.000',
        ]);

        $lines = explode("\n", LayoutTableParser::toMarkdown($layout));

        self::assertSame('|  |  |  |  |', $lines[0]);
        self::assertSame('|  | Haushalt 2026 | Plan 2027 | Plan 2028 |', $lines[2]);
        self::assertSame('| Betriebsvorricht. | -10.000 | -5.000 | -5.000 |', $lines[3]);
    }

    public function testHeaderIsUsedWhenItLinesUp(): void
    {
        $layout = implode("\n", [
            'Posten            2025          2026',
            'Ertraege    44.100.000    45.553.857',
            'Saldo       -1.800.000      -848.786',
        ]);

        $lines = explode("\n", LayoutTableParser::toMarkdown($layout));

        self::assertSame('| Posten | 2025 | 2026 |', $lines[0]);
        self::assertSame('| --- | --- | --- |', $lines[1]);
    }

    public function testLabelAndValueStayTogetherWhenColumnsTouch(): void
    {
        // Laeuft die Beschriftung bis unter die erste Wertspalte, gibt es dort keine
        // Luecke und damit keine Spaltengrenze. Beides bleibt dann in einer Zelle -
        // unschoen, aber die Zuordnung geht nicht verloren.
        $layout = implode("\n", [
            '                  Haushalt 2026    Plan 2027    Plan 2028',
            'Betriebsvorrichtungen   -10.000       -5.000       -5.000',
            'Wasseranschluesse             0            0            0',
            'Notstromaggregate       -50.000      -10.000      -10.000',
        ]);

        $markdown = LayoutTableParser::toMarkdown($layout);

        self::assertStringContainsString('| Betriebsvorrichtungen   -10.000 |', $markdown);
    }

    public function testBlockWithMostlySingleCellRowsIsNoTable(): void
    {
        // Spaltengrenzen gibt es hier, aber nur eine Zeile fuellt mehr als eine Zelle.
        // Das ist eine Ueberschrift mit Zwischenzeilen, keine Tabelle.
        $layout = implode("\n", [
            'Posten                    2025          2026',
            'Zwischensumme                               ',
            'Gesamtsumme                                 ',
            'Nachrichtlich                               ',
        ]);

        self::assertStringNotContainsString('|', LayoutTableParser::toMarkdown($layout));
    }

    public function testTwoLinesAreNoTable(): void
    {
        // Zwei ausgerichtete Zeilen sind eher ein Adressblock als eine Tabelle.
        $layout = "Name          Vorname       Ort\nMeier         Anna          Eppstein";

        self::assertStringNotContainsString('|', LayoutTableParser::toMarkdown($layout));
    }

    public function testTwoColumnsAreNoTable(): void
    {
        // Ein Inhaltsverzeichnis hat zwei Spalten. Als Tabelle auszugeben brächte nichts
        // und wuerde eingerueckten Fliesstext regelmaessig falsch erkennen.
        $layout = implode("\n", [
            'Einleitung                     3',
            'Der Haushalt in Zahlen         7',
            'Ausblick                      21',
        ]);

        self::assertStringNotContainsString('|', LayoutTableParser::toMarkdown($layout));
    }

    public function testBlocksAreSeparatedByBlankLines(): void
    {
        $layout = "Ein Absatz Text.\n\nPosten    2025      2026\nErträge   44.100    45.553\nSaldo     -1.800      -848";

        $markdown = LayoutTableParser::toMarkdown($layout);

        self::assertStringStartsWith('Ein Absatz Text.', $markdown);
        self::assertStringContainsString("\n\n|", $markdown);
    }

    public function testPipesInCellsAreEscaped(): void
    {
        $layout = implode("\n", [
            'Feld       Wert|A     Wert|B',
            'Eins       1           2',
            'Zwei       3           4',
        ]);

        $markdown = LayoutTableParser::toMarkdown($layout);

        self::assertStringContainsString('Wert\\|A', $markdown);
    }
}
