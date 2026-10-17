<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use FriendsOfRedaxo\Search\Chunk\Chunk;
use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use rex_sql;

use function count;
use function ini_get;

/**
 * Bettet offene Abschnitte ein.
 *
 * Offen heisst: es gibt keine Zeile in rex_search_vector dazu. Das ist mit Absicht kein
 * Zustandsfeld und keine Warteschlange -- ein abgebrochener Lauf hinterlaesst dann keinen
 * halben Zustand, den jemand aufraeumen muesste, und der naechste Lauf macht einfach da
 * weiter, wo Vektoren fehlen.
 *
 * Jeder Durchgang nimmt sich ein Budget: so viele Abschnitte oder so viele Sekunden. Der
 * Cronjob gibt Sekunden mit, damit er in sein Zeitfenster passt, die Fortschrittsanzeige
 * im Backend gibt eine Stueckzahl mit, damit die Antwort nicht auf sich warten laesst.
 */
final class Embedder
{
    /** Nach so vielen Fehlversuchen wird ein Abschnitt uebergangen. */
    public const MAX_ATTEMPTS = 3;

    /** Obergrenze fuer einen Lauf aus dem Backend, auch wenn der Server mehr erlaubt. */
    public const MAX_REQUEST_SECONDS = 90.0;

    /** Soviel Zeit bleibt dem Server fuer alles andere an der Anfrage. */
    private const REQUEST_RESERVE = 0.75;

    private readonly EmbeddingClient $client;

    private readonly VectorStore $store;

    public function __construct(?EmbeddingClient $client = null, ?VectorStore $store = null)
    {
        $this->client = $client ?? new EmbeddingClient();
        $this->store = $store ?? new VectorStore();
    }

    /**
     * Wie lange ein Lauf aus dem Backend dauern darf.
     *
     * Nicht geraten, sondern vom Server abgeleitet: laeuft die Anfrage in
     * max_execution_time, bricht PHP mitten im Lauf ab und der Benutzer bekommt eine
     * weisse Seite statt einer Meldung. Drei Viertel des Limits lassen Luft fuer den
     * Rest der Anfrage; die Obergrenze ist trotzdem gesetzt, weil eine Anfrage, die
     * anderthalb Minuten schweigt, auch bei erlaubten fuenf Minuten niemandem hilft --
     * fuer mehr gibt es den Cronjob und die Konsole.
     *
     * Ein Limit von 0 heisst unbegrenzt; dann gilt die Obergrenze.
     */
    public static function requestBudget(): float
    {
        $limit = (int) ini_get('max_execution_time');

        return 1 > $limit
            ? self::MAX_REQUEST_SECONDS
            : min(self::MAX_REQUEST_SECONDS, max(10.0, $limit * self::REQUEST_RESERVE));
    }

    /**
     * @param int|null $sourceId Nur diese Quelle, oder alle
     * @param int $maxChunks 0 heisst: so viele wie moeglich
     * @param float $maxSeconds 0 heisst: ohne Zeitgrenze
     */
    public function run(?int $sourceId = null, int $maxChunks = 0, float $maxSeconds = 0.0): EmbeddingResult
    {
        $start = microtime(true);

        if (!VectorSupport::isAvailable()) {
            return new EmbeddingResult(0, 0, 0, 0.0, 'Diese Datenbank kann keine Vektoren.');
        }
        if (!EmbeddingClient::isConfigured()) {
            return new EmbeddingResult(0, 0, $this->countOpen($sourceId), 0.0, 'Kein Embedding-Profil eingestellt.');
        }

        $embedded = 0;
        $failed = 0;
        $message = '';

        while (true) {
            if (0 < $maxChunks && $embedded + $failed >= $maxChunks) {
                break;
            }
            if (0.0 < $maxSeconds && microtime(true) - $start >= $maxSeconds) {
                break;
            }

            $size = EmbeddingClient::BATCH_SIZE;
            if (0 < $maxChunks) {
                $size = min($size, $maxChunks - $embedded - $failed);
            }

            $batch = $this->nextOpen($sourceId, $size);
            if ([] === $batch) {
                break;
            }

            try {
                $vectors = $this->client->embed(array_map(
                    static fn (array $row): string => (new Chunk($row['content'], $row['heading_path']))->getEmbeddableText(),
                    $batch,
                ));
            } catch (EmbeddingException $exception) {
                $this->countFailure($batch, $exception->getMessage());
                $failed += count($batch);
                $message = $exception->getMessage();
                break;
            }

            // Die Laenge steht erst fest, wenn der erste Vektor da ist. Danach ist sie
            // fuer dieses Profil unveraenderlich.
            $this->store->ensureTable(count($vectors[0]));

            $byChunk = [];
            $sources = [];
            foreach (array_values($batch) as $i => $row) {
                $byChunk[$row['id']] = $vectors[$i];
                $sources[$row['id']] = $row['source_id'];
            }

            $embedded += $this->store->store($byChunk, $sources);
        }

        return new EmbeddingResult($embedded, $failed, $this->countOpen($sourceId), microtime(true) - $start, $message);
    }

