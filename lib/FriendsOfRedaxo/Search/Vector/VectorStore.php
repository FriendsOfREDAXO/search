<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use rex;
use rex_sql;
use Throwable;

use function count;

/**
 * Schreibt und liest rex_search_vector.
 *
 * Die Tabelle entsteht nicht bei der Installation, sondern beim ersten Vektor. Der Grund
 * ist der Spaltentyp: VECTOR verlangt eine feste Laenge, und wie lang ein Vektor ist,
 * weiss erst das Modell hinter dem gewaehlten Profil. Ein Wechsel des Profils aendert sie
 * womoeglich -- und selbst bei gleicher Laenge waeren die alten Vektoren wertlos, weil
 * zwei Modelle denselben Text an verschiedene Stellen legen. Deshalb gibt es hier kein
 * Umbauen, sondern nur Wegwerfen und Neuanlegen.
 *
 * Nicht final, damit Tests die Datenbankzugriffe durch ein Doppel ersetzen koennen.
 */
class VectorStore
{
    /** So viele Zeilen gehen in eine INSERT-Anweisung. */
    private const INSERT_BATCH = 100;

    public static function table(): string
    {
        return rex::getTable('search_vector');
    }

    /**
     * Sorgt dafuer, dass die Tabelle da ist und zur Vektorlaenge passt.
     *
     * Passt sie nicht, wird sie verworfen. Das ist kein Datenverlust, der sich vermeiden
     * liesse: Vektoren anderer Laenge liessen sich nicht einmal speichern.
     */
    public function ensureTable(int $dimensions): void
    {
        $dimensions = max(1, $dimensions);
        $current = $this->getDimensions();

        if ($current === $dimensions) {
            return;
        }
        if (null !== $current) {
            $this->drop();
        }

        rex_sql::factory()->setQuery(
            'CREATE TABLE ' . self::table() . ' ('
            . ' chunk_id INT NOT NULL PRIMARY KEY,'
            . ' source_id INT NOT NULL,'
            . ' created_at DATETIME NOT NULL,'
            . ' embedding VECTOR(' . $dimensions . ') NOT NULL,'
            . ' INDEX source_vector (source_id),'
            . ' VECTOR INDEX (embedding) DISTANCE=cosine'
            . ') ENGINE=InnoDB',
        );
    }

