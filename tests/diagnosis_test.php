<?php

use FriendsOfRedaxo\Search\Backend\Diagnosis;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_diagnosis_test extends TestCase
{
    public function testBytesAreShownInTheUnitThatFitsTheNumber(): void
    {
        self::assertSame('0 B', Diagnosis::formatBytes(0));
        self::assertSame('999 B', Diagnosis::formatBytes(999));
        self::assertSame('1 KB', Diagnosis::formatBytes(1024));
        self::assertSame('500 KB', Diagnosis::formatBytes(512000));
        self::assertSame('3.6 MB', Diagnosis::formatBytes(3774874));
    }

    public function testTheBoundaryBetweenTwoUnitsIsNotOffByOne(): void
    {
        self::assertSame('1023 B', Diagnosis::formatBytes(1023));
        self::assertStringEndsWith('KB', Diagnosis::formatBytes(1024));
        self::assertStringEndsWith('KB', Diagnosis::formatBytes(1024 * 1024 - 1));
        self::assertStringEndsWith('MB', Diagnosis::formatBytes(1024 * 1024));
    }

    public function testAMissingCacheDirectoryCountsAsEmptyInsteadOfFailing(): void
    {
        self::assertSame([0, 0], Diagnosis::cacheSize(sys_get_temp_dir() . '/gibt-es-nicht-' . uniqid()));
    }

    public function testFilesAreCountedAcrossTheSubdirectories(): void
    {
        $root = sys_get_temp_dir() . '/search-cache-' . uniqid();
        mkdir($root . '/ab', 0o777, true);
        mkdir($root . '/cd', 0o777, true);
        file_put_contents($root . '/ab/eins.json', str_repeat('x', 100));
        file_put_contents($root . '/cd/zwei.json', str_repeat('x', 200));

        try {
            self::assertSame([2, 300], Diagnosis::cacheSize($root));
        } finally {
            unlink($root . '/ab/eins.json');
            unlink($root . '/cd/zwei.json');
            rmdir($root . '/ab');
            rmdir($root . '/cd');
            rmdir($root);
        }
    }

    /**
     * Ein ins Leere zeigender Symlink wird vom Iterator als Blatt geliefert; ohne die
     * Pruefung auf eine echte Datei zaehlte er mit und getSize() liefe auf eine Warnung.
     */
    public function testADanglingSymlinkIsNotCounted(): void
    {
        $root = sys_get_temp_dir() . '/search-cache-' . uniqid();
        mkdir($root, 0o777, true);
        file_put_contents($root . '/echt.json', str_repeat('x', 50));
        symlink($root . '/gibt-es-nicht.json', $root . '/kaputt.json');

        try {
            self::assertSame([1, 50], Diagnosis::cacheSize($root));
        } finally {
            unlink($root . '/kaputt.json');
            unlink($root . '/echt.json');
            rmdir($root);
        }
    }

    /**
     * Die Befunde kommen unmaskiert heraus; maskiert wird in der Seite, genau einmal.
     * rex_i18n::msg() maskiert selbst -- mit ihm stand auf der Einstellungsseite
     * "&quot;Häuser&quot; findet dort kein &quot;Haus&quot;" statt der Anfuehrungszeichen.
     */
    public function testTheFindingsAreNotAlreadyEscaped(): void
    {
        foreach ([...Diagnosis::php(), ...Diagnosis::tools()] as $check) {
            self::assertStringNotContainsString('&quot;', $check['detail'], $check['label']);
            self::assertStringNotContainsString('&amp;', $check['detail'], $check['label']);
        }
    }

    public function testEveryFindingHasAKnownState(): void
    {
        foreach ([...Diagnosis::php(), ...Diagnosis::tools()] as $check) {
            self::assertContains($check['state'], [Diagnosis::OK, Diagnosis::WARNING, Diagnosis::MISSING], $check['label']);
            self::assertNotSame('', $check['label']);
        }
    }
}
