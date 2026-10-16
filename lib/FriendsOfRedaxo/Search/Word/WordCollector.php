<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Word;

use function count;

/**
 * Sammelt waehrend eines Indexlaufs den Wortschatz einer Quelle.
 *
 * Gesammelt wird aus dem Text, der ohnehin durch die Hand geht, also ohne einen einzigen
 * zusaetzlichen Lesezugriff. Gemessen kostet das rund zwei Prozent der Laufzeit eines
 * Indexlaufs.
 *
 * Der Sammler haelt die Zaehlung im Speicher und wird am Ende des Laufs in einem Zug
 * geschrieben. Das ist Absicht: nur ein vollstaendig durchgelaufener Lauf kennt den
 * Wortschatz einer Quelle vollstaendig, und nur dann darf der alte ersetzt werden. Bricht
 * der Lauf ab, bleibt der bisherige Stand stehen.
 *
 * Die Sprache steht am Sammler und nicht am einzelnen Text: sie gehoert der Quelle. Ein
 * Dokument, das mehrere Sprachen mischt, laesst sich nachtraeglich nicht mehr trennen --
 * deshalb muss eine Quelle einsprachig sein, siehe {@see FriendsOfRedaxo\Search\Language}.
 */
final class WordCollector
{
    /**
     * Kleingeschriebenes Wort auf Schreibweise und Dokumentzahl.
     *
     * @var array<string, array{word: string, docs: int}>
     */
    private array $words = [];

    public function __construct(
        private readonly int $sourceId,
        private readonly string $language = '',
    ) {}

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Nimmt den Text eines Dokuments auf. Jedes Wort zaehlt je Dokument einmal.
     */
    public function add(string $text): void
    {
        foreach (WordFolder::tokenize($text) as $word) {
            $key = mb_strtolower($word);
            if (isset($this->words[$key])) {
                ++$this->words[$key]['docs'];
                $this->words[$key]['word'] = WordFolder::preferredSpelling($this->words[$key]['word'], $word);
                continue;
            }
            $this->words[$key] = ['word' => $word, 'docs' => 1];
        }
    }

    public function count(): int
    {
        return count($this->words);
    }

    public function isEmpty(): bool
    {
        return [] === $this->words;
    }

    /**
     * @return list<array{word: string, docs: int}>
     */
    public function toArray(): array
    {
        return array_values($this->words);
    }
}
