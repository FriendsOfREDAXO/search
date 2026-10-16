<?php

use FriendsOfRedaxo\Search\Source\Type\YFormTableSourceType;
use PHPUnit\Framework\TestCase;

/**
 * Eine YForm-Tabelle fuehrt Uebersetzungen als Spalten nebeneinander. Alle in eine Quelle
 * zu nehmen ergaebe ein Dokument mit mehreren Sprachen, und das ist der eine Fall, der
 * sich nachtraeglich nicht mehr aufloesen laesst.
 *
 * @internal
 */
final class rex_search_yform_language_fields_test extends TestCase
{
    public function testAFieldAndItsTranslationAreRefused(): void
    {
        self::assertSame(
            ['description', 'description_en'],
            YFormTableSourceType::findMixedLanguageFields(['fields' => ['description', 'description_en']]),
        );
    }

    public function testTwoTranslationsWithoutTheBaseFieldAreRefusedToo(): void
    {
        self::assertSame(
            ['title_de', 'title_fr'],
            YFormTableSourceType::findMixedLanguageFields(['fields' => ['title_de', 'title_fr']]),
        );
    }

    /**
     * Das Titelfeld gehoert genauso ins Dokument wie die Inhaltsfelder; es gesondert zu
     * behandeln waere ein Loch in der Pruefung.
     */
    public function testTheTitleFieldCountsAsWell(): void
    {
        self::assertSame(
            ['name', 'name_en'],
            YFormTableSourceType::findMixedLanguageFields(['fields' => ['name'], 'title_field' => 'name_en']),
        );
    }

    public function testTheTitleFieldAlsoChosenAsContentFieldIsNoConflict(): void
    {
        self::assertNull(YFormTableSourceType::findMixedLanguageFields(['fields' => ['name', 'text'], 'title_field' => 'name']));
    }

    public function testFieldsThatOnlyEndSimilarlyAreLeftAlone(): void
    {
        // "_it" ist ein Sprachkuerzel, "credit" endet nur zufaellig darauf -- und selbst
        // dann waere der Grundname "cred" und damit von "credits" verschieden.
        self::assertNull(YFormTableSourceType::findMixedLanguageFields(['fields' => ['credit', 'teaser', 'text']]));
    }

    public function testAnEmptyConfigurationIsFine(): void
    {
        self::assertNull(YFormTableSourceType::findMixedLanguageFields([]));
        self::assertNull(YFormTableSourceType::findMixedLanguageFields(['fields' => [], 'title_field' => '']));
    }

    /**
     * REDAXO-Sprachcodes duerfen laenger sein als zwei Zeichen, "de_de" ist gebraeuchlich.
     * Dann muss die laengste Endung zuerst greifen: sonst bliebe von "name_de_de" der
     * Grundname "name_de" stehen, von "name_en_us" der Grundname "name_en", und die beiden
     * gaelten als verschiedene Felder.
     */
    public function testALongerClangCodeWinsOverItsTwoLetterBeginning(): void
    {
        $config = ['fields' => ['name_de_de', 'name_en_us']];

        self::assertSame(['name_de_de', 'name_en_us'], YFormTableSourceType::findMixedLanguageFields($config, ['de_de', 'en_us']));

        // Ohne die passenden Codes bleiben es zwei verschiedene Felder -- erkannt wird an
        // der Endung, nicht geraten.
        self::assertNull(YFormTableSourceType::findMixedLanguageFields($config, []));
    }

    public function testTheCodesOfTheInstallationAreUsedAsWell(): void
    {
        // "at" ist keine Sprache des AddOns; als Sprachcode der Installation zaehlt es
        // trotzdem.
        self::assertSame(
            ['preis', 'preis_at'],
            YFormTableSourceType::findMixedLanguageFields(['fields' => ['preis', 'preis_at']], ['at']),
        );
        self::assertNull(YFormTableSourceType::findMixedLanguageFields(['fields' => ['preis', 'preis_at']], []));
    }
}
