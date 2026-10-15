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
 */
final class WordCollector
{
    /**
     * Wort und Sprache auf Dokumentzahl.
     *
     * @var array<string, array{word: string, clang: int, docs: int}>
     */
    private array $words = [];

    public function __construct(
        private readonly int $sourceId,
    ) {}

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    /**
     * Nimmt den Text eines Dokuments auf. Jedes Wort zaehlt je Dokument einmal.
     */
    public function add(string $text, ?int $clangId): void
    {
        $clang = $clangId ?? 0;
        foreach (WordFolder::tokenize($text) as $word) {
            $key = $clang . "\0" . mb_strtolower($word);
            if (isset($this->words[$key])) {
                ++$this->words[$key]['docs'];
                $this->words[$key]['word'] = WordFolder::preferredSpelling($this->words[$key]['word'], $word);
                continue;
            }
            $this->words[$key] = ['word' => $word, 'clang' => $clang, 'docs' => 1];
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
     * @return list<array{word: string, clang: int, docs: int}>
     */
    public function toArray(): array
    {
        return array_values($this->words);
    }
}
