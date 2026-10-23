<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use rex_sql;

use function array_slice;
use function count;

/**
 * Sucht Dokumente ueber die Bedeutung statt ueber die Woerter.
 *
 * Die Nachbarsuche arbeitet auf Abschnitten, die Trefferliste auf Dokumenten. Dazwischen
 * liegt eine Entscheidung: ein Dokument ist so aehnlich wie sein aehnlichster Abschnitt.
 * Die Alternative -- ueber alle Abschnitte eines Dokuments zu mitteln -- bestraft lange
 * Dokumente dafuer, dass sie auch anderes enthalten, und genau das sollen sie duerfen.
 *
 * Die Abschnitte, die den Ausschlag gaben, werden mitgeliefert: aus dem besten wird der
 * Textausschnitt des Treffers, und die uebrigen haengen als Liste am Treffer. Ohne sie
 * stuende bei einem Treffer, den nur die Bedeutung gefunden hat, der Anfang des Dokuments
 * -- und niemand saehe, warum er in der Liste steht.
 *
 * Nicht final, und Abfrage wie Einbettung liegen hinter eigenen Methoden: beide brauchen
 * eine Datenbank mit Vektorunterstuetzung und einen bezahlten Dienst, die Entscheidungen
 * dazwischen aber nicht. Ohne diese Naht waere genau der Teil ungetestet, der die
 * Trefferliste bestimmt.
 */
class SemanticSearch
{
    /**
     * So viele Abschnitte eines Dokuments kommen mit.
     *
     * Ein PDF mit siebenhundert Abschnitten soll nicht siebenhundert Belege an einen
     * Treffer haengen; drei genuegen, um zu zeigen, woran es lag.
     */
    public const MAX_PASSAGES_PER_DOCUMENT = 3;

    private readonly EmbeddingClient $client;

    private readonly VectorStore $store;

    private readonly QueryVectorCache $cache;

    public function __construct(?EmbeddingClient $client = null, ?VectorStore $store = null, ?QueryVectorCache $cache = null)
    {
        $this->client = $client ?? new EmbeddingClient();
        $this->store = $store ?? new VectorStore();
        $this->cache = $cache ?? new QueryVectorCache();
    }

    public function isReady(): bool
    {
        return VectorSupport::isAvailable() && EmbeddingClient::isConfigured() && $this->store->exists();
    }

    /**
     * Die aehnlichsten Dokumente, bestes zuerst.
     *
     * @param array<string, list<int|string>> $filters Spalte auf erlaubte Werte
     * @return list<array{document_id: int, namespace: string, item_id: string, type: string, source_id: int, clang_id: int, language: string, similarity: float, snippet: string, heading_path: string, passages: list<array{chunk_id: int, chunk_no: int, chunk_total: int, heading_path: string, text: string, similarity: float}>}>
     */
    public function findDocuments(string $question, float $minSimilarity, int $limit, array $filters = []): array
    {
        $vector = $this->vectorFor($question);
        if (null === $vector) {
            return [];
        }

        $documents = [];
        foreach ($this->readRows($vector, $filters, $limit) as $row) {
            $similarity = 1.0 - (float) $row['distance'];
            if ($similarity < $minSimilarity) {
                // Nach Abstand sortiert, ab hier wird es nur schlechter.
                break;
            }

            // Zusammengefasst wird ueber Namensraum und item_id, nicht ueber die
            // Zeilen-Id -- denselben Schluessel benutzt auch die Verschmelzung. Erfassen
            // vier Quellen denselben Artikel, liegen vier Zeilen im Index; ueber die
            // Zeilen-Id verbrauchten sie vier der angeforderten Plaetze und lieferten am
            // Ende doch nur einen Treffer. Am Bestand gemessen war genau das der Fall:
            // der Artikel "Hasen" steht in vier Quellen.
            $key = $row['namespace'] . "\0" . $row['item_id'];
            $passage = [
                'chunk_id' => (int) $row['chunk_id'],
                'chunk_no' => (int) $row['chunk_no'],
                'chunk_total' => (int) $row['chunk_total'],
                'heading_path' => (string) $row['heading_path'],
                'text' => (string) $row['content'],
                // Gerundet, weil die Zahl angezeigt und weitergereicht wird: sechzehn
                // Nachkommastellen einer Kosinus-Aehnlichkeit sind Rauschen.
                'similarity' => round($similarity, 4),
            ];

            if (isset($documents[$key])) {
                // Ein weiterer Abschnitt desselben Dokuments. Er entscheidet nichts mehr
                // -- das Dokument ist so aehnlich wie sein aehnlichster Abschnitt --,
                // aber er belegt, woran es lag.
                if (self::MAX_PASSAGES_PER_DOCUMENT > count($documents[$key]['passages'])) {
                    $documents[$key]['passages'][] = $passage;
                }
                continue;
            }

            $documents[$key] = [
                'passages' => [$passage],
                'document_id' => (int) $row['document_id'],
                'namespace' => (string) $row['namespace'],
                'item_id' => (string) $row['item_id'],
                'type' => (string) $row['type'],
                'source_id' => (int) $row['source_id'],
                'clang_id' => null === $row['clang_id'] ? 0 : (int) $row['clang_id'],
                'language' => (string) $row['language'],
                'similarity' => $similarity,
                'snippet' => (string) $row['content'],
                'heading_path' => (string) $row['heading_path'],
            ];

            // Hier nicht abbrechen, auch wenn genug Dokumente beisammen sind: die
            // folgenden Zeilen koennen noch weitere Abschnitte zu den bereits
            // gefundenen beitragen. Die Zahl der Zeilen ist durch das LIMIT der Abfrage
            // ohnehin begrenzt, abgeschnitten wird am Ende.
        }

        return array_slice(array_values($documents), 0, $limit);
    }

