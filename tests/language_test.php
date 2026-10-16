<?php

use FriendsOfRedaxo\Search\Language;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_language_test extends TestCase
{
    public function testAKnownCodeStaysAsItIs(): void
    {
        self::assertSame('de', Language::normalize('de'));
        self::assertSame('ru', Language::normalize('RU'));
        self::assertSame('en', Language::normalize('  en  '));
    }

    /**
     * REDAXO-Sprachcodes sind freier Text und stehen oft laenger da. Das ist keine
     * Ableitung der Sprache aus dem clang -- es nimmt nur dem Benutzer Schreibarbeit ab,
     * wenn er selbst eine solche Form eintraegt.
     */
    public function testALongerFormIsReducedToItsBase(): void
    {
        self::assertSame('de', Language::normalize('de_de'));
        self::assertSame('de', Language::normalize('de-CH'));
        self::assertSame('pt', Language::normalize('pt_BR'));
    }

    public function testAnUnknownCodeBecomesUnknownInsteadOfBeingGuessed(): void
    {
        self::assertSame(Language::UNKNOWN, Language::normalize('klingonisch'));
        self::assertSame(Language::UNKNOWN, Language::normalize('xx'));
        self::assertSame(Language::UNKNOWN, Language::normalize(''));
    }

    /**
     * "Keine Angabe" ist im Formular ein eigener Wert, damit sich das Pflichtfeld nicht
     * uebersehen laesst. Gespeichert wird er, gerechnet wird mit UNKNOWN.
     */
    public function testTheExplicitNoneValueBecomesUnknown(): void
    {
        self::assertSame(Language::UNKNOWN, Language::normalize(Language::NONE));
        self::assertNotSame(Language::UNKNOWN, Language::NONE);
        self::assertArrayHasKey(Language::NONE, Language::options());
        self::assertArrayNotHasKey(Language::UNKNOWN, Language::options());
    }

    public function testEveryOfferedCodeIsAlsoKnown(): void
    {
        foreach (Language::codes() as $code) {
            self::assertTrue(Language::isKnown($code), $code);
            self::assertSame($code, Language::normalize($code));
        }
        self::assertFalse(Language::isKnown(Language::UNKNOWN));
    }
}
