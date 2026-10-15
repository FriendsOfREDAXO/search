<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract\Extractor;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use rex_i18n;
use Throwable;
use ZipArchive;

use function array_slice;
use function count;
use function in_array;
use function ord;

use const LIBXML_COMPACT;
use const LIBXML_NONET;

/**
 * Office-Dateien nach Markdown, ohne Fremdbibliothek.
 *
 * Alle hier bedienten Formate sind ZIP-Archive mit XML darin. Das reicht fuer eine
 * Textextraktion vollstaendig aus; eine Office-Bibliothek wuerde denselben Inhalt liefern
 * und dabei ein Vielfaches an Abhaengigkeiten mitbringen. Was hier bewusst fehlt, ist alles
 * jenseits von Text: Formatierung, Formeln, Bilder, eingebettete Objekte.
 */
final class OfficeExtractor extends DocumentExtractor
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    /** Obergrenze je XML-Teil, damit eine praeparierte Datei den Speicher nicht sprengt. */
    private const MAX_XML_BYTES = 64 * 1024 * 1024;

    /** Tabellenkalkulationen koennen zehntausende Zeilen haben; so viele braucht kein Index. */
    private const MAX_SHEET_ROWS = 2000;
    private const MAX_SHEET_COLUMNS = 40;

    /** @var array<string, int> Stil-Kuerzel auf Ueberschriftenebene, je Datei neu gelesen */
    private array $headingStyles = [];

    public function getKey(): string
    {
        return 'office';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_extractor_office');
    }

    public function getExtensions(): array
    {
        return ['docx', 'odt', 'xlsx', 'ods', 'pptx', 'odp'];
    }

    public function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($path)) {
            return null;
        }

        try {
            $markdown = match ($context->getExtension()) {
                'docx' => $this->fromDocx($zip),
                'xlsx' => $this->fromXlsx($zip),
                'pptx' => $this->fromPptx($zip),
                default => $this->fromOpenDocument($zip),
            };
        } catch (Throwable) {
            return null;
        } finally {
            $zip->close();
        }

        if (null === $markdown) {
            return null;
        }

        [$markdown, $truncated] = $context->truncate($markdown);

        return new ExtractedDocument($markdown, $this->readTitle($markdown), ['extractor' => $this->getKey()], $truncated);
    }

    // ------------------------------------------------------------------ Word

    private function fromDocx(ZipArchive $zip): ?string
    {
        $xpath = $this->xpath($zip, 'word/document.xml', ['w' => self::NS_W]);
        if (null === $xpath) {
            return null;
        }

        $this->headingStyles = $this->docxHeadingStyles($zip);

        $body = $xpath->query('//w:body')->item(0);
        if (!$body instanceof DOMElement) {
            return null;
        }

        $blocks = [];
        foreach ($body->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ('p' === $node->localName) {
                $block = $this->docxParagraph($xpath, $node);
                if (null !== $block) {
                    $blocks[] = $block;
                }
            } elseif ('tbl' === $node->localName) {
                $rows = [];
                foreach ($xpath->query('./w:tr', $node) as $tr) {
                    $cells = [];
                    foreach ($xpath->query('./w:tc', $tr) as $tc) {
                        $cells[] = $this->textOf($xpath, $tc, './/w:t');
                    }
                    if ([] !== $cells) {
                        $rows[] = $cells;
                    }
                }
                $table = $this->renderTable($rows, true);
                if (null !== $table) {
                    $blocks[] = $table;
                }
            }
        }

        return $this->joinBlocks($blocks);
    }

    /**
     * Welche Absatzstile dieser Datei Ueberschriften sind, aus word/styles.xml.
     *
     * Das Stil-Kuerzel allein genuegt nicht. Word legt es in der Sprache der Oberflaeche
     * an -- "Heading1", "berschrift1", "Titre1", "Titolo1", "Nagwek1" -- und bei einer
     * Firmenvorlage heisst es womoeglich "Kapitelueberschrift". Der Name in styles.xml
     * ist dagegen sprachunabhaengig "heading 1", und daneben steht oft die Gliederungs-
     * ebene. Mit dem Kuerzel allein blieben italienische, spanische und polnische
     * Dokumente ohne Gliederung, gemessen an erzeugten Testdateien.
     *
     * @return array<string, int> Stil-Kuerzel auf Ebene
     */
    private function docxHeadingStyles(ZipArchive $zip): array
    {
        $xpath = $this->xpath($zip, 'word/styles.xml', ['w' => self::NS_W]);
        if (null === $xpath) {
            return [];
        }

        $levels = [];
        foreach ($xpath->query('//w:style[@w:type="paragraph"]') as $style) {
            if (!$style instanceof DOMElement) {
                continue;
            }
            $id = self::styleKey((string) $xpath->evaluate('string(./@w:styleId)', $style));
            if ('' === $id) {
                continue;
            }

            $level = 0;
            $name = self::styleKey((string) $xpath->evaluate('string(./w:name/@w:val)', $style));
            if (preg_match('/^heading([1-9])$/', $name, $match)) {
                $level = (int) $match[1];
            } elseif (in_array($name, ['title', 'subtitle'], true)) {
                $level = 1;
            }

            if (0 === $level) {
                $outline = (string) $xpath->evaluate('string(./w:pPr/w:outlineLvl/@w:val)', $style);
                if ('' !== $outline && ctype_digit($outline)) {
                    $level = (int) $outline + 1;
                }
            }

            if ($level > 0) {
                $levels[$id] = min(6, $level);
            }
        }

        return $levels;
    }

    /**
     * Vergleichsform eines Stilnamens: "Heading 1", "heading1" und "Heading-1" sind
     * dasselbe.
     */
    private static function styleKey(string $value): string
    {
        return mb_strtolower(str_replace([' ', '-', '_'], '', $value));
    }

    private function docxParagraph(DOMXPath $xpath, DOMElement $paragraph): ?string
    {
        $text = $this->textOf($xpath, $paragraph, './/w:t');
        if ('' === $text) {
            return null;
        }

        $level = $this->docxHeadingLevel($xpath, $paragraph);
        if (null !== $level) {
            return str_repeat('#', $level) . ' ' . $text;
        }

        // Nummerierung ist das verlaessliche Merkmal einer Liste, nicht der Absatzstil.
        if ($xpath->query('.//w:numPr', $paragraph)->length > 0) {
            return '- ' . $text;
        }

        return $text;
    }

    private function docxHeadingLevel(DOMXPath $xpath, DOMElement $paragraph): ?int
    {
        $style = self::styleKey((string) $xpath->evaluate('string(.//w:pStyle/@w:val)', $paragraph));
        if (isset($this->headingStyles[$style])) {
            return $this->headingStyles[$style];
        }

        // Ohne styles.xml -- oder bei einem Stil, der dort nicht steht -- bleibt das
        // Kuerzel als Anhaltspunkt. Deckt die haeufigsten Sprachen ab.
        if (preg_match('/^(?:heading|berschrift|titre|kop|titolo|ttulo|nagwek)([1-9])$/', $style, $match)) {
            return min(6, max(1, (int) $match[1]));
        }
        if (in_array($style, ['title', 'titel'], true)) {
            return 1;
        }

        $outline = (string) $xpath->evaluate('string(.//w:outlineLvl/@w:val)', $paragraph);
        if ('' !== $outline && ctype_digit($outline)) {
            return min(6, (int) $outline + 1);
        }

        return null;
    }

    // ------------------------------------------------------------------ Excel

    private function fromXlsx(ZipArchive $zip): ?string
    {
        $shared = $this->xlsxSharedStrings($zip);
        $blocks = [];

        foreach ($this->xlsxSheets($zip) as $name => $entry) {
            $xpath = $this->xpath($zip, $entry, ['s' => self::NS_S]);
            if (null === $xpath) {
                continue;
            }

            $rows = [];
            foreach ($xpath->query('//s:sheetData/s:row') as $row) {
                if (count($rows) >= self::MAX_SHEET_ROWS) {
                    break;
                }
                $cells = [];
                foreach ($xpath->query('./s:c', $row) as $cell) {
                    if (!$cell instanceof DOMElement) {
                        continue;
                    }
                    $index = $this->columnIndex((string) $cell->getAttribute('r'));
                    if ($index >= self::MAX_SHEET_COLUMNS) {
                        continue;
                    }
                    $cells[$index] = $this->xlsxCellValue($xpath, $cell, $shared);
                }
                if ([] === array_filter($cells, static fn (string $v): bool => '' !== $v)) {
                    continue;
                }
                $width = max(array_keys($cells)) + 1;
                $rows[] = array_map(static fn (int $i): string => $cells[$i] ?? '', range(0, $width - 1));
            }

            $table = $this->renderTable($this->padRows($rows), true);
            if (null === $table) {
                continue;
            }
            $blocks[] = '## ' . $name;
            $blocks[] = $table;
        }

        return $this->joinBlocks($blocks);
    }

    /**
     * @return list<string>
     */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xpath = $this->xpath($zip, 'xl/sharedStrings.xml', ['s' => self::NS_S]);
        if (null === $xpath) {
            return [];
        }

        $strings = [];
        foreach ($xpath->query('//s:si') as $si) {
            $strings[] = $this->textOf($xpath, $si, './/s:t');
        }

        return $strings;
    }

    /**
     * Blattnamen in Dokumentreihenfolge, zugeordnet zum Eintrag im Archiv.
     *
     * @return array<string, string>
     */
    private function xlsxSheets(ZipArchive $zip): array
    {
        $rels = [];
        $relPath = $this->xpath($zip, 'xl/_rels/workbook.xml.rels', []);
        if (null !== $relPath) {
            foreach ($relPath->query('//*[local-name()="Relationship"]') as $rel) {
                if ($rel instanceof DOMElement) {
                    $rels[$rel->getAttribute('Id')] = ltrim($rel->getAttribute('Target'), '/');
                }
            }
        }

        $workbook = $this->xpath($zip, 'xl/workbook.xml', ['s' => self::NS_S, 'r' => self::NS_R]);
        $sheets = [];
        if (null !== $workbook) {
            $index = 0;
            foreach ($workbook->query('//s:sheets/s:sheet') as $sheet) {
                if (!$sheet instanceof DOMElement) {
                    continue;
                }
                ++$index;
                $name = (string) $sheet->getAttribute('name');
                $id = $sheet->getAttributeNS(self::NS_R, 'id');
                $target = $rels[$id] ?? ('worksheets/sheet' . $index . '.xml');
                $entry = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                $sheets['' === $name ? 'Tabelle ' . $index : $name] = $entry;
            }
        }

        if ([] !== $sheets) {
            return $sheets;
        }

        // Ohne workbook.xml: alle Blaetter in Archivreihenfolge
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('~^xl/worksheets/sheet(\d+)\.xml$~', $entry, $match)) {
                $sheets['Tabelle ' . $match[1]] = $entry;
            }
        }

        return $sheets;
    }

    /**
     * @param list<string> $shared
     */
    private function xlsxCellValue(DOMXPath $xpath, DOMElement $cell, array $shared): string
    {
        $type = (string) $cell->getAttribute('t');

        if ('s' === $type) {
            $index = (int) $this->textOf($xpath, $cell, './s:v');

            return $shared[$index] ?? '';
        }
        if ('inlineStr' === $type) {
            return $this->textOf($xpath, $cell, './/s:t');
        }

        return $this->textOf($xpath, $cell, './s:v');
    }

    /**
     * "BC12" -> 54. Fehlt der Bezug, liefert die Methode 0.
     */
    private function columnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/', $reference, $match)) {
            return 0;
        }

        $index = 0;
        foreach (str_split($match[1]) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return max(0, $index - 1);
    }

    // ------------------------------------------------------------------ PowerPoint

    private function fromPptx(ZipArchive $zip): ?string
    {
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $entry, $match)) {
                $slides[(int) $match[1]] = $entry;
            }
        }
        ksort($slides);

        $blocks = [];
        foreach ($slides as $number => $entry) {
            $xpath = $this->xpath($zip, $entry, ['a' => self::NS_A]);
            if (null === $xpath) {
                continue;
            }

            $lines = [];
            foreach ($xpath->query('//a:p') as $paragraph) {
                $text = $this->textOf($xpath, $paragraph, './/a:t');
                if ('' !== $text) {
                    $lines[] = $text;
                }
            }
            if ([] === $lines) {
                continue;
            }

            // Die erste Zeile einer Folie ist in aller Regel ihr Titel.
            $blocks[] = '## ' . array_shift($lines);
            if ([] !== $lines) {
                $blocks[] = implode("\n", array_map(static fn (string $l): string => '- ' . $l, $lines));
            }
            unset($number);
        }

        return $this->joinBlocks($blocks);
    }

    // ------------------------------------------------------------------ OpenDocument

    private function fromOpenDocument(ZipArchive $zip): ?string
    {
        $xpath = $this->xpath($zip, 'content.xml', [
            'text' => self::NS_TEXT,
            'table' => self::NS_TABLE,
            'office' => self::NS_OFFICE,
        ]);
        if (null === $xpath) {
            return null;
        }

        $body = $xpath->query('//office:body')->item(0);
        if (!$body instanceof DOMElement) {
            return null;
        }

        return $this->joinBlocks($this->odfBlocks($xpath, $body));
    }

    /**
     * @return list<string>
     */
    private function odfBlocks(DOMXPath $xpath, DOMNode $parent): array
    {
        $blocks = [];
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            switch ($node->localName) {
                case 'h':
                    $text = $this->plain($node->textContent);
                    if ('' !== $text) {
                        $level = (int) $node->getAttributeNS(self::NS_TEXT, 'outline-level');
                        $blocks[] = str_repeat('#', min(6, max(1, $level))) . ' ' . $text;
                    }
                    break;
                case 'p':
                    $text = $this->plain($node->textContent);
                    if ('' !== $text) {
                        $blocks[] = $text;
                    }
                    break;
                case 'list':
                    $items = [];
                    foreach ($xpath->query('.//text:list-item', $node) as $item) {
                        $text = $this->plain($item->textContent);
                        if ('' !== $text) {
                            $items[] = '- ' . $text;
                        }
                    }
                    if ([] !== $items) {
                        $blocks[] = implode("\n", $items);
                    }
                    break;
                case 'table':
                    $rows = [];
                    // Die Kopfzeile steht in einem eigenen Element und muss zuerst kommen.
                    $rowNodes = array_merge(
                        iterator_to_array($xpath->query('./table:table-header-rows/table:table-row', $node)),
                        iterator_to_array($xpath->query('./table:table-row', $node)),
                    );
                    foreach ($rowNodes as $row) {
                        $cells = [];
                        foreach ($xpath->query('./table:table-cell', $row) as $cell) {
                            if (!$cell instanceof DOMElement) {
                                continue;
                            }
                            $repeat = (int) ($cell->getAttributeNS(self::NS_TABLE, 'number-columns-repeated') ?: '1');
                            $value = $this->plain($cell->textContent);
                            for ($i = 0; $i < min(max(1, $repeat), self::MAX_SHEET_COLUMNS); ++$i) {
                                $cells[] = $value;
                            }
                        }
                        if ([] !== array_filter($cells, static fn (string $v): bool => '' !== $v)) {
                            $rows[] = $cells;
                        }
                        if (count($rows) >= self::MAX_SHEET_ROWS) {
                            break;
                        }
                    }
                    $table = $this->renderTable($this->padRows($rows), true);
                    if (null !== $table) {
                        $blocks[] = $table;
                    }
                    break;
                default:
                    // office:text, office:spreadsheet, table:table-header-rows und aehnliche Huellen
                    foreach ($this->odfBlocks($xpath, $node) as $block) {
                        $blocks[] = $block;
                    }
            }
        }

        return $blocks;
    }

    // ------------------------------------------------------------------ Gemeinsames

    /**
     * @param array<string, string> $namespaces
     */
    private function xpath(ZipArchive $zip, string $entry, array $namespaces): ?DOMXPath
    {
        $index = $zip->locateName($entry);
        if (false === $index) {
            return null;
        }

        $stat = $zip->statIndex($index);
        if (false !== $stat && ($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
            return null;
        }

        $xml = $zip->getFromIndex($index);
        if (false === $xml || '' === $xml) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        foreach ($namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    private function textOf(DOMXPath $xpath, DOMNode $context, string $query): string
    {
        $parts = [];
        foreach ($xpath->query($query, $context) as $node) {
            $parts[] = $node->textContent;
        }

        return $this->plain(implode('', $parts));
    }

    private function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Alle Zeilen auf dieselbe Spaltenzahl bringen.
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function padRows(array $rows): array
    {
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, count($row));
        }

        return array_map(static fn (array $row): array => array_pad($row, $width, ''), $rows);
    }

    /**
     * @param list<list<string>> $rows
     */
    private function renderTable(array $rows, bool $firstRowIsHeader): ?string
    {
        if (count($rows) < 2) {
            return null;
        }

        $columns = count($rows[0]);
        if ($columns < 2) {
            return null;
        }

        $render = static function (array $cells) use ($columns): string {
            $cells = array_pad(array_slice($cells, 0, $columns), $columns, '');
            $cells = array_map(static fn (string $cell): string => str_replace('|', '\\|', $cell), $cells);

            return '| ' . implode(' | ', $cells) . ' |';
        };

        $header = $firstRowIsHeader ? array_shift($rows) : array_fill(0, $columns, '');
        $lines = [$render($header), '|' . str_repeat(' --- |', $columns)];
        foreach ($rows as $row) {
            $lines[] = $render($row);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $blocks
     */
    private function joinBlocks(array $blocks): ?string
    {
        $blocks = array_values(array_filter($blocks, static fn (string $block): bool => '' !== trim($block)));
        if ([] === $blocks) {
            return null;
        }

        // Jeder Listenpunkt kommt als eigener Absatz an; nebeneinanderliegende gehoeren
        // zu einer Liste und werden ohne Leerzeile verbunden.
        $merged = [];
        foreach ($blocks as $block) {
            $isItem = str_starts_with($block, '- ');
            $previous = $merged[count($merged) - 1] ?? null;
            if ($isItem && null !== $previous && str_starts_with($previous, '- ')) {
                $merged[count($merged) - 1] = $previous . "\n" . $block;
                continue;
            }
            $merged[] = $block;
        }

        return implode("\n\n", $merged);
    }

    private function readTitle(string $markdown): ?string
    {
        if (preg_match('/^#{1,6}\s+(.+)$/m', $markdown, $match)) {
            return trim($match[1]);
        }

        return null;
    }
}
