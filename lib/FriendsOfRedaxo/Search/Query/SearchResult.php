<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * Das Ergebnis einer Suche: die Treffer dieser Seite und was man zum Blaettern braucht.
 *
 * Laesst sich direkt in einem Template durchlaufen:
 *
 *     foreach ($result as $hit) { ... }
 *
 * @implements IteratorAggregate<int, SearchHit>
 */
final class SearchResult implements IteratorAggregate, Countable
{
    /**
     * @param list<SearchHit> $hits Treffer dieser Seite
     * @param int $total Treffer insgesamt, siehe $truncated
     * @param bool $truncated Die Gesamtzahl ist an der Kandidatengrenze abgeschnitten
     * @param array<string, string> $corrections Eingegebenes Wort auf gesuchte Schreibweise
     * @param array<string, array<string, int>> $facets Spalte auf Auspraegung auf Anzahl
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly SearchQuery $query,
        public readonly SearchTerms $terms,
        public readonly bool $truncated = false,
        public readonly array $corrections = [],
        public readonly array $facets = [],
    ) {}

    public static function empty(SearchQuery $query, SearchTerms $terms): self
    {
        return new self([], 0, $query, $terms);
    }

    /**
     * @return list<SearchHit>
     */
    public function getHits(): array
    {
        return $this->hits;
    }

    public function getFirst(): ?SearchHit
    {
        return $this->hits[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->hits;
    }

    /**
     * Anzahl der Treffer auf dieser Seite, nicht insgesamt.
     */
    public function count(): int
    {
        return count($this->hits);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->hits);
    }

    public function getPage(): int
    {
        return intdiv($this->query->getOffset(), $this->query->getLimit()) + 1;
    }

    public function getPageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->query->getLimit()));
    }

    public function hasMore(): bool
    {
        return $this->query->getOffset() + count($this->hits) < $this->total;
    }

    /**
     * Die weggelassenen Woerter, damit die Oberflaeche sie benennen kann.
     *
     * @return array<string, string> Wort auf Grund
     */
    public function getIgnoredWords(): array
    {
        return $this->terms->ignored;
    }

    /**
     * Wurde die Eingabe als Tippfehler behandelt?
     */
    public function isCorrected(): bool
    {
        return [] !== $this->corrections;
    }

    /**
     * Die zusaetzlich gesuchten Formen, flach -- etwa "Burgruine" zu "Ruine".
     *
     * @return array<string, list<string>>
     */
    public function getExpansions(): array
    {
        return $this->terms->expansions;
    }

    /**
     * Die Haeufigkeitsverteilung einer Spalte, haeufigste zuerst.
     *
     * Gezaehlt ueber alle Treffer der Suche, nicht ueber die angezeigte Seite.
     *
     * @return array<string, int> Auspraegung auf Anzahl
     */
    public function getFacet(string $column): array
    {
        return $this->facets[$column] ?? [];
    }
}
