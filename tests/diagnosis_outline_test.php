<?php

use FriendsOfRedaxo\Search\Backend\Diagnosis;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Eine Quelle, die nach Gliederung zerlegt, deren Dokumente aber keine Ueberschrift
 * tragen, zerlegt in Wahrheit in feste Stuecke. Auf der Quellenliste sieht die
 * Einstellung richtig aus, und niemand erfaehrt, dass sie nichts bewirkt.
 *
 * Gemessen am Beispielbestand: von acht PDFs hat eines eine Ueberschrift, das groesste
 * mit 900.000 Zeichen keine einzige. Bei den Artikeln dagegen 165 von 188.
 *
 * @internal
 */
final class rex_search_diagnosis_outline_test extends TestCase
{
    /**
     * @return array{name: string, chunks: bool, strategy: string, documents: int, withHeadings: int}
     */
    private function source(string $name, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'chunks' => true, 'strategy' => ChunkStrategyRegistry::DEFAULT_KEY, 'documents' => 10, 'withHeadings' => 0];
    }

    /**
     * @param list<array{name: string, chunks: bool, strategy: string, documents: int, withHeadings: int}> $sources
     * @return list<string>
     */
    private function names(array $sources): array
    {
        return array_column(Diagnosis::sourcesWithoutOutline($sources), 'name');
    }

    public function testASourceSplittingByOutlineWithoutAnyHeadingIsNamed(): void
    {
        self::assertSame(['Haushalt'], $this->names([$this->source('Haushalt')]));
    }

    /**
     * Der Fall, an dem die strenge Regel scheiterte: die Medienpool-Quelle hat in einem
     * von acht Dokumenten eine Ueberschrift, und ausgerechnet das groesste mit 900.000
     * Zeichen hat keine. "Gar keine Ueberschrift" haette sie durchgelassen.
     */
    public function testOneHeadingInEightDocumentsIsStillAFinding(): void
    {
        self::assertSame(['Medienpool'], $this->names([$this->source('Medienpool', ['documents' => 8, 'withHeadings' => 1])]));
    }

    public function testASourceWhereMostDocumentsHaveHeadingsIsFine(): void
    {
        self::assertSame([], $this->names([$this->source('Artikel', ['documents' => 188, 'withHeadings' => 165])]));
    }

    /**
     * Die Grenze ist eine Setzung -- aber sie muss an einer bestimmten Stelle liegen und
     * dort bleiben. "Weniger als die Haelfte" heisst: genau die Haelfte ist in Ordnung,
     * eine weniger ist ein Befund.
     */
    public function testTheBoundaryLiesJustBelowHalfTheDocuments(): void
    {
        self::assertSame(['Knapp'], $this->names([$this->source('Knapp', ['documents' => 10, 'withHeadings' => 4])]));
        self::assertSame([], $this->names([$this->source('Knapp', ['documents' => 10, 'withHeadings' => 5])]));
    }

    public function testASourceWithoutAnyDocumentIsNotAFinding(): void
    {
        // Eine frisch angelegte Quelle hat noch nichts im Index. Sie zu nennen hiesse,
        // einen Befund zu melden, bevor es etwas zu befinden gibt.
        self::assertSame([], $this->names([$this->source('Neu', ['documents' => 0])]));
    }

    public function testASourceWithoutSplittingIsNoneOfOurBusiness(): void
    {
        self::assertSame([], $this->names([$this->source('Aus', ['chunks' => false])]));
    }

    /**
     * "Feste Groesse" und "Ganzes Dokument" kennen keine Gliederung. Dass ihre Dokumente
     * keine Ueberschrift haben, ist dort kein Befund, sondern der Normalfall.
     */
    public function testAnotherStrategyIsNotAFinding(): void
    {
        self::assertSame([], $this->names([
            $this->source('Feste Groesse', ['strategy' => 'fixed']),
            $this->source('Ganzes Dokument', ['strategy' => 'whole']),
        ]));
    }

    public function testEverySuchSourceIsNamedAndTheOthersAreLeftOut(): void
    {
        self::assertSame(['Hunde', 'PDFs'], $this->names([
            $this->source('Hunde'),
            $this->source('Artikel', ['documents' => 188, 'withHeadings' => 165]),
            $this->source('PDFs'),
            $this->source('Metadaten', ['strategy' => 'whole']),
        ]));
    }

    public function testTheCountsComeBackWithTheName(): void
    {
        // Ohne sie stuende in der Meldung nur "diese Quelle ist auffaellig", und niemand
        // koennte beurteilen, ob eine von acht oder achtzig von hundert gemeint sind.
        self::assertSame(
            [['name' => 'Medienpool', 'documents' => 8, 'withHeadings' => 1]],
            Diagnosis::sourcesWithoutOutline([$this->source('Medienpool', ['documents' => 8, 'withHeadings' => 1])]),
        );
    }

    public function testWithoutAnySourceThereIsNothingToReport(): void
    {
        self::assertSame([], $this->names([]));
    }
}
