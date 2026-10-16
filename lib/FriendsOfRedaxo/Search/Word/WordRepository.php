<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Word;

use FriendsOfRedaxo\Search\Language;
use rex;
use rex_sql;

use function count;
use function in_array;

/**
 * Schreibt und liest rex_search_word.
 *
 * Nicht final, damit Tests die Datenbankzugriffe durch ein Doppel ersetzen koennen.
 */
class WordRepository
{
    /** So viele Zeilen gehen in eine INSERT-Anweisung. */
    private const INSERT_BATCH = 500;

    public static function table(): string
    {
        return rex::getTable('search_word');
    }

    /**
     * Ersetzt den Wortschatz einer Quelle vollstaendig.
     *
     * Loeschen und Neuschreiben statt Abgleichen: nur so verschwinden Woerter, die es in der
     * Quelle nicht mehr gibt, und bei ein paar zehntausend Zeilen ist der Unterschied in der
     * Laufzeit bedeutungslos. Woerter, die eine andere Quelle ebenfalls kennt, bleiben
     * unberuehrt, weil jede Quelle ihre eigenen Zeilen hat.
     */
    public function replaceSource(WordCollector $collector): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery('DELETE FROM ' . self::table() . ' WHERE source_id = :s', ['s' => $collector->getSourceId()]);

        $written = 0;
        $batch = [];
        foreach ($collector->toArray() as $entry) {
            $batch[] = $entry;
            if (count($batch) >= self::INSERT_BATCH) {
                $written += $this->insertBatch($collector->getSourceId(), $collector->getLanguage(), $batch);
                $batch = [];
            }
        }
        $written += $this->insertBatch($collector->getSourceId(), $collector->getLanguage(), $batch);

