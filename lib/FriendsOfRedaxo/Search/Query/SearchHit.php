<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use DateTimeImmutable;

use function count;

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
     * @param string $language Die Sprache der Quelle, leer wenn keine angegeben ist
     * @param float $score Die rohe Bewertung, unbegrenzt und vom Bestand abhaengig
     * @param float $relevance Anteil an der besten Bewertung dieser Suche, 0 bis 1
     * @param float|null $similarity Die Aehnlichkeit des besten Abschnitts, null wenn die
     *                               Bedeutung an diesem Treffer nicht beteiligt war
     * @param list<array{chunk_id: int, chunk_no: int, chunk_total: int, heading_path: string, text: string, similarity: float}> $passages
     *        Die Abschnitte, die den Ausschlag gaben, bester zuerst. Leer ohne
     *        semantische Suche. Damit laesst sich zeigen, *warum* ein Treffer dasteht --
     *        und ein Aufrufer kann daraus eine Antwort bauen, ohne die Passagen-API ein
     *        zweites Mal zu fragen.
     * @param list<array{id: int, source_id: int, type: string, score: float}> $group
     *        Die Indexzeilen, die zu diesem Treffer zusammengefasst wurden. Ein Artikel
     *        liegt einmal je Quelle im Index, die ihn erfasst; hier steht, welche das
     *        waren und wie sie bewertet wurden.
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
        public readonly string $language,
        public readonly float $score,
        public readonly float $relevance = 0.0,
        public readonly ?float $similarity = null,
        public readonly array $passages = [],
        public readonly array $group = [],
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
     * Ob die Bedeutung an diesem Treffer beteiligt war.
     */
    public function isSemantic(): bool
    {
        return null !== $this->similarity;
    }

    /**
     * Ueber wie viele Indexzeilen dieser Treffer zusammengefasst wurde.
     */
    public function countGrouped(): int
    {
        return max(1, count($this->group));
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
            'language' => $this->language,
            'score' => round($this->score, 6),
            'relevance' => round($this->relevance, 4),
            'similarity' => null === $this->similarity ? null : round($this->similarity, 4),
            'snippet' => $this->snippet,
            'meta' => $this->meta,
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'group' => $this->group,
            'passages' => $this->passages,
        ];
    }
}
