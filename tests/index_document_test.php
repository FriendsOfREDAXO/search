<?php

use FriendsOfRedaxo\Search\Document\IndexDocument;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_index_document_test extends TestCase
{
    public function testHashCoversTheOriginalContent(): void
    {
        $a = new IndexDocument('1', '1', 'Titel', 'Klartext', rawContent: '<p>Klartext</p>', rawFormat: IndexDocument::FORMAT_HTML);
        $b = new IndexDocument('1', '1', 'Titel', 'Klartext', rawContent: '<h1>Klartext</h1>', rawFormat: IndexDocument::FORMAT_HTML);

        // Der Klartext ist gleich, das Original nicht. Ohne den Hash ueber das Original
        // bliebe die geaenderte Struktur beim naechsten Aufbau unbemerkt liegen.
        self::assertNotSame($a->getContentHash(), $b->getContentHash());
    }

    public function testHashCoversTheFormat(): void
    {
        $a = new IndexDocument('1', '1', 'Titel', 'Text', rawContent: 'Text', rawFormat: IndexDocument::FORMAT_HTML);
        $b = new IndexDocument('1', '1', 'Titel', 'Text', rawContent: 'Text', rawFormat: IndexDocument::FORMAT_TEXT);

        self::assertNotSame($a->getContentHash(), $b->getContentHash());
    }

    public function testHashIsStableForEqualDocuments(): void
    {
        $a = new IndexDocument('1', '1', 'Titel', 'Text', rawContent: 'Text', rawFormat: IndexDocument::FORMAT_TEXT);
        $b = new IndexDocument('1', '1', 'Titel', 'Text', rawContent: 'Text', rawFormat: IndexDocument::FORMAT_TEXT);

        self::assertSame($a->getContentHash(), $b->getContentHash());
    }

    public function testOriginalContentIsOptional(): void
    {
        $document = new IndexDocument('1', '1', 'Titel', 'Text');

        self::assertNull($document->rawContent);
        self::assertNull($document->rawFormat);
    }
}
