<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use DateTimeImmutable;

/**
 * Ein Treffer der Suche.
 *
 * $snippet ist Klartext, nicht HTML. Wer ihn ausgibt, nimmt {@see getHighlightedSnippet()}
 * und bekommt eine maskierte Fassung mit mark-Elementen; der rohe Wert bleibt damit
 * gefahrlos fuer alles andere, etwa eine JSON-Antwort.
 */
final class SearchHit
{
    /**
     * @param array<string, mixed> $meta
     * @param float $score Die rohe Bewertung, unbegrenzt und vom Bestand abhaengig
     * @param float $relevance Anteil an der besten Bewertung dieser Suche, 0 bis 1
     * @param list<string> $words Die Suchwoerter, fuer das Hervorheben
     */
    public function __construct(
        public readonly int $id,
        public readonly int $sourceId,
        public readonly string $namespace,
        public readonly string $type,
        public readonly string $itemId,
        public readonly string $title,
        public readonly string $url,
        public readonly ?int $clangId,
        public readonly float $score,
        public readonly float $relevance = 0.0,
        public readonly string $snippet = '',
        public readonly array $meta = [],
        public readonly ?DateTimeImmutable $updatedAt = null,
        private readonly array $words = [],
    ) {}

    /**
     * Der Textausschnitt als HTML, Suchwoerter in mark-Elementen.
     */
    public function getHighlightedSnippet(): string
    {
        return Snippet::highlight($this->snippet, $this->words);
    }

    /**
     * Der Titel als HTML, Suchwoerter in mark-Elementen.
     */
    public function getHighlightedTitle(): string
    {
        return Snippet::highlight($this->title, $this->words);
    }

    public function getEscapedTitle(): string
    {
        return rex_escape($this->title);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->sourceId,
            'namespace' => $this->namespace,
            'type' => $this->type,
            'item_id' => $this->itemId,
            'title' => $this->title,
            'url' => $this->url,
            'clang_id' => $this->clangId,
            'score' => $this->score,
            'relevance' => $this->relevance,
            'snippet' => $this->snippet,
            'meta' => $this->meta,
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