        return $written;
    }

    public function deleteSource(int $sourceId): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source_id = :s', ['s' => $sourceId]);
    }

    public function countAll(): int
    {
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table());

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function isEmpty(): bool
    {
        return [] === rex_sql::factory()->getArray('SELECT id FROM ' . self::table() . ' LIMIT 1');
    }

    /**
     * Woerter, die mit dem Praefix beginnen, haeufigste zuerst.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<array{word: string, docs: int}>
     */
    public function findByPrefix(string $prefix, array $sourceIds = [], array $languages = [], int $limit = 10): array
    {
        return $this->find('word_folded LIKE :needle', self::escapeLike(WordFolder::fold($prefix)) . '%', $sourceIds, $languages, $limit);
    }

    /**
     * Woerter, die den Teil irgendwo enthalten -- der Fall "Ruine" findet "Burgruine".
     *
     * Das ist ein voller Durchlauf durch die Wortliste, kein Index kann dabei helfen. Bei
     * den Groessen, um die es hier geht, kostet er gemessen rund sechs Millisekunden.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<array{word: string, docs: int}>
     */
    public function findByInfix(string $part, array $sourceIds = [], array $languages = [], int $limit = 10): array
    {
        return $this->find('word_folded LIKE :needle', '%' . self::escapeLike(WordFolder::fold($part)) . '%', $sourceIds, $languages, $limit);
    }

    /**
     * Kandidaten fuer eine Tippfehlerkorrektur: alle Woerter im erlaubten Laengenbereich.
     *
     * Der Abstand selbst wird nicht hier berechnet, sondern in PHP -- levenshtein() gibt es
     * in MySQL nicht, und ueber die Laenge vorzufiltern macht den Rest billig genug.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<array{word: string, folded: string, docs: int}>
     */
    public function findByLength(int $min, int $max, array $sourceIds = [], array $languages = [], int $limit = 20000): array
    {
        [$where, $params] = $this->scope($sourceIds, $languages);
        $where[] = 'word_length BETWEEN :min AND :max';
        $params['min'] = max(1, $min);
        $params['max'] = max(1, $max);

        $rows = rex_sql::factory()->getArray(
            'SELECT word, word_folded, SUM(docs) AS docs FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY word, word_folded LIMIT ' . max(1, $limit),
            $params,
        );

        $words = [];
        foreach ($rows as $row) {
            $words[] = ['word' => (string) $row['word'], 'folded' => (string) $row['word_folded'], 'docs' => (int) $row['docs']];
        }

        return $words;
    }

    /**
     * @param list<array{word: string, docs: int}> $batch
     */
    private function insertBatch(int $sourceId, string $language, array $batch): int
    {
        if ([] === $batch) {
            return 0;
        }

        $values = [];
        $params = [];
        foreach ($batch as $i => $entry) {
            $folded = WordFolder::fold($entry['word']);
            $values[] = "(:w{$i}, :f{$i}, :l{$i}, :s{$i}, :c{$i}, :d{$i})";
            $params["w{$i}"] = $entry['word'];
            $params["f{$i}"] = $folded;
            $params["l{$i}"] = min(255, mb_strlen($entry['word']));
            $params["s{$i}"] = $sourceId;
            $params["c{$i}"] = $language;
            $params["d{$i}"] = $entry['docs'];
        }

        rex_sql::factory()->setQuery(
            'INSERT INTO ' . self::table() . ' (word, word_folded, word_length, source_id, language, docs)'
            . ' VALUES ' . implode(', ', $values),
            $params,
        );

        return count($batch);
    }

    /**
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<array{word: string, docs: int}>
     */
    private function find(string $condition, string $needle, array $sourceIds, array $languages, int $limit): array
    {
        [$where, $params] = $this->scope($sourceIds, $languages);
        $where[] = $condition;
        $params['needle'] = $needle;

        $rows = rex_sql::factory()->getArray(
            'SELECT word, SUM(docs) AS docs FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY word ORDER BY docs DESC, word ASC LIMIT ' . max(1, $limit),
            $params,
        );

        $words = [];
        foreach ($rows as $row) {
            $words[] = ['word' => (string) $row['word'], 'docs' => (int) $row['docs']];
        }

        return $words;
    }

    /**
     * Einschraenkung auf Quellen und Sprachen.
     *
     * Bei der Sprache werden Quellen ohne Angabe immer mitgenommen: sie wuerden sonst aus
     * jeder sprachbezogenen Suche fallen, und auf einer Installation, auf der niemand eine
     * Sprache eingetragen hat, bliebe die Wortliste wirkungslos. Dass das vorher der
     * Regelfall war -- Medien und YForm hatten nie einen clang --, ist der Unterschied:
     * jetzt ist es eine Entscheidung und kein Zustand.
     *
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return array{list<string>, array<string, mixed>}
     */
    private function scope(array $sourceIds, array $languages): array
    {
        $where = [];
        $params = [];

        if ([] !== $sourceIds) {
            $names = [];
            foreach (array_values($sourceIds) as $i => $id) {
                $names[] = ':src' . $i;
                $params['src' . $i] = $id;
            }
            $where[] = 'source_id IN (' . implode(', ', $names) . ')';
        }

        if ([] !== $languages) {
            $names = [];
            foreach (array_values(self::withUnknown($languages)) as $i => $language) {
                $names[] = ':lang' . $i;
                $params['lang' . $i] = $language;
            }
            $where[] = 'language IN (' . implode(', ', $names) . ')';
        }

        if ([] === $where) {
            $where[] = '1';
        }

        return [$where, $params];
    }

    /**
     * Die Sprachen samt der leeren -- Quellen ohne Angabe gehoeren in jede Auswahl.
     *
     * @param list<string> $languages
     * @return list<string>
     */
    public static function withUnknown(array $languages): array
    {
        return in_array(Language::UNKNOWN, $languages, true) ? $languages : [...$languages, Language::UNKNOWN];
    }

    /**
     * Ein Prozentzeichen im Suchwort darf nicht zum Platzhalter werden.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