    /**
     * Die aehnlichsten Abschnitte, nach Abstand sortiert, bester zuerst.
     *
     * @param array<string, list<int|string>> $filters Spalte auf erlaubte Werte
     * @return list<array<string, mixed>>
     */
    protected function readRows(string $vector, array $filters, int $limit): array
    {
        $where = [];
        $params = ['q' => $vector];
        foreach ($filters as $column => $values) {
            $names = [];
            foreach (array_values($values) as $i => $value) {
                $name = 'f' . count($params) . '_' . $i;
                $names[] = ':' . $name;
                $params[$name] = $value;
            }
            $where[] = $column . ' IN (' . implode(', ', $names) . ')';
        }

        // Mehr Abschnitte holen als Dokumente gewuenscht sind: mehrere Abschnitte
        // desselben Dokuments fallen beim Zusammenfassen zu einem zusammen, und bei
        // einem PDF mit siebenhundert Abschnitten sind das schnell alle.
        return rex_sql::factory()->getArray(
            'SELECT c.id AS chunk_id, c.document_id, c.content, c.heading_path, c.chunk_no, c.chunk_total,'
            . ' i.namespace, i.item_id, i.type, i.source_id, i.clang_id, i.language,'
            . ' VEC_DISTANCE_COSINE(v.embedding, VEC_FromText(:q)) AS distance'
            . ' FROM ' . VectorStore::table() . ' v'
            . ' JOIN ' . ChunkRepository::table() . ' c ON c.id = v.chunk_id'
            . ' JOIN ' . IndexRepository::table() . ' i ON i.id = c.document_id'
            . ([] === $where ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY distance LIMIT ' . max(1, $limit * 5),
            $params,
        );
    }

    /**
     * Das eingestellte Embedding-Profil.
     *
     * Eigene Methode, weil die Auskunft aus einem anderen AddOn kommt: ohne sie liesse
     * sich {@see vectorFor()} nur auf einer Installation pruefen, auf der ai_platform
     * eingerichtet ist.
     */
    protected function profileId(): ?int
    {
        return EmbeddingClient::getProfileId();
    }

    /**
     * Die Anweisung vor der Frage, siehe {@see EmbeddingClient::getQueryInstruction()}.
     *
     * Eigene Methode aus demselben Grund wie {@see profileId()}.
     */
    protected function queryInstruction(): string
    {
        return EmbeddingClient::getQueryInstruction();
    }

    /**
     * Der Vektor zu einer Frage, aus dem Zwischenspeicher oder frisch.
     *
     * Gemerkt wird er unter dem Text samt Anweisung: mit einer anderen Anweisung liegt
     * dieselbe Frage woanders.
     */
    protected function vectorFor(string $question): ?string
    {
        $profileId = $this->profileId();
        if (null === $profileId || '' === trim($question)) {
            return null;
        }

        $question = EmbeddingClient::queryText($question, $this->queryInstruction());
        $cached = $this->cache->get($question, $profileId);
        if (null !== $cached) {
            return $cached;
        }

        try {
            $vector = $this->client->embed([$question])[0] ?? [];
        } catch (EmbeddingException) {
            // Der Dienst antwortet nicht. Die Volltextsuche laeuft trotzdem -- eine
            // Suche, die gar nichts liefert, waere die schlechtere Antwort.
            return null;
        }

        if ([] === $vector) {
            return null;
        }

        $text = VectorStore::toText($vector);
        $this->cache->put($question, $profileId, $text);

        return $text;
    }
}
