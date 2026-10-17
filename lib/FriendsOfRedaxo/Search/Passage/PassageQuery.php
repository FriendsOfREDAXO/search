<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Passage;

use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Vector\Similarity;

use function array_slice;

/**
 * Eine Frage an den Bestand, unveraenderlich.
 *
 *     $passages = (new Retriever())->retrieve(
 *         PassageQuery::create('Wie hoch ist die Gewerbesteuer?')
 *             ->withSourceIds(19, 20)
 *             ->withLanguages('de')
 *             ->withMaxCharacters(6000),
 *     );
 */
final class PassageQuery
{
    /**
     * Ab dieser Aehnlichkeit gilt ein Abschnitt als Antwort.
     *
     * Die Zahl und ihre Begruendung stehen bei {@see Similarity}; sie ist dieselbe wie
     * bei der hybriden Suche, weil beide dasselbe Modell fragen.
     */
    public const DEFAULT_MIN_SIMILARITY = Similarity::DEFAULT_MINIMUM;

    public const DEFAULT_MAX_PASSAGES = 8;

    /** Zeichen insgesamt. Acht Abschnitte zu 1.500 Zeichen sind rund 3.000 Token. */
    public const DEFAULT_MAX_CHARACTERS = 12000;

    /** @var list<int> */
    private array $sourceIds = [];

    /** @var list<string> */
    private array $namespaces = [];

    /** @var list<string> */
    private array $languages = [];

    private float $minSimilarity = self::DEFAULT_MIN_SIMILARITY;

    private int $maxPassages = self::DEFAULT_MAX_PASSAGES;

    private int $maxCharacters = self::DEFAULT_MAX_CHARACTERS;

    private function __construct(
        private readonly string $question,
    ) {}

    public static function create(string $question): self
    {
        return new self($question);
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    /** @return list<int> */
    public function getSourceIds(): array
    {
        return $this->sourceIds;
    }

    /** @return list<string> */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /** @return list<string> */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getMinSimilarity(): float
    {
        return $this->minSimilarity;
    }

    public function getMaxPassages(): int
    {
        return $this->maxPassages;
    }

    public function getMaxCharacters(): int
    {
        return $this->maxCharacters;
    }

    public function withSourceIds(int ...$sourceIds): self
    {
        $clone = clone $this;
        $clone->sourceIds = array_values(array_unique($sourceIds));

        return $clone;
    }

    public function withNamespaces(string ...$namespaces): self
    {
        $clone = clone $this;
        $clone->namespaces = array_values(array_unique($namespaces));

        return $clone;
    }

    public function withLanguages(string ...$languages): self
    {
        $clone = clone $this;
        $clone->languages = array_values(array_unique(array_map(Language::normalize(...), $languages)));

        return $clone;
    }

    /**
     * Ab welcher Aehnlichkeit ein Abschnitt mitgeliefert wird, 0 bis 1.
     *
     * 0 liefert immer etwas -- und zwar auch zu einer Frage, zu der nichts dasteht.
     */
    public function withMinSimilarity(float $minSimilarity): self
    {
        $clone = clone $this;
        $clone->minSimilarity = Similarity::clamp($minSimilarity);

        return $clone;
    }

    public function withMaxPassages(int $maxPassages): self
    {
        $clone = clone $this;
        $clone->maxPassages = max(1, $maxPassages);

        return $clone;
    }

    /**
     * Obergrenze fuer die Zeichen aller Passagen zusammen.
     *
     * Die Grenze gehoert hierher und nicht zum Aufrufer: ein Kontextfenster ist endlich,
     * und wer Passagen weiterreicht, soll nicht erst selbst nachzaehlen muessen.
     */
    public function withMaxCharacters(int $maxCharacters): self
    {
        $clone = clone $this;
        $clone->maxCharacters = max(200, $maxCharacters);

        return $clone;
    }

    /**
     * Nur fuer den Retriever: die Filterwerte als Spalte-zu-Werte-Abbildung.
     *
     * @return array<string, list<int|string>>
     */
    public function getFilters(): array
    {
        $filters = [];
        foreach ([
            'v.source_id' => $this->sourceIds,
            'i.namespace' => $this->namespaces,
            'i.language' => $this->languages,
        ] as $column => $values) {
            if ([] !== $values) {
                $filters[$column] = array_slice(array_values($values), 0, 500);
            }
        }

        return $filters;
    }
}
