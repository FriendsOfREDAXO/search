<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\Source;
use rex;
use rex_sql;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Schreibt und liest rex_search_index.
 *
 * Nicht final, damit Tests die Datenbankzugriffe durch ein Doppel ersetzen koennen.
 */
class IndexRepository
{
    public static function table(): string
    {
        return rex::getTable('search_index');
    }

    /**
     * Legt ein Dokument an oder aktualisiert es. Unveraenderte Dokumente (gleicher Hash) bekommen
     * nur einen neuen indexed_at-Stempel, damit {@see deleteStale()} sie nicht entfernt.
     *
     * @return bool true, wenn Inhalt geschrieben wurde
     */
    public function store(Source $source, string $namespace, string $type, IndexDocument $document, DateTimeImmutable $indexedAt): bool
    {
        $sourceId = $source->requireId();
        $hash = $document->getContentHash();
        $stamp = $indexedAt->format('Y-m-d H:i:s');

        $existing = rex_sql::factory()->getArray(
            'SELECT id, content_hash FROM ' . self::table() . ' WHERE source_id = :source AND index_id = :index',
            ['source' => $sourceId, 'index' => $document->indexId],
        );

        $sql = rex_sql::factory();
        $sql->setTable(self::table());

        if ([] !== $existing && $existing[0]['content_hash'] === $hash) {
            $sql->setWhere(['id' => (int) $existing[0]['id']]);
            $sql->setValue('indexed_at', $stamp);
            $sql->setValue('item_id', $document->itemId);
            $sql->setValue('namespace', $namespace);
            $sql->setValue('type', $type);
            $sql->update();

            return false;
        }

        $sql->setValue('source_id', $sourceId);
        $sql->setValue('namespace', $namespace);
        $sql->setValue('type', $type);
        $sql->setValue('item_id', $document->itemId);
        $sql->setValue('index_id', $document->indexId);
        $sql->setValue('title', mb_substr($document->title, 0, 255));
        $sql->setValue('content', $document->content);
        $sql->setValue('content_raw', $document->rawContent);
        $sql->setValue('content_raw_format', $document->rawFormat);
        $sql->setValue('url', mb_substr($document->url, 0, 1024));
        $sql->setValue('clang_id', $document->clangId);
        $sql->setValue('meta', json_encode($document->meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sql->setValue('content_hash', $hash);
        $sql->setValue('updated_at', $document->updatedAt?->format('Y-m-d H:i:s'));
        $sql->setValue('indexed_at', $stamp);

        if ([] !== $existing) {
            $sql->setWhere(['id' => (int) $existing[0]['id']]);
            $sql->update();
        } else {
            $sql->insert();
        }

        return true;
    }

    /**
     * Entfernt die Dokumente eines Datensatzes, optional bis auf die genannten index_ids.
     *
     * @param list<string> $keepIndexIds
     */
    public function deleteByItem(int $sourceId, string $itemId, array $keepIndexIds = []): int
    {
        $query = 'DELETE FROM ' . self::table() . ' WHERE source_id = :source AND item_id = :item';
        $params = ['source' => $sourceId, 'item' => $itemId];

        if ([] !== $keepIndexIds) {
            $placeholders = [];
            foreach (array_values($keepIndexIds) as $i => $indexId) {
                $placeholders[] = ':keep' . $i;
                $params['keep' . $i] = $indexId;
            }
            $query .= ' AND index_id NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = rex_sql::factory();
        $sql->setQuery($query, $params);

        return $sql->getRows();
    }

    /**
     * Entfernt alle Dokumente einer Quelle, die ein Komplettaufbau nicht angefasst hat.
     */
    public function deleteStale(int $sourceId, DateTimeImmutable $runStartedAt): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . self::table() . ' WHERE source_id = :source AND indexed_at < :stamp',
            ['source' => $sourceId, 'stamp' => $runStartedAt->format('Y-m-d H:i:s')],
        );

        return $sql->getRows();
    }

    public function deleteBySource(int $sourceId): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source_id = :source', ['source' => $sourceId]);
    }

    public function countBySource(int $sourceId): int
    {
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table() . ' WHERE source_id = :source', ['source' => $sourceId]);

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function countAll(): int
    {
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table());

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Einfache Volltextsuche im BOOLEAN MODE, gedacht fuer die Testsuche im Backend und als
     * Ausgangspunkt fuer die eigentliche Such-API.
     *
     * @param list<string> $namespaces
     * @param list<string> $types
     * @param list<int> $sourceIds
     * @return list<array<string, mixed>>
     */
    public function search(string $query, array $namespaces = [], array $types = [], int $limit = 20, array $sourceIds = []): array
    {
        $term = self::toBooleanQuery($query);
        if ('' === $term) {
            return [];
        }

        $where = ['MATCH (title, content) AGAINST (:term IN BOOLEAN MODE)'];
        $params = ['term' => $term];

        foreach (['namespace' => $namespaces, 'type' => $types, 'source_id' => $sourceIds] as $column => $values) {
            if ([] === $values) {
                continue;
            }
            $placeholders = [];
            foreach (array_values($values) as $i => $value) {
                $placeholders[] = ':' . $column . $i;
                $params[$column . $i] = $value;
            }
            $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
        }

        return rex_sql::factory()->getArray(
            'SELECT id, source_id, namespace, type, item_id, index_id, title, url, clang_id, updated_at,'
            . ' SUBSTRING(content, 1, 300) AS snippet,'
            . ' MATCH (title, content) AGAINST (:term IN BOOLEAN MODE) AS score'
            . ' FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY score DESC, id ASC'
            . ' LIMIT ' . max(1, $limit),
            $params,
        );
    }

    /**
     * Macht aus freier Eingabe eine BOOLEAN-MODE-Abfrage: jedes Wort ist Pflicht und darf
     * am Ende erweitert sein ("+such*"). Operatorzeichen des Nutzers werden entfernt.
     */
    public static function toBooleanQuery(string $query): string
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[+\-<>()~*"@]/u', ' ', $query))) ?: [];
        $parts = [];
        foreach ($words as $word) {
            if ('' === $word) {
                continue;
            }
            $parts[] = '+' . $word . '*';
        }

        return implode(' ', $parts);
    }
}
