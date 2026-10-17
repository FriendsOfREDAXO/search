<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

use FriendsOfRedaxo\Search\Chunk\Strategy\FixedChunkStrategy;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use rex_sql;

use function count;

/**
 * Baut die Abschnitte einer Quelle aus dem vorhandenen Index.
 *
 * Eigener Durchgang statt mitten im Indexlauf, aus demselben Grund wie bei der
 * Wortliste: so laesst sich die Zerlegung aendern, ohne jedes PDF noch einmal durch
 * pdftotext zu schicken. Gelesen werden nur Titel, Volltext und Originalinhalt.
 *
 * Nicht final, damit Tests das Lesen aus dem Index durch ein Doppel ersetzen koennen.
 */
class ChunkBuilder
{
    /** So viele Dokumente werden auf einmal gelesen. Hoeher waere bei PDFs unklug: ein
     *  einzelnes Dokument hat hier gemessen eine halbe Million Zeichen. */
    private const BATCH = 25;

    private readonly ChunkRepository $chunks;

    private readonly VectorStore $vectors;

    public function __construct(?ChunkRepository $chunks = null, ?VectorStore $vectors = null)
    {
        $this->chunks = $chunks ?? new ChunkRepository();
        $this->vectors = $vectors ?? new VectorStore();
    }

    /**
     * @return array{chunks: int, kept: int, written: int, deleted: int}
     */
    public function rebuildSource(Source $source): array
    {
        $sourceId = $source->requireId();

        if (!$source->createsChunks()) {
            $this->chunks->deleteBySource($sourceId);
            $this->vectors->deleteBySource($sourceId);

            return ['chunks' => 0, 'kept' => 0, 'written' => 0, 'deleted' => 0];
        }

        $strategy = $source->getChunkStrategy();
        $config = $source->config;

        $total = 0;
        $kept = 0;
        $written = 0;
        $deleted = 0;
        $offset = 0;

        while (true) {
            $rows = $this->readDocuments($sourceId, $offset, self::BATCH);
            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $input = new ChunkInput(
                    (string) $row['title'],
                    (string) $row['content'],
                    null === $row['content_raw'] ? null : (string) $row['content_raw'],
                    null === $row['content_raw_format'] ? null : (string) $row['content_raw_format'],
                );
                $chunks = $input->isEmpty() ? [] : self::enforceMaximum($strategy->split($input, $config), $config);
                $result = $this->chunks->syncDocument($sourceId, (int) $row['id'], $chunks);

                $total += count($chunks);
                $kept += $result['kept'];
                $written += $result['written'];
                $deleted += $result['deleted'];
            }

            $offset += self::BATCH;
        }

        // Dokumente, die der Indexlauf entfernt hat, lassen ihre Abschnitte zurueck --
        // und die wiederum ihre Vektoren.
        $deleted += $this->chunks->deleteOrphans(IndexRepository::table());
        $this->vectors->deleteOrphans();

        return ['chunks' => $total, 'kept' => $kept, 'written' => $written, 'deleted' => $deleted];
    }

    /**
     * Die Dokumente einer Quelle, seitenweise.
     *
     * @return list<array<string, mixed>>
     */
    protected function readDocuments(int $sourceId, int $offset, int $limit): array
    {
        return rex_sql::factory()->getArray(
            'SELECT id, title, content, content_raw, content_raw_format FROM ' . IndexRepository::table()
            . ' WHERE source_id = :s ORDER BY id LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['s' => $sourceId],
        );
    }

    /**
     * Teilt Abschnitte, die kein Einbettungsmodell annehmen wuerde.
     *
     * Hier und nicht im Verfahren, weil die Verfahren aus einer Registry kommen: eines,
     * das ein fremdes AddOn mitbringt, kann die Grenze nicht kennen -- und "Ganzes
     * Dokument" darf sie nicht kennen, sonst waere es nicht mehr das ganze Dokument.
     *
     * Der Ueberschriftenpfad bleibt an allen Teilen haengen; sie gehoeren weiter zu
     * derselben Stelle im Dokument.
     *
     * @param list<Chunk> $chunks
     * @param array<string, mixed> $config
     * @return list<Chunk>
     */
    public static function enforceMaximum(array $chunks, array $config): array
    {
        $result = [];
        foreach ($chunks as $chunk) {
            if (ChunkStrategy::MAX_CHUNK_CHARACTERS >= mb_strlen($chunk->text)) {
                $result[] = $chunk;
                continue;
            }
            foreach (TextSplitter::split($chunk->text, FixedChunkStrategy::size($config), FixedChunkStrategy::overlap($config)) as $piece) {
                $result[] = new Chunk($piece, $chunk->headingPath);
            }
        }

        return $result;
    }
}
