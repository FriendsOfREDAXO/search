<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Vector\SemanticSearch;
use FriendsOfRedaxo\Search\Word\WordExpander;
use FriendsOfRedaxo\Search\Word\WordRepository;
use rex;
use rex_sql;
use Throwable;

use function array_slice;
use function count;
use function in_array;
use function is_array;
use function is_string;

use const JSON_THROW_ON_ERROR;

/**
 * Fuehrt eine {@see SearchQuery} aus.
 *
 * Zwei Abfragen statt einer, und das mit Absicht. Die erste holt Kennung und Bewertung
 * aller Treffer, die zweite nur die Zeilen der angezeigten Seite. So lassen sich Dubletten
 * zusammenfassen -- derselbe Artikel liegt mehrfach im Index, sobald mehrere Quellen ihn
 * erfassen -- ohne Fensterfunktionen, die auf aelteren Servern fehlen. Bei den Groessen,
 * um die es hier geht (ein paar zehntausend Zeilen), kostet das nichts.
 *
 * Die Bewertung steht in {@see ScoreExpression} und nirgends sonst.
 * @phpstan-type Candidate array{id: int, namespace: string, item_id: string, type: string, source_id: int, clang_id: int, language: string, direct: bool, score: float, relevance?: float, snippet?: string, similarity?: float, passages?: list<array<string, mixed>>, group?: list<array<string, mixed>>}
 */
final class Searcher
{
    private readonly TermParser $parser;

    private readonly WordRepository $words;

    private readonly SemanticSearch $semantic;

    private ?WordExpander $expander = null;

    private ?bool $hasWordList = null;

    /** @var list<Candidate>|null Einmal je Suche, nicht einmal je Anlauf */
    private ?array $semanticCandidates = null;

    public function __construct(?TermParser $parser = null, ?WordRepository $words = null, ?SemanticSearch $semantic = null)
    {
        $this->parser = $parser ?? new TermParser();
        $this->words = $words ?? new WordRepository();
        $this->semantic = $semantic ?? new SemanticSearch();
    }

    /**
     * Kurzform fuer den haeufigen Fall.
     */
    public function searchFor(string $term, int $limit = SearchQuery::DEFAULT_LIMIT): SearchResult
    {
        return $this->search(SearchQuery::create($term)->withLimit($limit));
    }

    /**
     * Sucht in bis zu drei Anlaeufen: wie eingegeben, korrigiert, erweitert.
     *
     * Die Staffelung ist das Entscheidende, nicht die einzelnen Schritte. Korrigiert wird
     * nur, wenn gar nichts kam, erweitert nur, wenn die erste Seite nicht voll wurde.
     * Am echten Bestand gemessen ist das der Unterschied zwischen einer brauchbaren und
     * einer unbrauchbaren Suche: "burg" findet 22 Artikel, und eine Erweiterung haengt
     * "Einbuergerung", "Hamburg" und "Schomburg" daran -- woraufhin sich ein Artikel ueber
     * Ganerbenburgen vor den ueber die Burg Eppstein schiebt. Wer genug gefunden hat,
     * braucht keine Hilfe.
     *
     * Die zusaetzlichen Anlaeufe kosten also nur dort etwas, wo der erste zu wenig lieferte.
     */
    public function search(SearchQuery $query): SearchResult
    {
        $terms = $this->parser->parse($query->getTerm());
        if ($terms->isEmpty()) {
            return SearchResult::empty($query, $terms);
        }

        // Die Bedeutung aendert sich zwischen den Anlaeufen nicht -- erweitert und
        // korrigiert wird der Volltextausdruck, nicht die Frage.
        $this->semanticCandidates = null;

        $result = $this->run($query, $terms);

        // Die Korrektur kommt vor der Erweiterung. Umgekehrt fand die Erweiterung zu einem
        // vertippten Wort fast immer etwas, naemlich seine Teile -- "Energiberatung" brachte
        // "Energie", "Beratung" und "Energiewende" --, und zur Korrektur auf
        // "Energieberatung" kam es nie, weil ja nicht mehr nichts gefunden war.
        if ($result->isEmpty() && $query->isCorrecting()) {
            $corrected = $this->correct($query, $terms);
            if (null !== $corrected) {
                return $corrected;
            }
        }

        if ($query->isExpanding() && $result->total < $query->getExpandBelow()) {
            $expanded = $this->expand($query, $terms);
            if ([] !== $expanded->expansions) {
                $wider = $this->run($query, $expanded);
                // Eine Erweiterung darf nur hinzufuegen. Findet sie weniger, war etwas faul.
                $result = $wider->total > $result->total ? $wider : $result;
            }
        }

        return $result;
    }

