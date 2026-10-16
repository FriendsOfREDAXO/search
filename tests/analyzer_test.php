<?php

use FriendsOfRedaxo\Search\Analysis\Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * Prueft gegen die mitgelieferten Bibliotheken, nicht gegen ein Doppel: was hier
 * herauskommt, bestimmt den Inhalt der Formtabelle, und genau diese Werte muessen stabil
 * sein. Ein Versionssprung bei loupe/matcher oder wamania/php-stemmer, der sie verschiebt,
 * soll hier auffallen und nicht erst an einer schlechteren Trefferliste.
 *
 * @internal
 */
final class rex_search_analyzer_test extends TestCase
{
    protected function setUp(): void
    {
        Analyzer::setCacheDirectory(sys_get_temp_dir() . '/rex-search-test-dictionaries');
    }

    public function testACompoundYieldsItsOwnStemAndTheStemOfEveryPart(): void
    {
        self::assertSame(['burgruin', 'burg', 'ruin'], $this->de()->forms('Burgruine'));
    }

    /**
     * Der Kern der Sache: gebeugte Form und Zusammensetzung treffen sich auf derselben
     * Form. Ohne das faende "Ruinen" die "Burgruine" nicht, weil die Zerlegung "ruine"
     * liefert und nicht "ruinen".
     */
    public function testAnInflectedFormReachesTheSameStemAsTheCompoundPart(): void
    {
        self::assertContains('ruin', $this->de()->forms('Ruinen'));
        self::assertContains('ruin', $this->de()->forms('Burgruine'));
    }

    /**
     * Das Gegenstueck: eine Zerlegung, die zu weit ginge, waere schlimmer als keine.
     * "Hamburg" und "Einbuergerung" enthalten die Zeichenfolge "burg", sind aber keine
     * Zusammensetzungen damit -- genau der Beifang, den die Suche nach enthaltenen Teilen
     * frueher geliefert hat.
     */
    public function testWordsThatOnlyContainTheLettersAreNotTakenApart(): void
    {
        self::assertSame([], $this->de()->parts('Hamburg'));
        self::assertSame([], $this->de()->parts('Einbürgerung'));
    }

    /**
     * Die Zerlegung arbeitet sich schrittweise durch ein Wort und behaelt dabei auch die
     * Zwischenstufen. Ohne sie bliebe von "Bandmaterial" nur "band", "mate" und "rial" --
     * ausgerechnet "material" fiele weg, und eine Suche danach faende das Wort nicht.
     */
    public function testIntermediateTermsAreKept(): void
    {
        self::assertContains('material', $this->de()->parts('Bandmaterial'));
        self::assertContains('bestand', $this->de()->parts('Bestandsaufnahme'));
    }

    public function testTheWordItselfAlwaysHasAForm(): void
    {
        // Ohne eigene Zeile waere "Burg" ueber die Formtabelle nicht erreichbar, und
        // "Burgen" fuende es nicht.
        self::assertSame(['burg'], $this->de()->forms('Burg'));
    }

    public function testTheStemDependsOnTheLanguage(): void
    {
        self::assertSame(['has'], $this->de()->forms('Hasen'));
        self::assertSame(['hasen'], Analyzer::forLanguage('en')->forms('Hasen'));
    }

    public function testThereIsNoAnalysisWithoutALanguage(): void
    {
        self::assertNull(Analyzer::forLanguage(''));
        self::assertNull(Analyzer::forLanguage('xx'));
        self::assertFalse(Analyzer::supports(''));
    }

    /**
     * Fuer Franzoesisch gibt es einen Stammbildner, aber kein Woerterbuch zur Zerlegung.
     * Die Quelle bekommt trotzdem Formen -- nur eben keine zerlegten.
     */
    public function testALanguageWithoutADictionaryStillGetsStems(): void
    {
        self::assertTrue(Analyzer::supports('fr'));
        self::assertFalse(Analyzer::canDecompound('fr'));
        self::assertSame([], Analyzer::forLanguage('fr')->parts('bibliothèque'));
        self::assertNotSame([], Analyzer::forLanguage('fr')->forms('bibliothèques'));
    }

    public function testAnEmptyWordHasNoForms(): void
    {
        self::assertSame([], $this->de()->forms('   '));
    }

    private function de(): Analyzer
    {
        return Analyzer::forLanguage('de');
    }
}
