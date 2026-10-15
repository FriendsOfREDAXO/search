<?php

use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Extract\Extractor\OfficeExtractor;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_office_extractor_test extends TestCase
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

    public function testDocxBecomesMarkdown(): void
    {
        $file = $this->buildDocx($this->docxBody());

        $document = (new OfficeExtractor())->extract($file, new ExtractionContext('t.docx'));

        self::assertNotNull($document);
        self::assertStringContainsString('# Jahresbericht', $document->markdown);
        self::assertStringContainsString('## Zahlen', $document->markdown);
        self::assertStringContainsString('Ein gewöhnlicher Absatz.', $document->markdown);
        self::assertSame('Jahresbericht', $document->title);
    }

    public function testDocxListItemsFormOneBlock(): void
    {
        $file = $this->buildDocx($this->docxBody());

        $markdown = (new OfficeExtractor())->extract($file, new ExtractionContext('t.docx'))?->markdown ?? '';

        // Jeder Punkt kommt als eigener Absatz aus Word; getrennt durch Leerzeilen waere
        // es in Markdown keine Liste mehr.
        self::assertStringContainsString("- Erster Punkt\n- Zweiter Punkt", $markdown);
    }

    public function testDocxTableKeepsRowsAndColumns(): void
    {
        $file = $this->buildDocx($this->docxBody());

        $markdown = (new OfficeExtractor())->extract($file, new ExtractionContext('t.docx'))?->markdown ?? '';

        self::assertStringContainsString('| Posten | 2026 |', $markdown);
        self::assertStringContainsString('| Erträge | 45.553.857 |', $markdown);
    }

    public function testPipesInCellsAreEscaped(): void
    {
        $body = '<w:tbl>'
            . $this->docxRow(['A|B', 'C'])
            . $this->docxRow(['1', '2'])
            . '</w:tbl>';
        $file = $this->buildDocx($body);

        $markdown = (new OfficeExtractor())->extract($file, new ExtractionContext('t.docx'))?->markdown ?? '';

        self::assertStringContainsString('A\\|B', $markdown);
    }

    public function testBrokenArchiveYieldsNull(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nozip');
        self::assertIsString($file);
        $this->files[] = $file;
        file_put_contents($file, 'kein zip');

        self::assertNull((new OfficeExtractor())->extract($file, new ExtractionContext('t.docx')));
    }

    public function testDocumentWithoutTextYieldsNull(): void
    {
        $file = $this->buildDocx('<w:p><w:r><w:t xml:space="preserve">   </w:t></w:r></w:p>');

        self::assertNull((new OfficeExtractor())->extract($file, new ExtractionContext('t.docx')));
    }

    public function testContentIsTruncatedAtTheLimit(): void
    {
        $long = str_repeat('Wort ', 400);
        $file = $this->buildDocx('<w:p><w:r><w:t>' . $long . '</w:t></w:r></w:p>');

        $document = (new OfficeExtractor())->extract($file, new ExtractionContext('t.docx', '', 100));

        self::assertNotNull($document);
        self::assertTrue($document->truncated);
        self::assertLessThanOrEqual(100, mb_strlen($document->markdown));
    }

    private function docxBody(): string
    {
        return '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Jahresbericht</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Ein gewöhnlicher Absatz.</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Erster Punkt</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Zweiter Punkt</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Zahlen</w:t></w:r></w:p>'
            . '<w:tbl>' . $this->docxRow(['Posten', '2026']) . $this->docxRow(['Erträge', '45.553.857']) . '</w:tbl>';
    }

    /**
     * @param list<string> $cells
     */
    private function docxRow(array $cells): string
    {
        $xml = '<w:tr>';
        foreach ($cells as $cell) {
            $xml .= '<w:tc><w:p><w:r><w:t>' . htmlspecialchars($cell, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p></w:tc>';
        }

        return $xml . '</w:tr>';
    }

    private function buildDocx(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'docx');
        self::assertIsString($file);
        $this->files[] = $file;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '</w:body></w:document>';

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($file, ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $file;
    }
}