    /**
     * Wie viele Abschnitte noch keinen Vektor haben.
     */
    public function countOpen(?int $sourceId = null): int
    {
        [$where, $params] = $this->openCondition($sourceId);

        $rows = rex_sql::factory()->getArray(
            'SELECT COUNT(*) AS c FROM ' . ChunkRepository::table() . ' c' . $this->join() . ' WHERE ' . $where,
            $params,
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Offene Abschnitte je Quelle, fuer die Uebersicht.
     *
     * @return array<int, int>
     */
    public function countOpenBySource(): array
    {
        [$where, $params] = $this->openCondition(null);

        $counts = [];
        foreach (rex_sql::factory()->getArray(
            'SELECT c.source_id, COUNT(*) AS n FROM ' . ChunkRepository::table() . ' c' . $this->join()
            . ' WHERE ' . $where . ' GROUP BY c.source_id',
            $params,
        ) as $row) {
            $counts[(int) $row['source_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @return list<array{id: int, source_id: int, content: string, heading_path: string}>
     */
    private function nextOpen(?int $sourceId, int $limit): array
    {
        if (1 > $limit) {
            return [];
        }

        [$where, $params] = $this->openCondition($sourceId);

        $batch = [];
        foreach (rex_sql::factory()->getArray(
            'SELECT c.id, c.source_id, c.content, c.heading_path FROM ' . ChunkRepository::table() . ' c' . $this->join()
            . ' WHERE ' . $where . ' ORDER BY c.id LIMIT ' . $limit,
            $params,
        ) as $row) {
            $batch[] = [
                'id' => (int) $row['id'],
                'source_id' => (int) $row['source_id'],
                'content' => (string) $row['content'],
                'heading_path' => (string) $row['heading_path'],
            ];
        }

        return $batch;
    }

    /**
     * Der Verbund zur Vektortabelle -- nur wenn es sie schon gibt.
     */
    private function join(): string
    {
        return $this->store->exists()
            ? ' LEFT JOIN ' . VectorStore::table() . ' v ON v.chunk_id = c.id'
            : '';
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function openCondition(?int $sourceId): array
    {
        $where = ['c.embed_attempts < ' . self::MAX_ATTEMPTS];
        $params = [];

        if ($this->store->exists()) {
            $where[] = 'v.chunk_id IS NULL';
        }
        if (null !== $sourceId) {
            $where[] = 'c.source_id = :s';
            $params['s'] = $sourceId;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param list<array{id: int, source_id: int, content: string, heading_path: string}> $batch
     */
    private function countFailure(array $batch, string $error): void
    {
        $names = [];
        $params = ['e' => mb_substr($error, 0, 255)];
        foreach ($batch as $i => $row) {
            $names[] = ':i' . $i;
            $params['i' . $i] = $row['id'];
        }

        rex_sql::factory()->setQuery(
            'UPDATE ' . ChunkRepository::table()
            . ' SET embed_attempts = embed_attempts + 1, embed_error = :e'
            . ' WHERE id IN (' . implode(', ', $names) . ')',
            $params,
        );
    }
}