    /**
     * Der Anlauf mit korrigierter Schreibweise, oder null.
     *
     * Null auch dann, wenn die Korrektur nichts findet: die verschweigen wir lieber.
     */
    private function correct(SearchQuery $query, SearchTerms $terms): ?SearchResult
    {
        $corrections = $this->corrections($query, $terms);
        if ([] === $corrections) {
            return null;
        }

        $corrected = $this->run($query, $terms->withWords(array_values(array_map(
            static fn (string $word): string => $corrections[$word] ?? $word,
            $terms->words,
        ))), $corrections);

        return $corrected->isEmpty() ? null : $corrected;
    }

    /**
     * Verwandte Formen aus der Wortliste dazunehmen, etwa "Burgruine" zu "Ruine".
     */
    private function expand(SearchQuery $query, SearchTerms $terms): SearchTerms
    {
        if (!$this->hasWordList()) {
            return $terms;
        }

        // Was eine Erweiterung ueber SEARCH_QUERY_TERMS schon beigesteuert hat, bleibt
        // stehen: hier wird ergaenzt, nicht ersetzt.
        $expansions = $terms->expansions;
        foreach ($terms->words as $word) {
            $forms = $this->expander()->expand($word, $query->getSourceIds(), $query->getLanguages());
            foreach ($forms as $form) {
                if (!in_array($form, $expansions[$word] ?? [], true)) {
                    $expansions[$word][] = $form;
                }
            }
        }

        return $expansions === $terms->expansions ? $terms : $terms->withExpansions($expansions);
    }

    /**
     * @return array<string, string> Eingegebenes Wort auf vorgeschlagene Schreibweise
     */
    private function corrections(SearchQuery $query, SearchTerms $terms): array
    {
        if (!$this->hasWordList()) {
            return [];
        }

        $corrections = [];
        foreach ($terms->words as $word) {
            $correction = $this->expander()->correct($word, $query->getSourceIds(), $query->getLanguages());
            if (null !== $correction) {
                $corrections[$word] = $correction;
            }
        }

        return $corrections;
    }

    private function expander(): WordExpander
    {
        return $this->expander ??= new WordExpander($this->words);
    }

    /**
     * Ohne Wortliste -- frisch installiert, noch nicht indiziert -- entfaellt beides
     * lautlos. Einmal je Anfrage gefragt, nicht einmal je Wort.
     */
    private function hasWordList(): bool
    {
        return $this->hasWordList ??= !$this->words->isEmpty();
    }

    /**
     * @param array<string, string> $corrections
     */
    private function run(SearchQuery $query, SearchTerms $terms, array $corrections = []): SearchResult
    {
        $candidates = $this->findCandidates($query, $terms);
        $semantic = $this->semanticCandidates($query);

        // Ein Dokument, das nur der Bedeutung nach passt, ist ein Treffer. Frueher
        // endete die Suche hier, sobald der Volltext nichts fand -- und eine Frage ohne
        // woertliche Entsprechung lieferte nichts, obwohl die Antwort im Bestand stand.
        if ([] === $candidates && [] === $semantic) {
            return SearchResult::empty($query, $terms);
        }

        $truncated = count($candidates) >= $query->getCandidateLimit();
        $ranked = $query->isGrouped() ? $this->groupByItem($candidates) : $candidates;
        if ([] === $semantic) {
            // Die Bewertung ist unbegrenzt und haengt am Wortschatz des Bestands; erst
            // der Anteil am besten Treffer ist eine Zahl, die ueberall dasselbe bedeutet.
            $ranked = CandidateList::rank($ranked);
        } else {
            $ranked = CandidateList::fuse($ranked, $semantic, $query->getSemanticWeight());
        }

        $ranked = CandidateList::dropBelowMinRelevance($ranked, $query->getMinRelevance());

        $facets = CandidateList::countFacets($ranked, $query->getFacets());
        $page = array_slice($ranked, $query->getOffset(), $query->getLimit());

        return new SearchResult(
            [] === $page ? [] : $this->loadHits($page, $terms),
            count($ranked),
            $query,
            $terms,
            $truncated,
            $corrections,
            $facets,
        );
    }

