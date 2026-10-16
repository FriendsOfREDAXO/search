<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Word;

use FriendsOfRedaxo\Search\Analysis\Analyzer;
use FriendsOfRedaxo\Search\Analysis\WordForms;
use rex;
use rex_sql;

use function count;

/**
 * Schreibt und liest rex_search_word_form, die Nachschlageformen des Wortschatzes.
 *
 * Nicht final, damit Tests die Datenbankzugriffe durch ein Doppel ersetzen koennen.
 */
class WordFormRepository
{
    /** So viele Zeilen gehen in eine INSERT-Anweisung. */
    private const INSERT_BATCH = 500;

    public static function table(): string
    {
        return rex::getTable('search_word_form');
    }

    /**
     * Ersetzt die Formen einer Quelle vollstaendig.
     *
     * Gibt es fuer die Sprache keine Analyse -- unbekannte Sprache, fehlende Bibliothek,
     * fehlendes ext-intl --, bleibt die Quelle ohne Formen. Die Suche faellt dann auf die
     * Suche nach enthaltenen Teilen zurueck, siehe {@see WordExpander}.
     */
    public function replaceSource(WordCollector $collector): int
    {
        $sourceId = $collector->getSourceId();
        $language = $collector->getLanguage();
        $this->deleteSource($sourceId);

        $rows = WordForms::build($collector->toArray(), Analyzer::forLanguage($language));
        if ([] === $rows) {
            return 0;
        }

        $written = 0;
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row;
            if (count($batch) >= self::INSERT_BATCH) {
                $written += $this->insertBatch($sourceId, $language, $batch);
                $batch = [];
            }
        }
        $written += $this->insertBatch($sourceId, $language, $batch);

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

    /**
     * Die Sprachen, zu denen ueberhaupt Formen vorliegen.
     *
     * Die Suche braucht sie, wenn die Anfrage keine Sprache vorgibt: sie muss das
     * eingegebene Wort je Sprache in eine Form bringen, und das ueber alle vierzehn
     * moeglichen zu tun hiesse, dreizehn Stammbildner fuer nichts zu bemuehen.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $rows = rex_sql::factory()->getArray('SELECT DISTINCT language FROM ' . self::table());

        $languages = [];
        foreach ($rows as $row) {
            $language = (string) $row['language'];
            if ('' !== $language) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /**
     * Die Woerter zu einer Menge von Formen, haeufigste zuerst.
     *
     * @param list<string> $forms
     * @param list<int> $sourceIds
     * @param list<string> $languages
     * @return list<array{word: string, docs: int}>
     */
    public function findByForms(array $forms, array $sourceIds = [], array $languages = [], int $limit = 20): array
    {
        if ([] === $forms) {
            return [];
        }

        $where = [];
        $params = [];

        $names = [];
        foreach (array_values($forms) as $i => $form) {
            $names[] = ':f' . $i;
            $params['f' . $i] = $form;
        }
        $where[] = 'form IN (' . implode(', ', $names) . ')';

        if ([] !== $sourceIds) {
            $names = [];
            foreach (array_values($sourceIds) as $i => $id) {
                $names[] = ':s' . $i;
                $params['s' . $i] = $id;
            }
            $where[] = 'source_id IN (' . implode(', ', $names) . ')';
        }

        if ([] !== $languages) {
            $names = [];
            foreach (array_values($languages) as $i => $language) {
                $names[] = ':l' . $i;
                $params['l' . $i] = $language;
            }
            $where[] = 'language IN (' . implode(', ', $names) . ')';
        }

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
     * @param list<array{form: string, word: string, docs: int}> $batch
     */
    private function insertBatch(int $sourceId, string $language, array $batch): int
    {
        if ([] === $batch) {
            return 0;
        }

        $values = [];
        $params = [];
        foreach ($batch as $i => $row) {
            $values[] = "(:o{$i}, :w{$i}, :s{$i}, :l{$i}, :d{$i})";
            $params["o{$i}"] = $row['form'];
            $params["w{$i}"] = $row['word'];
            $params["s{$i}"] = $sourceId;
            $params["l{$i}"] = $language;
            $params["d{$i}"] = $row['docs'];
        }

        rex_sql::factory()->setQuery(
            'INSERT INTO ' . self::table() . ' (form, word, source_id, language, docs)'
            . ' VALUES ' . implode(', ', $values),
            $params,
        );

        return count($batch);
    }
}
