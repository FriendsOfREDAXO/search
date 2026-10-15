<?php

use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Extract\Extractor\TextExtractor;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_text_extractor_test extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    public function testMarkdownIsTakenAsItIs(): void
    {
        $file = $this->write("# Titel\n\nEin Absatz.", 'md');

        $document = (new TextExtractor())->extract($file, new ExtractionContext('t.md'));

        self::assertNotNull($document);
        self::assertSame("# Titel\n\nEin Absatz.", $document->markdown);
        self::assertSame('Titel', $document->title);
    }

    public function testCsvBecomesATable(): void
    {
        $file = $this->write("Posten;2025;2026\nErträge;44100000;45553857\nSaldo;-1800000;-848786", 'csv');

        $markdown = (new TextExtractor())->extract($file, new ExtractionContext('t.csv'))?->markdown ?? '';

        // Als Fliesstext ginge die Zuordnung Spalte zu Wert verloren.
        self::assertStringContainsString('| Posten | 2025 | 2026 |', $markdown);
        self::assertStringContainsString('| Erträge | 44100000 | 45553857 |', $markdown);
    }

    public function testCsvDelimiterIsDetected(): void
    {
        $file = $this->write("a,b,c\n1,2,3\n4,5,6", 'csv');

        $markdown = (new TextExtractor())->extract($file, new ExtractionContext('t.csv'))?->markdown ?? '';

        self::assertStringContainsString('| a | b | c |', $markdown);
    }

    public function testSingleColumnCsvStaysText(): void
    {
        $file = $this->write("nur\neine\nspalte", 'csv');

        $markdown = (new TextExtractor())->extract($file, new ExtractionContext('t.csv'))?->markdown ?? '';

        self::assertStringNotContainsString('|', $markdown);
    }

    public function testWindowsEncodingIsConverted(): void
    {
        $raw = mb_convert_encoding('Grüße aus Eppstein', 'Windows-1252', 'UTF-8');
        self::assertIsString($raw);
        $file = $this->write($raw, 'txt');

        $markdown = (new TextExtractor())->extract($file, new ExtractionContext('t.txt'))?->markdown ?? '';

        // Ohne Umwandlung landen kaputte Bytes im Index und die Suche findet das Wort nie.
        self::assertSame('Grüße aus Eppstein', $markdown);
        self::assertTrue(mb_check_encoding($markdown, 'UTF-8'));
    }

    public function testMissingFileYieldsNull(): void
    {
        self::assertNull((new TextExtractor())->extract('/pfad/gibt/es/nicht.txt', new ExtractionContext('nicht.txt')));
    }

    private function write(string $content, string $extension): string
    {
        $file = tempnam(sys_get_temp_dir(), 'txt');
        self::assertIsString($file);
        $this->files[] = $file;
        file_put_contents($file, $content);
        unset($extension);

        return $file;
    }
}
