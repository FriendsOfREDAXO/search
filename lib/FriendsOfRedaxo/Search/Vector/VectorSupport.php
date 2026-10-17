<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use rex_sql;
use Throwable;

/**
 * Ob diese Datenbank Vektoren kann.
 *
 * Der Vektorteil des AddOns haengt an einer Eigenschaft, die aeltere Server nicht haben:
 * MariaDB kennt den Spaltentyp VECTOR und einen Index darauf erst ab 11.7. MySQL 8 hat
 * beides nicht, MySQL 9 den Typ ohne Index.
 *
 * Das AddOn selbst verlangt das nicht -- Volltext, Wortliste und Analyse laufen
 * unveraendert weiter. Nur die Abschnitte und ihre Vektoren entstehen dann gar nicht
 * erst, und die Oberflaeche sagt warum, statt Felder anzubieten, die nichts bewirken.
 */
final class VectorSupport
{
    /** Ab hier gibt es VECTOR und VECTOR INDEX. */
    public const MIN_MARIADB = '11.7';

    private static ?bool $available = null;

    private static ?string $version = null;

    public static function isAvailable(): bool
    {
        return self::$available ??= self::supports(self::getVersion());
    }

    /**
     * Die Versionszeile des Servers, etwa "11.7.2-MariaDB".
     */
    public static function getVersion(): string
    {
        if (null !== self::$version) {
            return self::$version;
        }

        try {
            $rows = rex_sql::factory()->getArray('SELECT VERSION() AS v');
        } catch (Throwable) {
            return self::$version = '';
        }

        return self::$version = (string) ($rows[0]['v'] ?? '');
    }

    /**
     * Ob diese Versionszeile Vektoren bedeutet.
     *
     * Getrennt von {@see getVersion()}, damit sich die Entscheidung ohne Datenbank
     * pruefen laesst -- sie ist der Grund, warum auf manchen Servern ganze Teile der
     * Oberflaeche fehlen, und das soll nicht von der Laune eines Servers abhaengen.
     */
    public static function supports(string $version): bool
    {
        if (!str_contains(strtolower($version), 'mariadb')) {
            return false;
        }

        // Aus "11.7.2-MariaDB-1:11.7.2+maria~ubu2404" die fuehrende Versionsnummer.
        if (!preg_match('/^(\d+)\.(\d+)/', $version, $match)) {
            return false;
        }

        return version_compare($match[1] . '.' . $match[2], self::MIN_MARIADB, '>=');
    }

    /**
     * Nur fuer Tests.
     */
    public static function reset(): void
    {
        self::$available = null;
        self::$version = null;
    }
}
