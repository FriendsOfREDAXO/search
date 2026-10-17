<?php

use FriendsOfRedaxo\Search\Chunk\Chunk;
use FriendsOfRedaxo\Search\Chunk\ChunkBuilder;
use FriendsOfRedaxo\Search\Chunk\ChunkInput;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategyRegistry;
use FriendsOfRedaxo\Search\Chunk\Strategy\FixedChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\Strategy\MarkdownChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\Strategy\WholeDocumentChunkStrategy;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_chunk_strategy_test extends TestCase
{
    private function markdown(string $title, string $markdown): ChunkInput
    {
        return new ChunkInput($title, strip_tags($markdown), $markdown, IndexDocument::FORMAT_MARKDOWN);
    }

    public function testWholeDocumentKeepsTheTitleWithTheText(): void
    {
        $chunks = (new WholeDocumentChunkStrategy())->split(new ChunkInput('Hasen', 'Ein Beitrag über Hasen.'), []);

        self::assertCount(1, $chunks);
        self::assertSame("Hasen\n\nEin Beitrag über Hasen.", $chunks[0]->text);
    }

    public function testADocumentWithoutAnythingYieldsNothing(): void
    {
        self::assertSame([], (new WholeDocumentChunkStrategy())->split(new ChunkInput('', ''), []));
        self::assertTrue((new ChunkInput('  ', "\n", null, null))->isEmpty());
    }

    /**
     * Ein Datensatz, von dem nur der Name dasteht, ist ueber genau diesen Namen
     * auffindbar -- gemessen findet "Kaninchen" den Artikel "Hasen" (fuenf Zeichen) als
     * besten Treffer und "Speisekarte" den Eintrag "Die Speisekarte" mit einer
     * Aehnlichkeit von 0,820. Ohne diesen Rueckfall faellt eine Quelle, deren gewaehltes
     * Feld leer ist, stillschweigend ganz aus: am Bestand gemessen achtzehn von
     * achtzehn YForm-Datensaetzen und vierzehn Artikel der Volltextquelle.
     */
    public function testATitleWithoutBodyIsStillSomethingToEmbed(): void
    {
        $nurTitel = new ChunkInput('Registrierung', '', null, null);

        self::assertFalse($nurTitel->isEmpty());
        self::assertSame('Registrierung', $nurTitel->getText());

        foreach ([new WholeDocumentChunkStrategy(), new FixedChunkStrategy(), new MarkdownChunkStrategy()] as $strategy) {
            $chunks = $strategy->split($nurTitel, []);
            self::assertCount(1, $chunks, $strategy->getKey());
            self::assertStringContainsString('Registrierung', $chunks[0]->text, $strategy->getKey());
        }
    }

    /**
     * Ein Originalinhalt allein macht noch keine Gliederung -- erst sein Format. Eine
     * YForm-Quelle legt ihren Feldwert als FORMAT_TEXT daneben; wuerde der als Markdown
     * gelesen, bekaeme ein Feld mit einer Raute am Zeilenanfang Ueberschriften, die
     * niemand geschrieben hat.
     */
    public function testRawContentWithoutMarkdownFormatIsNoStructure(): void
    {
        $lang = str_repeat('Eine Zeile mit Inhalt darin. ', 20);
        $text = "$lang\n\n# 4711 Vermerk\n\n$lang";
        $klartext = new ChunkInput('Liste', $text, $text, IndexDocument::FORMAT_TEXT);

        self::assertFalse($klartext->hasStructure());
        self::assertSame(['Liste'], array_unique(array_map(
            static fn (Chunk $c): string => $c->headingPath,
            (new MarkdownChunkStrategy())->split($klartext, []),
        )));
    }

    public function testABodyWinsOverTheTitleAsText(): void
    {
        self::assertSame('Der Inhalt', (new ChunkInput('Titel', 'Der Inhalt', null, null))->getText());
    }

    public function testFixedSplittingPutsTheTitleIntoThePath(): void
    {
        $input = new ChunkInput('Haushalt', trim(str_repeat('Ein Satz mit etwas Text darin. ', 100)));
        $chunks = (new FixedChunkStrategy())->split($input, ['chunk_size' => 400, 'chunk_overlap' => 0]);

        self::assertGreaterThan(1, count($chunks));
        self::assertSame('Haushalt', $chunks[0]->headingPath);
    }

    public function testTheSizeIsHeldWithinBounds(): void
    {
        self::assertSame(200, FixedChunkStrategy::size(['chunk_size' => '10']));
        self::assertSame(8000, FixedChunkStrategy::size(['chunk_size' => '999999']));
        self::assertSame(FixedChunkStrategy::DEFAULT_SIZE, FixedChunkStrategy::size([]));
        self::assertSame(0, FixedChunkStrategy::overlap(['chunk_overlap' => '-5']));
    }

    public function testEachSectionBecomesItsOwnChunkWithItsPath(): void
    {
        $input = $this->markdown('Stadt Eppstein', "# Burg\n\n" . str_repeat('Text über die Burg. ', 25) . "\n\n## Öffnungszeiten\n\n" . str_repeat('Täglich geöffnet. ', 25));
        $chunks = (new MarkdownChunkStrategy())->split($input, []);

        self::assertCount(2, $chunks);
        self::assertSame('Stadt Eppstein › Burg', $chunks[0]->headingPath);
        self::assertSame('Stadt Eppstein › Burg › Öffnungszeiten', $chunks[1]->headingPath);
    }

    /**
     * Ein Artikel heisst oft genauso wie seine erste Ueberschrift. "Fortnite › Fortnite"
     * sagt nichts und kostet trotzdem Platz, weil der Pfad beim Einbetten vorangestellt
     * wird.
     */
    public function testAHeadingEqualToTheTitleIsNotRepeated(): void
    {
        $input = $this->markdown('Fortnite', "# Fortnite\n\n" . str_repeat('Ein Spiel. ', 40));

        self::assertSame('Fortnite', (new MarkdownChunkStrategy())->split($input, [])[0]->headingPath);
    }

    public function testAHeadingClosesTheDeeperOnesBeforeIt(): void
    {
        $lang = str_repeat('Inhalt dazu. ', 30);
        $input = $this->markdown('', "# A\n\n$lang\n\n## A1\n\n$lang\n\n# B\n\n$lang");
        $chunks = (new MarkdownChunkStrategy())->split($input, []);

        self::assertSame(['A', 'A › A1', 'B'], array_map(static fn (Chunk $c): string => $c->headingPath, $chunks));
    }

    public function testASectionTooSmallForItsOwnChunkIsMergedWithTheNext(): void
    {
        $input = $this->markdown('', "# Kurz\n\nZwei Worte.\n\n## Danach\n\n" . str_repeat('Hier steht mehr Text. ', 30));
        $chunks = (new MarkdownChunkStrategy())->split($input, []);

        self::assertCount(1, $chunks);
        self::assertStringContainsString('Zwei Worte.', $chunks[0]->text);
        self::assertStringContainsString('Hier steht mehr Text.', $chunks[0]->text);
    }

    public function testAHashInACodeBlockIsNoHeading(): void
    {
        $lang = str_repeat('Erläuterung dazu an dieser Stelle. ', 20);
        $input = $this->markdown('', "# Befehle\n\n$lang\n\n```\n# das ist ein Kommentar\ngit status\n```\n\n$lang");

        // Die Rauten im Codeblock duerfen keinen zweiten Abschnitt aufmachen: alles
        // gehoert unter "Befehle".
        self::assertSame(['Befehle'], array_unique(array_map(static fn (Chunk $c): string => $c->headingPath, (new MarkdownChunkStrategy())->split($input, []))));
    }

    /**
     * Und umgekehrt: in einem Text ohne Markdown ist eine Raute am Zeilenanfang keine
     * Ueberschrift, sondern ein Zeichen. Eine CSV-Zeile oder eine PDF-Zeile bekaeme sonst
     * einen Pfad, den niemand geschrieben hat.
     */
    public function testAHashInPlainTextIsNoHeading(): void
    {
        $lang = str_repeat('Eine Zeile mit Inhalt darin. ', 20);
        $plain = new ChunkInput('Liste', "$lang\n\n# 4711 Vermerk\n\n$lang", null, null);

        self::assertSame(['Liste'], array_unique(array_map(static fn (Chunk $c): string => $c->headingPath, (new MarkdownChunkStrategy())->split($plain, []))));
    }

    /**
     * Ohne Gliederung faellt das Verfahren auf feste Stuecke zurueck. Das ist der
     * haeufige Fall: ein YForm-Feldwert und eine CSV-Datei haben kein Markdown.
     */
    public function testWithoutMarkdownItFallsBackToFixedPieces(): void
    {
        $plain = new ChunkInput('Titel', trim(str_repeat('Ein Satz mit etwas Text darin. ', 100)), null, null);

        self::assertEquals(
            (new FixedChunkStrategy())->split($plain, []),
            (new MarkdownChunkStrategy())->split($plain, []),
        );
    }

    public function testTheHashCoversThePathNotJustTheText(): void
    {
        // Sonst behielte ein Abschnitt seinen Vektor, obwohl sich der Pfad geaendert hat
        // -- und der Pfad wird mit eingebettet.
        self::assertNotSame((new Chunk('Text', 'A'))->getHash(), (new Chunk('Text', 'B'))->getHash());
        self::assertSame((new Chunk('Text', 'A'))->getHash(), (new Chunk('Text', 'A'))->getHash());
    }

    public function testThePathIsPutInFrontOfTheTextWhenEmbedding(): void
    {
        self::assertSame("A › B\n\nText", (new Chunk('Text', 'A › B'))->getEmbeddableText());
        self::assertSame('Text', (new Chunk('Text'))->getEmbeddableText());
    }

    public function testEveryRegisteredStrategyIsReachableByItsKey(): void
    {
        foreach (ChunkStrategyRegistry::all() as $key => $strategy) {
            self::assertSame($key, $strategy->getKey());
            self::assertNotSame('', $strategy->getLabel());
        }
        self::assertArrayHasKey(ChunkStrategyRegistry::DEFAULT_KEY, ChunkStrategyRegistry::all());
    }

    public function testAnUnknownStrategyFallsBackInsteadOfFailing(): void
    {
        // Das AddOn, das ein Verfahren mitgebracht hat, kann deinstalliert werden -- die
        // Quelle zeigt dann auf einen Schluessel, den es nicht mehr gibt.
        self::assertSame(ChunkStrategyRegistry::DEFAULT_KEY, ChunkStrategyRegistry::getOrDefault('gibt-es-nicht')->getKey());
    }

    /**
     * Ein Einbettungsmodell nimmt nur begrenzt viel Text an -- text-embedding-3-small
     * 8.191 Token, also grob 25.000 Zeichen Deutsch. Ein laengerer Abschnitt wird nicht
     * gekuerzt eingebettet, sondern abgewiesen, und bliebe nach drei Versuchen fuer
     * immer ohne Vektor.
     *
     * Am Bestand gemessen ist das kein Grenzfall: "Ganzes Dokument" auf der
     * Dokumentquelle ergaebe fuer einen Haushaltsplan einen Abschnitt mit 536.782
     * Zeichen.
     */
    public function testAnOversizedChunkIsSplitByTheBuilder(): void
    {
        $riesig = new Chunk(trim(str_repeat('Ein Satz mit etwas Text darin. ', 2000)), 'Pfad');

        self::assertGreaterThan(ChunkStrategy::MAX_CHUNK_CHARACTERS, mb_strlen($riesig->text));

        $teile = ChunkBuilder::enforceMaximum([$riesig], []);

        self::assertGreaterThan(1, count($teile));
        foreach ($teile as $teil) {
            self::assertLessThanOrEqual(ChunkStrategy::MAX_CHUNK_CHARACTERS, mb_strlen($teil->text));
            // Die Teile gehoeren weiter zu derselben Stelle im Dokument.
            self::assertSame('Pfad', $teil->headingPath);
        }
    }

    public function testAChunkWithinTheLimitIsLeftUntouched(): void
    {
        $chunks = [new Chunk('Kurz', 'A'), new Chunk(str_repeat('b', 1000), 'B')];

        self::assertEquals($chunks, ChunkBuilder::enforceMaximum($chunks, []));
    }
}
