<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use FriendsOfRedaxo\Search\Word\WordFormRepository;
use FriendsOfRedaxo\Search\Word\WordRepository;
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
        $language = $source->getLanguage();
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
            // Die Sprache gehoert der Quelle, nicht dem Dokument, und geht deshalb nicht in
            // den Inhalts-Hash ein. Sie muss trotzdem auch hier mitgeschrieben werden, sonst
            // behielte eine unveraenderte Zeile nach einer Umstellung ihre alte Sprache.
            $sql->setValue('language', $language);
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
        $sql->setValue('language', $language);
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
    /**
     * Wie viele Dokumente dieser Quelle eine Markdown-Ueberschrift enthalten.
     *
     * Die Zerlegung nach Gliederung schneidet an Ueberschriften. Sind keine da, tut sie
     * nichts -- sie faellt auf feste Stuecke zurueck, ohne das zu sagen. Gemessen ist das
     * der haeufigere Fall, als man denkt: von acht PDFs im Beispielbestand hat eines eine
     * Ueberschrift, das groesste auf 900.000 Zeichen keine einzige.
     *
     * Ein voller Durchlauf ueber content_raw, kein Index hilft dabei. Gemessen 5 bis 11 ms
     * fuer 863 Dokumente -- vertretbar auf der Einstellungsseite, nicht in einer Suche.
     */
    public function countWithHeadings(int $sourceId): int
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT COUNT(*) AS anzahl FROM ' . self::table()
            . " WHERE source_id = :s AND content_raw_format = 'markdown'"
            . " AND content_raw REGEXP '(^|\\n)#{1,6}[[:space:]]'",
            ['s' => $sourceId],
        );

        return (int) ($rows[0]['anzahl'] ?? 0);
    }

    public function deleteStale(int $sourceId, DateTimeImmutable $runStartedAt): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . self::table() . ' WHERE source_id = :source AND indexed_at < :stamp',
            ['source' => $sourceId, 'stamp' => $runStartedAt->format('Y-m-d H:i:s')],
        );

        return $sql->getRows();
    }

    /**
     * Entfernt alle Dokumente einer Quelle, samt ihres Wortschatzes.
     *
     * Die Wortliste ist aus dem Index abgeleitet; verschwindet der Index einer Quelle, darf
     * ihr Wortschatz nicht stehen bleiben. Das gehoert hierher und nicht an die Aufrufer:
     * es gibt drei davon -- Quelle geloescht, Quelle nach Konfigurationsaenderung neu
     * aufgebaut, Quelle nach Aenderung deaktiviert -- und beim zweiten und dritten habe ich
     * es zuerst vergessen. Beim Neuaufbau schreibt der Indexer die Woerter danach ohnehin
     * wieder. Dasselbe gilt fuer Nachschlageformen, Abschnitte und Vektoren: alle drei
     * zeigen auf etwas, das es dann nicht mehr gibt.
     */
    public function deleteBySource(int $sourceId): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source_id = :source', ['source' => $sourceId]);
        (new WordRepository())->deleteSource($sourceId);
        (new WordFormRepository())->deleteSource($sourceId);
        (new ChunkRepository())->deleteBySource($sourceId);
        (new VectorStore())->deleteBySource($sourceId);
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
}
