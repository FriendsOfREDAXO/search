<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Passage;

use FriendsOfRedaxo\Search\Chunk\ChunkRepository;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\EmbeddingException;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use rex_extension;
use rex_extension_point;
use rex_sql;

use function count;
use function is_string;

/**
 * Sucht zu einer Frage die Abschnitte, die sie beantworten koennten.
 *
 * Das ist die Schnittstelle fuer fremde AddOns, die mit einem Sprachmodell antworten
 * wollen. Sie liefert nicht Treffer, sondern Textbausteine samt Herkunft -- siehe
 * {@see Passage}.
 *
 * Drei Dinge, die hier und nicht beim Aufrufer entschieden werden:
 *
 * Die Schwelle. Eine Nachbarsuche liefert immer die naechsten Abschnitte, auch zu einer
 * Frage, zu der nichts dasteht. Ohne Schwelle bekaeme ein Modell zu jeder Frage einen
 * Kontext und wuerde daraus etwas bauen. Siehe {@see PassageQuery::DEFAULT_MIN_SIMILARITY}.
 *
 * Die Obergrenze in Zeichen. Ein Kontextfenster ist endlich.
 *
 * Doppelte Abschnitte. Erfassen zwei Quellen dieselbe Datei, steht derselbe Text zweimal
 * im Index -- und saehe im Kontext aus wie eine Bestaetigung durch eine zweite Quelle.
 */
final class Retriever
{
    /**
     * So viel mehr wird geholt, als am Ende gebraucht wird.
     *
     * Noetig wegen der Schritte danach, siehe {@see PassageSelection}: Doppelte fallen
     * weg, und die Grenze in Zeichen schneidet weiter. Ohne Zuschlag kaemen bei acht
     * gewuenschten Passagen womoeglich drei heraus.
     */
    private const OVERFETCH = 4;

    private readonly EmbeddingClient $client;

    private readonly VectorStore $store;

    public function __construct(?EmbeddingClient $client = null, ?VectorStore $store = null)
    {
        $this->client = $client ?? new EmbeddingClient();
        $this->store = $store ?? new VectorStore();
    }

    /**
     * @param PassageQuery|string $question Eine Zeichenkette nimmt die Vorgaben
     */
    public function retrieve(PassageQuery|string $question): PassageResult
    {
        $query = is_string($question) ? PassageQuery::create($question) : $question;

        if ('' === trim($query->getQuestion()) || !$this->isReady()) {
            return PassageResult::empty($query);
        }

        try {
            $vector = $this->client->embed([$query->getQuestion()])[0] ?? [];
        } catch (EmbeddingException) {
            // Der Dienst antwortet nicht. Keine Passagen ist hier die richtige Antwort:
            // ein Aufrufer, der daraufhin ohne Kontext antwortet, ist besser dran als
            // einer, der auf eine Ausnahme laeuft.
            return PassageResult::empty($query);
        }

        if ([] === $vector) {
            return PassageResult::empty($query);
        }

        $rows = $this->findNearest($query, $vector);
        if ([] === $rows) {
            return PassageResult::empty($query);
        }

        $best = 1.0 - (float) $rows[0]['distance'];
        $passages = self::toPassages($rows);

        // Vor der Auswahl, nicht danach: ein AddOn, das hier eine Passage aussortiert
        // oder eine eigene beisteuert, soll damit auch die Zeichengrenze beeinflussen.
        /** @var list<Passage> $passages */
        $passages = rex_extension::registerPoint(new rex_extension_point('SEARCH_PASSAGES', $passages, ['query' => $query]));

        $selected = PassageSelection::select($passages, $query);

        // "Gekuerzt" heisst: es haette mehr gepasst, es ging nur nicht mehr hinein --
        // nicht, dass die Schwelle etwas aussortiert hat.
        $eligible = PassageSelection::countEligible($passages, $query->getMinSimilarity());

        return new PassageResult($selected, $query, $eligible, $best, count($selected) < $eligible);
    }

    /**
     * Ob ueberhaupt gesucht werden kann: Datenbank, Profil, Vektoren.
     */
    public function isReady(): bool
    {
        return VectorSupport::isAvailable() && EmbeddingClient::isConfigured() && $this->store->exists();
    }

    /**
     * @param list<float> $vector
     * @return list<array<string, mixed>>
     */
    private function findNearest(PassageQuery $query, array $vector): array
    {
        $where = [];
        $params = ['q' => VectorStore::toText($vector)];

        foreach ($query->getFilters() as $column => $values) {
            $names = [];
            foreach (array_values($values) as $i => $value) {
                $name = 'f' . count($params) . '_' . $i;
                $names[] = ':' . $name;
                $params[$name] = $value;
            }
            $where[] = $column . ' IN (' . implode(', ', $names) . ')';
        }

        // Die Aehnlichkeitsgrenze steht nicht im WHERE, sondern wird danach angewandt:
        // der Vektorindex bricht die Suche sonst ab, bevor er genug Nachbarn gesehen hat.
        return rex_sql::factory()->getArray(
            'SELECT c.id, c.document_id, c.source_id, c.heading_path, c.content, c.chunk_no, c.chunk_total,'
            . ' i.namespace, i.type, i.item_id, i.title, i.url, i.language,'
            . ' VEC_DISTANCE_COSINE(v.embedding, VEC_FromText(:q)) AS distance'
            . ' FROM ' . VectorStore::table() . ' v'
            . ' JOIN ' . ChunkRepository::table() . ' c ON c.id = v.chunk_id'
            . ' JOIN ' . IndexRepository::table() . ' i ON i.id = c.document_id'
            . ([] === $where ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY distance LIMIT ' . ($query->getMaxPassages() * self::OVERFETCH),
            $params,
        );
    }

    /**
     * Zeilen zu Passagen, ungefiltert -- ausgewaehlt wird danach.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Passage>
     */
    private static function toPassages(array $rows): array
    {
        $passages = [];

        foreach ($rows as $row) {
            $passages[] = new Passage(
                (int) $row['id'],
                (int) $row['document_id'],
                (int) $row['source_id'],
                (string) $row['namespace'],
                (string) $row['type'],
                (string) $row['item_id'],
                (string) $row['title'],
                (string) $row['url'],
                (string) $row['language'],
                (string) $row['heading_path'],
                (string) $row['content'],
                (int) $row['chunk_no'],
                (int) $row['chunk_total'],
                1.0 - (float) $row['distance'],
            );
        }

        return $passages;
    }
}