    /**
     * Die Laenge der gespeicherten Vektoren, oder null wenn es die Tabelle nicht gibt.
     */
    public function getDimensions(): ?int
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
                ['t' => rex::getTable('search_vector'), 'c' => 'embedding'],
            );
        } catch (Throwable) {
            return null;
        }

        if ([] === $rows || !preg_match('/\((\d+)\)/', (string) $rows[0]['t'], $match)) {
            return null;
        }

        return (int) $match[1];
    }

    public function exists(): bool
    {
        return null !== $this->getDimensions();
    }

    /**
     * Verwirft alle Vektoren.
     *
     * Dazu gehoert, die Fehlerzaehler der Abschnitte zurueckzusetzen: ein Abschnitt, an
     * dem das alte Profil dreimal gescheitert ist, waere sonst auch fuer das neue
     * gesperrt -- und womoeglich lag es am alten.
     */
    public function drop(): void
    {
        rex_sql::factory()->setQuery('DROP TABLE IF EXISTS ' . self::table());
        rex_sql::factory()->setQuery(
            'UPDATE ' . ChunkRepository::table() . ' SET embed_attempts = 0, embed_error = NULL WHERE embed_attempts > 0',
        );
    }

    /**
     * Schreibt Vektoren zu Abschnitten.
     *
     * @param array<int, list<float>> $vectors Abschnitts-Id auf Vektor
     * @param array<int, int> $sourceIds Abschnitts-Id auf Quellen-Id
     */
    public function store(array $vectors, array $sourceIds): int
    {
        if ([] === $vectors) {
            return 0;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $written = 0;
        $values = [];
        $params = [];

        foreach ($vectors as $chunkId => $vector) {
            $i = count($values);
            // Der Vektor geht als Text hinein, nicht als Parameter: VEC_FromText ist die
            // Umkehrung von VEC_ToText, und einen VECTOR-Parameter kennt PDO nicht.
            $values[] = '(:c' . $i . ', :s' . $i . ', :d' . $i . ', VEC_FromText(:v' . $i . '))';
            $params['c' . $i] = $chunkId;
            $params['s' . $i] = $sourceIds[$chunkId] ?? 0;
            $params['d' . $i] = $now;
            $params['v' . $i] = self::toText($vector);

            if (count($values) >= self::INSERT_BATCH) {
                $written += $this->insert($values, $params);
                $values = [];
                $params = [];
            }
        }

        return $written + $this->insert($values, $params);
    }

    public function deleteBySource(int $sourceId): void
    {
        if (!$this->exists()) {
            return;
        }

        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source_id = :s', ['s' => $sourceId]);
    }

    /**
     * Vektoren zu Abschnitten, die es nicht mehr gibt.
     *
     * Abschnitte verschwinden ueber mehrere Wege -- Dokument neu zerlegt, Quelle
     * umgestellt, Quelle geloescht. Statt jeden Weg zu ergaenzen und den naechsten zu
     * vergessen, wird hinterher aufgeraeumt; dasselbe Verfahren wie bei den Abschnitten
     * selbst.
     */
    public function deleteOrphans(): int
    {
        if (!$this->exists()) {
            return 0;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE v FROM ' . self::table() . ' v'
            . ' LEFT JOIN ' . ChunkRepository::table() . ' c ON c.id = v.chunk_id'
            . ' WHERE c.id IS NULL',
        );

        return $sql->getRows();
    }

    public function countAll(): int
    {
        if (!$this->exists()) {
            return 0;
        }

        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table());

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Wie viel Platz die Vektoren belegen.
     *
     * Gemessen liegt der Verbrauch deutlich ueber der reinen Rechnung aus Laenge mal vier
     * Byte: 1.986 Vektoren zu 1.536 Werten sind 11,6 MB an Zahlen, aber 16,5 MB auf der
     * Platte. Der Rest ist Verwaltung und der Nachbarschaftsgraph des Index. Deshalb wird
     * gemessen und nicht gerechnet, sobald es etwas zu messen gibt.
     */
    public function getBytes(): int
    {
        if (!$this->exists()) {
            return 0;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT data_length + index_length AS b FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => rex::getTable('search_vector')],
        );

        return (int) ($rows[0]['b'] ?? 0);
    }

    /**
     * Wie viele Vektoren es je Quelle gibt.
     *
     * @return array<int, int>
     */
    public function countBySource(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $counts = [];
        foreach (rex_sql::factory()->getArray('SELECT source_id, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY source_id') as $row) {
            $counts[(int) $row['source_id']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Ein Vektor als Text, so wie VEC_FromText ihn erwartet.
     *
     * Beide Trennzeichen werden ausdruecklich gesetzt. Das Dezimaltrennzeichen waere
     * ohnehin der Punkt -- number_format richtet sich nicht nach der Locale --, aber der
     * Tausendertrenner ist voreingestellt ein Komma, und das macht aus einem Wert zwei.
     * Bei einem Vektor eines Sprachmodells liegen die Werte zwischen -1 und 1, der Fall
     * tritt also nicht auf; darauf will ich mich in einer Funktion, die Text fuer eine
     * SQL-Anweisung baut, trotzdem nicht verlassen.
     *
     * Sieben Nachkommastellen genuegen: mehr steht in einem float32 nicht drin.
     *
     * @param list<float> $vector
     */
    public static function toText(array $vector): string
    {
        $parts = [];
        foreach ($vector as $value) {
            $parts[] = rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');
        }

        return '[' . implode(',', $parts) . ']';
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $params
     */
    private function insert(array $values, array $params): int
    {
        if ([] === $values) {
            return 0;
        }

        rex_sql::factory()->setQuery(
            'REPLACE INTO ' . self::table() . ' (chunk_id, source_id, created_at, embedding)'
            . ' VALUES ' . implode(', ', $values),
            $params,
        );

        return count($values);
    }
}
