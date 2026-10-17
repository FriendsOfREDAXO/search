<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Passage;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * Die Antwortbausteine zu einer Frage.
 *
 * @implements IteratorAggregate<int, Passage>
 */
final class PassageResult implements IteratorAggregate, Countable
{
    /**
     * @param list<Passage> $passages
     * @param int $eligible Wie viele Abschnitte die Schwelle bestanden haben. Mehr als
     *                      $passages heisst: der Rest passte nicht mehr hinein
     * @param float $bestSimilarity Die Aehnlichkeit des naechsten Abschnitts, auch wenn
     *                              er unter der Schwelle lag -- daran laesst sich
     *                              ablesen, ob eine leere Antwort knapp war
     */
    public function __construct(
        public readonly array $passages,
        public readonly PassageQuery $query,
        public readonly int $eligible = 0,
        public readonly float $bestSimilarity = 0.0,
        public readonly bool $truncated = false,
    ) {}

    public static function empty(PassageQuery $query, float $bestSimilarity = 0.0): self
    {
        return new self([], $query, 0, $bestSimilarity);
    }

    /**
     * @return list<Passage>
     */
    public function getPassages(): array
    {
        return $this->passages;
    }

    public function isEmpty(): bool
    {
        return [] === $this->passages;
    }

    public function count(): int
    {
        return count($this->passages);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->passages);
    }

    public function getCharacters(): int
    {
        $characters = 0;
        foreach ($this->passages as $passage) {
            $characters += $passage->getCharacters();
        }

        return $characters;
    }

    /**
     * Die Passagen als Textblock fuer eine Anfrage an ein Sprachmodell.
     *
     * Eine Bequemlichkeit, keine Vorschrift -- wer es anders braucht, laeuft selbst
     * ueber die Passagen. Die Nummerierung ist der Grund, warum es sie trotzdem gibt:
     * ein Modell kann sich darauf beziehen ("laut Quelle 2"), und der Aufrufer findet
     * ueber dieselbe Nummer die URL zum Verlinken.
     */
    public function toPromptContext(): string
    {
        $parts = [];
        foreach ($this->passages as $i => $passage) {
            $parts[] = '[' . ($i + 1) . '] ' . $passage->getLabel() . "\n" . $passage->text;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Passage $p): array => $p->toArray(), $this->passages);
    }
}
