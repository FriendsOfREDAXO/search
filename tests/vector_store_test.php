<?php

use FriendsOfRedaxo\Search\Vector\VectorStore;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_vector_store_test extends TestCase
{
    public function testTheDecimalSeparatorIsAPoint(): void
    {
        self::assertSame('[0.5,-0.25]', VectorStore::toText([0.5, -0.25]));
    }

    /**
     * number_format setzt ohne Zutun ein Komma als Tausendertrenner. Aus einem Wert
     * würden damit zwei, und der Vektor hätte eine Dimension zu viel – VEC_FromText
     * nähme ihn an und legte Unsinn ab.
     */
    public function testALargeValueGetsNoThousandsSeparator(): void
    {
        self::assertSame('[1234.5]', VectorStore::toText([1234.5]));
    }

    public function testWholeNumbersStayShort(): void
    {
        self::assertSame('[1,0,-1]', VectorStore::toText([1.0, 0.0, -1.0]));
    }

    /**
     * Sieben Nachkommastellen reichen fuer float32 -- mehr Stellen stehen in einem
     * Vektor gar nicht drin und blaehen nur die Anweisung auf.
     */
    public function testTheValuesAreCutToSevenDecimals(): void
    {
        self::assertSame('[0.1234568]', VectorStore::toText([0.12345678901]));
    }

    public function testAZeroStaysAZero(): void
    {
        // Beim Abschneiden der Nullen bleibt der Punkt die Grenze -- "[,1]" wäre kein
        // Vektor.
        self::assertSame('[0,1]', VectorStore::toText([0.0, 1.0]));
    }

    public function testAnEmptyVectorIsStillBrackets(): void
    {
        self::assertSame('[]', VectorStore::toText([]));
    }
}
