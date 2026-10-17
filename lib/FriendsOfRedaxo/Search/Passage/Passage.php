<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Passage;

/**
 * Ein Textabschnitt als Antwortbaustein.
 *
 * Bewusst etwas anderes als ein FriendsOfRedaxo\Search\Query\SearchHit. Ein Treffer ist
 * ein Dokument mit einem Ausschnitt rund um die erste Fundstelle -- gut fuer eine
 * Trefferliste, unbrauchbar als Vorlage fuer eine Antwort. Eine Passage ist der Abschnitt
 * selbst, mit dem Weg durch die Ueberschriften bis dorthin, damit eine Antwortmaschine
 * zitieren und verlinken kann, statt zu behaupten.
 */
final class Passage
{
    /**
     * @param float $similarity 1 heisst deckungsgleich, 0 heisst nichts miteinander zu tun
     */
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly int $sourceId,
        public readonly string $namespace,
        public readonly string $type,
        public readonly string $itemId,
        public readonly string $title,
        public readonly string $url,
        public readonly string $language,
        public readonly string $headingPath,
        public readonly string $text,
        public readonly int $chunkNo,
        public readonly int $chunkTotal,
        public readonly float $similarity,
    ) {}

    /**
     * Woher dieser Abschnitt stammt, in einer Zeile.
     */
    public function getLabel(): string
    {
        $label = '' !== $this->headingPath ? $this->headingPath : $this->title;
        if ('' === $label) {
            $label = $this->namespace . ' ' . $this->itemId;
        }
        if (1 < $this->chunkTotal) {
            $label .= ' (' . ($this->chunkNo + 1) . '/' . $this->chunkTotal . ')';
        }

        return $label;
    }

    public function getCharacters(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'source_id' => $this->sourceId,
            'namespace' => $this->namespace,
            'type' => $this->type,
            'item_id' => $this->itemId,
            'title' => $this->title,
            'url' => $this->url,
            'language' => $this->language,
            'heading_path' => $this->headingPath,
            'text' => $this->text,
            'chunk_no' => $this->chunkNo,
            'chunk_total' => $this->chunkTotal,
            'similarity' => round($this->similarity, 4),
        ];
    }
}
