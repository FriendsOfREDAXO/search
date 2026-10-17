<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

use DateTimeImmutable;
use rex;
use rex_sql;

use function count;

/**
 * Schreibt und liest rex_search_chunk.
 *
 * Nicht final, damit Tests die Datenbankzugriffe durch ein Doppel ersetzen koennen.
 */
class ChunkRepository
{
    public static function table(): string
    {
        return rex::getTable('search_chunk');
    }

    /**
     * Bringt die Abschnitte eines Dokuments auf den neuen Stand.
     *
     * Nicht loeschen und neu schreiben, sondern abgleichen -- und zwar ueber den Hash,
     * nicht ueber die Position. Das ist der Unterschied, der zaehlt: an einem Dokument
     * aendert sich meist ein Absatz, und wer danach alle Abschnitte neu anlegt, wirft
     * auch alle Vektoren weg, die daran haengen. Ueber den Hash bleibt ein Abschnitt
     * selbst dann derselbe, wenn er durch eine Einfuegung weiter nach hinten gerutscht
     * ist; nur seine Nummer wird nachgezogen.
     *
     * @param list<Chunk> $chunks
     * @return array{kept: int, written: int, deleted: int}
     */
    public function syncDocument(int $sourceId, int $documentId, array $chunks): array
    {
        $existing = [];
        foreach (rex_sql::factory()->getArray(
            'SELECT id, chunk_hash FROM ' . self::table() . ' WHERE document_id = :d',
            ['d' => $documentId],
        ) as $row) {
            $existing[(string) $row['chunk_hash']][] = (int) $row['id'];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $total = count($chunks);
        $kept = 0;
        $written = 0;
        $used = [];

        foreach ($chunks as $number => $chunk) {
            $hash = $chunk->getHash();
            $id = null;
            if (isset($existing[$hash]) && [] !== $existing[$hash]) {
                $id = array_shift($existing[$hash]);
                ++$kept;
            }

            $sql = rex_sql::factory();
            $sql->setTable(self::table());
            $sql->setValue('chunk_no', $number);
            $sql->setValue('chunk_total', $total);

            if (null !== $id) {
                // Inhalt und Hash sind gleich geblieben, sonst waere der Hash ein anderer.
                $sql->setWhere(['id' => $id]);
                $sql->update();
                $used[] = $id;
                continue;
            }

            $sql->setValue('source_id', $sourceId);
            $sql->setValue('document_id', $documentId);
            $sql->setValue('heading_path', self::shortenPath($chunk->headingPath));
            $sql->setValue('content', $chunk->text);
            $sql->setValue('chunk_hash', $hash);
            $sql->setValue('chars', mb_strlen($chunk->text));
            $sql->setValue('created_at', $now);
            $sql->insert();
            $used[] = (int) $sql->getLastId();
            ++$written;
        }

        $deleted = $this->deleteByDocument($documentId, $used);

        return ['kept' => $kept, 'written' => $written, 'deleted' => $deleted];
    }

    /**
     * Kuerzt einen zu langen Ueberschriftenpfad -- von vorn, nicht von hinten.
     *
     * Die Spalte fasst 255 Zeichen. Vorn steht der Titel des Dokuments, hinten die
     * Ueberschrift, unter der ein Abschnitt tatsaechlich steht; von hinten zu kuerzen
     * naehme ausgerechnet die Angabe weg, die den Abschnitt einordnet.
     */
    public static function shortenPath(string $path): string
    {
        if (255 >= mb_strlen($path)) {
            return $path;
        }

        return '… ' . mb_substr($path, -253);
    }

    /**
     * @param list<int> $keepIds
     */
    public function deleteByDocument(int $documentId, array $keepIds = []): int
    {
        $query = 'DELETE FROM ' . self::table() . ' WHERE document_id = :d';
        $params = ['d' => $documentId];

        if ([] !== $keepIds) {
            $names = [];
            foreach (array_values($keepIds) as $i => $id) {
                $names[] = ':k' . $i;
                $params['k' . $i] = $id;
            }
            $query .= ' AND id NOT IN (' . implode(', ', $names) . ')';
        }

        $sql = rex_sql::factory();
        $sql->setQuery($query, $params);

        return $sql->getRows();
    }

    public function deleteBySource(int $sourceId): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source_id = :s', ['s' => $sourceId]);
    }

    /**
     * Abschnitte zu Dokumenten, die es nicht mehr gibt.
     *
     * Ein Dokument verschwindet aus dem Index, ohne dass jemand die Abschnitte fragt --
     * deleteStale() loescht nach Zeitstempel, deleteByItem() nach Datensatz. Statt beide
     * Wege zu ergaenzen und den dritten zu vergessen, wird hinterher aufgeraeumt.
     */
    public function deleteOrphans(string $indexTable): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE c FROM ' . self::table() . ' c'
            . ' LEFT JOIN ' . $indexTable . ' i ON i.id = c.document_id'
            . ' WHERE i.id IS NULL',
        );

        return $sql->getRows();
    }

    public function countBySource(int $sourceId): int
    {
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table() . ' WHERE source_id = :s', ['s' => $sourceId]);

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function countAll(): int
    {
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table());

        return (int) ($rows[0]['c'] ?? 0);
    }
}