    /**
     * Die Dokumente, die der Bedeutung nach passen -- leer, wenn nicht gefragt.
     *
     * Gefiltert wird mit denselben Werten wie der Volltext, nur mit den Spaltennamen der
     * dortigen Abfrage. Ohne das lieferte eine auf eine Quelle eingeschraenkte Suche
     * ueber die Bedeutung plotzlich Treffer aus allen anderen.
     *
     * @return list<Candidate>
     */
    private function semanticCandidates(SearchQuery $query): array
    {
        if (null !== $this->semanticCandidates) {
            return $this->semanticCandidates;
        }
        if (!$query->isSemantic() || !$this->semantic->isReady()) {
            return $this->semanticCandidates = [];
        }

        $filters = [];
        foreach ($query->getFilters() as $column => $values) {
            $filters['i.' . $column] = $values;
        }

        $candidates = [];
        foreach ($this->semantic->findDocuments(
            $query->getTerm(),
            $query->getSemanticMinSimilarity(),
            $query->getSemanticLimit(),
            $filters,
        ) as $row) {
            $candidates[] = [
                'id' => $row['document_id'],
                'namespace' => $row['namespace'],
                'item_id' => $row['item_id'],
                'type' => $row['type'],
                'source_id' => $row['source_id'],
                'clang_id' => $row['clang_id'],
                'language' => $row['language'],
                'direct' => false,
                'score' => 0.0,
                'similarity' => $row['similarity'],
                // Der Abschnitt, der den Ausschlag gab. Ohne ihn stuende bei einem
                // Treffer, den nur die Bedeutung gefunden hat, der Anfang des Dokuments.
                'snippet' => $row['snippet'],
                'passages' => $row['passages'],
            ];
        }

        return $this->semanticCandidates = $candidates;
    }

