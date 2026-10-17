<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk\Strategy;

use FriendsOfRedaxo\Search\Chunk\Chunk;
use FriendsOfRedaxo\Search\Chunk\ChunkInput;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategy;
use rex_i18n;

/**
 * Ein Abschnitt je Dokument, ungeteilt.
 *
 * Fuer alles, was ohnehin kurz ist: YForm-Datensaetze, Metadaten, Medienangaben. Am
 * Bestand gemessen sind das Zeilen zwischen 35 und 178 Zeichen -- die zu teilen gaebe
 * nichts her, und jede davon einzeln einzubetten kostet trotzdem einen Aufruf.
 */
final class WholeDocumentChunkStrategy extends ChunkStrategy
{
    public function getKey(): string
    {
        return 'whole';
    }

    public function getLabel(): string
    {
        return rex_i18n::rawMsg('search_chunk_whole');
    }

    public function getDescription(): string
    {
        return rex_i18n::rawMsg('search_chunk_whole_description');
    }

    public function split(ChunkInput $input, array $config): array
    {
        // Der Titel gehoert in den Text. Bei einem Datensatz, der nur aus einem Feldwert
        // besteht, ist er oft die einzige Angabe, die ihn einordnet.
        $text = trim($input->title . "\n\n" . $input->content);

        return '' === $text ? [] : [new Chunk($text)];
    }
}
