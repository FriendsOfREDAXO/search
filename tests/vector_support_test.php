<?php

use FriendsOfRedaxo\Search\Vector\VectorSupport;
use PHPUnit\Framework\TestCase;

/**
 * Geprueft wird die Entscheidung, nicht der Server: an ihr haengt, ob ganze Teile der
 * Oberflaeche erscheinen, und das soll nicht davon abhaengen, worauf die Testsuite
 * gerade laeuft.
 *
 * @internal
 */
final class rex_search_vector_support_test extends TestCase
{
    public function testMariaDbFromTheRequiredVersionOn(): void
    {
        self::assertTrue(VectorSupport::supports('11.7.2-MariaDB'));
        self::assertTrue(VectorSupport::supports('11.8.0-MariaDB'));
        self::assertTrue(VectorSupport::supports('12.0.1-MariaDB-log'));
        // Zeichenweise verglichen waere "11.10" kleiner als "11.7" -- hier faellt auf,
        // wenn jemand version_compare durch einen schlichten Vergleich ersetzt.
        self::assertTrue(VectorSupport::supports('11.10.0-MariaDB'));
    }

    public function testOlderMariaDbDoesNot(): void
    {
        self::assertFalse(VectorSupport::supports('11.6.2-MariaDB'));
        self::assertFalse(VectorSupport::supports('10.11.9-MariaDB'));
        // Der Zahlenvergleich darf nicht auf Zeichenketten hereinfallen: "10.11" ist
        // groesser als "11.7", wenn man es Zeichen fuer Zeichen vergleicht.
        self::assertFalse(VectorSupport::supports('10.7.0-MariaDB'));
    }

    /**
     * MySQL 8 kennt weder Typ noch Index, MySQL 9 den Typ ohne Index. Die Versionszahl
     * allein genuegt also nicht -- 9.1 waere sonst "neuer als 11.7 nicht", 11.7 aber gibt
     * es bei MySQL gar nicht.
     */
    public function testMysqlNeverCounts(): void
    {
        self::assertFalse(VectorSupport::supports('8.0.39'));
        self::assertFalse(VectorSupport::supports('9.1.0'));
        self::assertFalse(VectorSupport::supports('11.7.0'));
    }

    public function testAnUnreadableVersionIsNoSupport(): void
    {
        self::assertFalse(VectorSupport::supports(''));
        self::assertFalse(VectorSupport::supports('MariaDB'));
    }
}