    /**
     * Alle Treffer, aber nur Kennung, Gruppierungsschluessel und Bewertung.
     *
     * @return list<Candidate>
     */
    private function findCandidates(SearchQuery $query, SearchTerms $terms): array
    {
        $expression = new ScoreExpression($query, $terms);
        $score = $expression->build();
        // Vor der Bewertung steht, ob der Treffer auch ohne Erweiterung gekommen waere.
        $direct = $expression->directMatch();
        $params = $expression->getParams();

        $where = ['MATCH (title, content) AGAINST (:term_filter IN BOOLEAN MODE)'];
        $params['term_filter'] = $terms->expression;

        foreach ($query->getFilters() as $column => $values) {
            $placeholders = [];
            foreach (array_values($values) as $i => $value) {
                $name = $column . '_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $value;
            }
            $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT id, namespace, item_id, type, source_id, clang_id, language,'
            . ('' === $direct ? '' : ' ' . $direct . ' AS direct,')
            . ' ' . $score . ' AS score'
            . ' FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where)
            // Bei gleicher Bewertung gewinnt das inhaltsreichere Dokument: es liefert den
            // brauchbareren Textausschnitt, und beim Zusammenfassen bleibt der erste stehen.
            . ' ORDER BY ' . ('' === $direct ? '' : 'direct DESC, ') . 'score DESC, CHAR_LENGTH(content) DESC, id ASC'
            . ' LIMIT ' . $query->getCandidateLimit(),
            $params,
        );

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'id' => (int) $row['id'],
                'namespace' => (string) $row['namespace'],
                'item_id' => (string) $row['item_id'],
                'type' => (string) $row['type'],
                'source_id' => (int) $row['source_id'],
                'clang_id' => null === $row['clang_id'] ? 0 : (int) $row['clang_id'],
                'language' => (string) $row['language'],
                // Ohne Erweiterung gibt es die Spalte nicht, dann ist jeder Treffer ein
                // direkter.
                'direct' => !isset($row['direct']) || (bool) $row['direct'],
                'score' => (float) $row['score'],
            ];
        }

        return $candidates;
    }

    /**
     * Fasst Eintraege desselben Datensatzes zusammen und behaelt den besten.
     *
     * Ein Artikel liegt im Index einmal je Quelle, die ihn erfasst: Volltext, Meta-Name,
     * Meta-Beschreibung. In einer Trefferliste ist das dreimal dasselbe. Zusammengefasst
     * wird ueber namespace und item_id; die Sprache steckt bereits in der item_id, zwei
     * Sprachfassungen bleiben also getrennte Treffer.
     *
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function groupByItem(array $candidates): array
    {
        $best = [];
        foreach ($candidates as $candidate) {
            // Die Kandidaten kommen bereits nach Bewertung sortiert, der erste je Schluessel
            // ist damit der beste.
            $key = $candidate['namespace'] . "\0" . $candidate['item_id'];
            $row = [
                'id' => $candidate['id'],
                'source_id' => $candidate['source_id'],
                'type' => $candidate['type'],
                'score' => $candidate['score'],
            ];

            if (!isset($best[$key])) {
                $candidate['group'] = [$row];
                $best[$key] = $candidate;
                continue;
            }

            // Die uebrigen Zeilen desselben Datensatzes verschwinden nicht, sie haengen
            // am Treffer. Wer wissen will, warum ein Artikel oben steht, sieht so, ueber
            // welche Quellen er gefunden wurde -- und die Testsuche kann es anzeigen.
            $best[$key]['group'][] = $row;
        }

        return array_values($best);
    }

    /**
     * Holt die vollstaendigen Zeilen einer Seite samt Textausschnitt.
     *
     * Der Ausschnitt entsteht in der Datenbank, nicht hier: LOCATE findet die erste
     * Fundstelle, SUBSTRING holt ein Fenster darum. Sonst muesste bei einem PDF ein halbes
     * Megabyte Text durch die Verbindung, nur um 200 Zeichen davon zu zeigen.
     *
     * @param list<Candidate> $page
     * @return list<SearchHit>
     */
    private function loadHits(array $page, SearchTerms $terms): array
    {
        $params = [];
        $ids = [];
        foreach ($page as $i => $candidate) {
            $name = 'id_' . $i;
            $ids[] = ':' . $name;
            $params[$name] = $candidate['id'];
        }

        $position = $this->buildPositionExpression($terms, $params);
        $start = 'GREATEST(1, ' . $position . ' - ' . Snippet::LEAD . ')';

        $rows = rex_sql::factory()->getArray(
            'SELECT id, source_id, namespace, type, item_id, title, url, clang_id, language, meta, updated_at,'
            . ' ' . $start . ' AS snippet_start,'
            . ' SUBSTRING(content, ' . $start . ', ' . Snippet::WINDOW . ') AS snippet_window'
            . ' FROM ' . self::table()
            . ' WHERE id IN (' . implode(', ', $ids) . ')',
            $params,
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $hits = [];
        foreach ($page as $candidate) {
            $row = $byId[$candidate['id']] ?? null;
            if (null === $row) {
                // Zwischen den beiden Abfragen wurde neu indiziert. Kein Grund fuer einen
                // Fehler, der Treffer faellt einfach weg.
                continue;
            }
            $hits[] = $this->toHit($row, $candidate, $terms);
        }

        return $hits;
    }

    /**
     * Die Position der ersten Fundstelle, ueber alle Suchwoerter hinweg.
     *
     * LOCATE arbeitet auf Zeichenketten, findet also auch den Wortanfang: "burg" trifft
     * "Burgmauer". Findet kein Wort etwas -- der Treffer kam ueber den Titel --, wird der
     * Anfang des Textes genommen.
     *
     * @param array<string, mixed> $params
     */
    private function buildPositionExpression(SearchTerms $terms, array &$params): string
    {
        $parts = [];
        foreach ($terms->getHighlightWords() as $i => $word) {
            $name = 'needle_' . $i;
            $params[$name] = $word;
            $parts[] = 'NULLIF(LOCATE(:' . $name . ', content), 0)';
        }

        if ([] === $parts) {
            return '1';
        }

        return 'COALESCE(' . implode(', ', $parts) . ', 1)';
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * @param array<string, mixed> $row
     * @param Candidate $candidate
     */
    private function toHit(array $row, array $candidate, SearchTerms $terms): SearchHit
    {
        // Hat die Bedeutung den Treffer gebracht, zeigt der Ausschnitt den Abschnitt,
        // der dafuer gesorgt hat -- die Suche nach der Fundstelle liefe hier ins Leere,
        // weil keines der Suchwoerter im Text steht.
        if (isset($candidate['snippet'])) {
            $window = (string) $candidate['snippet'];
            $cutAtStart = false;
        } else {
            $window = (string) ($row['snippet_window'] ?? '');
            $cutAtStart = 1 < (int) ($row['snippet_start'] ?? 1);
        }

        return new SearchHit(
            (int) $row['id'],
            (int) $row['source_id'],
            (string) $row['namespace'],
            (string) $row['type'],
            (string) $row['item_id'],
            (string) $row['title'],
            (string) $row['url'],
            null === $row['clang_id'] ? null : (int) $row['clang_id'],
            (string) $row['language'],
            $candidate['score'],
            $candidate['relevance'] ?? 0.0,
            $candidate['similarity'] ?? null,
            $candidate['passages'] ?? [],
            $candidate['group'] ?? [],
            Snippet::fromWindow($window, $cutAtStart),
            $this->decodeMeta($row['meta'] ?? null),
            $this->toDate($row['updated_at'] ?? null),
            $terms->getHighlightWords(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(mixed $value): array
    {
        if (!is_string($value) || '' === $value) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function table(): string
    {
        return rex::getTable('search_index');
    }
}
