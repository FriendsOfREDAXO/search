<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Vector\Similarity;

use function array_slice;
use function count;

/**
 * Eine Suchanfrage, unveraenderlich.
 *
 * Bewusst ohne Abfragesprache: der Eingabetext ist eine Folge von Woertern, alle muessen
 * vorkommen. Kein UND, kein ODER, keine Klammern, keine Phrasen. Wer das eines Tages
 * braucht, bekommt es als eigene Schicht darueber; fuer Websites dieser Groesse ist es
 * Ballast, den ohnehin niemand tippt.
 *
 * Die with-Methoden geben eine Kopie zurueck, eine Anfrage laesst sich also gefahrlos
 * weiterreichen und abwandeln:
 *
 *     $query = SearchQuery::create('burg eppstein')
 *         ->withNamespaces('article')
 *         ->withClangIds(rex_clang::getCurrentId())
 *         ->withLimit(10);
 */
final class SearchQuery
{
    public const DEFAULT_LIMIT = 20;

    /** Nur diese Spalten lassen sich als Facette auszaehlen. */
    public const FACET_COLUMNS = ['namespace', 'type', 'source_id', 'clang_id', 'language'];

    /** Obergrenze fuer die Trefferliste, bevor die Seite herausgeschnitten wird. */
    public const DEFAULT_CANDIDATE_LIMIT = 2000;

    /** Titeltreffer zaehlen zusaetzlich, mit diesem Faktor. 0 schaltet sie ab. */
    public const DEFAULT_TITLE_BOOST = 1.5;

    /** Die exakte Wortform zaehlt zusaetzlich zum Wortanfang. 0 schaltet den Zuschlag ab. */
    public const DEFAULT_EXACT_BOOST = 0.5;

    /** Woerter nahe beieinander zaehlen zusaetzlich. 0 schaltet den Zuschlag ab. */
    public const DEFAULT_PROXIMITY_BOOST = 0.8;

    /**
     * Ab dieser Aehnlichkeit zaehlt ein Dokument als semantischer Treffer.
     *
     * Die Zahl und ihre Begruendung stehen bei {@see Similarity}; sie ist dieselbe wie
     * bei der Passagen-API, weil beide dasselbe Modell fragen.
     */
    public const DEFAULT_SEMANTIC_MIN_SIMILARITY = Similarity::DEFAULT_MINIMUM;

    /**
     * Gewicht der semantischen Liste gegenueber der des Volltexts.
     *
     * Gleichauf, und das ist der Sinn der Sache. Wer die Bedeutung nur als Ergaenzung
     * will, setzt es herunter; die Verschmelzung vergleicht Plaetze, kein Wert muss
     * dafuer umgerechnet werden. Siehe {@see RankFusion}.
     */
    public const DEFAULT_SEMANTIC_WEIGHT = 1.0;

    /** So viele Dokumente steuert die semantische Suche hoechstens bei. */
    public const DEFAULT_SEMANTIC_LIMIT = 50;

    /** Bis zu diesem Wortabstand gelten Woerter als nahe beieinander. */
    public const DEFAULT_PROXIMITY_DISTANCE = 20;

    /**
     * Bis zu dieser Zeichenzahl wird ein Dokument gar nicht gedaempft, darueber hinaus
     * logarithmisch. 0 schaltet die Daempfung ab.
     *
     * Die Schwelle ist noetig, nicht bequem: eine Daempfung, die schon zwischen 24 und 984
     * Zeichen unterscheidet, stellt den Meta-Eintrag eines Artikels vor dessen Volltext,
     * weil beide dieselbe Bewertung haben und der kuerzere weniger geteilt wird. Der
     * Textausschnitt im Treffer waere dann nur der Titel.
     */
    public const DEFAULT_LENGTH_SCALE = 5000;

    /** @var list<int> */
    private array $sourceIds = [];

    /** @var list<string> */
    private array $namespaces = [];

    /** @var list<string> */
    private array $types = [];

    /** @var list<int> */
    private array $clangIds = [];

    /** @var list<string> */
    private array $languages = [];

    private int $limit = self::DEFAULT_LIMIT;

    private int $offset = 0;

    private bool $grouped = true;

    private bool $expanding = true;

    private ?int $expandBelow = null;

    private bool $correcting = true;

    private bool $semantic = false;

    private float $semanticMinSimilarity = self::DEFAULT_SEMANTIC_MIN_SIMILARITY;

    private float $semanticWeight = self::DEFAULT_SEMANTIC_WEIGHT;

    private int $semanticLimit = self::DEFAULT_SEMANTIC_LIMIT;

    private float $titleBoost = self::DEFAULT_TITLE_BOOST;

    private float $exactBoost = self::DEFAULT_EXACT_BOOST;

    private float $proximityBoost = self::DEFAULT_PROXIMITY_BOOST;

    private int $proximityDistance = self::DEFAULT_PROXIMITY_DISTANCE;

    private float $minRelevance = 0.0;

    /** @var list<string> */
    private array $facets = [];

    private int $lengthScale = self::DEFAULT_LENGTH_SCALE;

    /** @var array<int, float> */
    private array $sourceWeights = [];

    private int $candidateLimit = self::DEFAULT_CANDIDATE_LIMIT;

    private function __construct(
        private readonly string $term,
    ) {}

    public static function create(string $term): self
    {
        return new self($term);
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    /** @return list<int> */
    public function getSourceIds(): array
    {
        return $this->sourceIds;
    }

    /** @return list<string> */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /** @return list<string> */
    public function getTypes(): array
    {
        return $this->types;
    }

    /** @return list<int> */
    public function getClangIds(): array
    {
        return $this->clangIds;
    }

    /** @return list<string> */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function isGrouped(): bool
    {
        return $this->grouped;
    }

    public function isExpanding(): bool
    {
        return $this->expanding;
    }

    /**
     * Unter so vielen Treffern wird erweitert; ohne Angabe ist das das Limit.
     */
    public function getExpandBelow(): int
    {
        return $this->expandBelow ?? $this->limit;
    }

    public function isCorrecting(): bool
    {
        return $this->correcting;
    }

    public function isSemantic(): bool
    {
        return $this->semantic;
    }

    public function getSemanticMinSimilarity(): float
    {
        return $this->semanticMinSimilarity;
    }

    public function getSemanticWeight(): float
    {
        return $this->semanticWeight;
    }

    public function getSemanticLimit(): int
    {
        return $this->semanticLimit;
    }

    public function getTitleBoost(): float
    {
        return $this->titleBoost;
    }

    public function getExactBoost(): float
    {
        return $this->exactBoost;
    }

    public function getProximityBoost(): float
    {
        return $this->proximityBoost;
    }

    public function getProximityDistance(): int
    {
        return $this->proximityDistance;
    }

    public function getMinRelevance(): float
    {
        return $this->minRelevance;
    }

    /** @return list<string> */
    public function getFacets(): array
    {
        return $this->facets;
    }

    public function getLengthScale(): int
    {
        return $this->lengthScale;
    }

    /** @return array<int, float> */
    public function getSourceWeights(): array
    {
        return $this->sourceWeights;
    }

    public function getCandidateLimit(): int
    {
        return $this->candidateLimit;
    }

    public function withTerm(string $term): self
    {
        // PHP 8.1 vertraegt kein new ohne Klammern vor dem Methodenaufruf.
        return (new self($term))->copyOptionsFrom($this);
    }

    public function withSourceIds(int ...$sourceIds): self
    {
        $clone = clone $this;
        $clone->sourceIds = array_values(array_unique($sourceIds));

        return $clone;
    }

    public function withNamespaces(string ...$namespaces): self
    {
        $clone = clone $this;
        $clone->namespaces = array_values(array_unique($namespaces));

        return $clone;
    }

    public function withTypes(string ...$types): self
    {
        $clone = clone $this;
        $clone->types = array_values(array_unique($types));

        return $clone;
    }

    public function withClangIds(int ...$clangIds): self
    {
        $clone = clone $this;
        $clone->clangIds = array_values(array_unique($clangIds));

        return $clone;
    }

    /**
     * Nur Dokumente dieser Sprachen durchsuchen.
     *
     * Das ist nicht dasselbe wie {@see withClangIds()}: der clang sagt, in welchem
     * Sprachzweig der Struktur ein Artikel steht, die Sprache, in welcher Sprache ein
     * Dokument verfasst ist. Medien und YForm-Datensaetze haben keinen clang, aber sehr
     * wohl eine Sprache, und mehrere clangs koennen dieselbe Sprache sein.
     *
     * Quellen ohne Sprachangabe bleiben aussen vor -- wer nach Sprache filtert, will keine
     * Dokumente, von denen niemand weiss, in welcher sie sind. Bei der Wortliste ist es
     * umgekehrt, siehe WordRepository::withUnknown(): dort geht es nicht um die Treffer,
     * sondern um Vorschlaege, und ein Vorschlag zu viel schadet weniger als eine
     * Trefferliste, die Fremdes enthaelt.
     */
    public function withLanguages(string ...$languages): self
    {
        $clone = clone $this;
        $clone->languages = array_values(array_unique(array_map(Language::normalize(...), $languages)));

        return $clone;
    }

    public function withLimit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(1, $limit);

        return $clone;
    }

    public function withOffset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = max(0, $offset);

        return $clone;
    }

    /**
     * Seitenweises Blaettern, erste Seite ist 1.
     */
    public function withPage(int $page, ?int $perPage = null): self
    {
        $clone = $this->withLimit($perPage ?? $this->limit);
        $clone = $clone->withOffset((max(1, $page) - 1) * $clone->getLimit());

        return $clone;
    }

    /**
     * Ohne Gruppierung erscheint ein Artikel mehrfach, sobald ihn mehrere Quellen erfassen.
     * Das ist nur fuer Auswertungen sinnvoll, nicht fuer eine Trefferliste.
     */
    public function withGrouping(bool $grouped = true): self
    {
        $clone = clone $this;
        $clone->grouped = $grouped;

        return $clone;
    }

    /**
     * Verwandte Formen aus der Wortliste mitsuchen, etwa "Burgruine" zu "Ruine".
     *
     * Erweitert wird nur, wenn die Suche wie eingegeben weniger als `$below` Treffer hat.
     * Vorgabe ist das Limit, also: die erste Seite wurde nicht voll. Wer viele Treffer
     * auf einer Seite zeigt, will die Grenze tiefer -- bei hundert je Seite sind dreissig
     * direkte Treffer genug, und die Erweiterung mischte ihnen nur Verwandtes bei.
     */
    public function withExpansion(bool $expanding = true, ?int $below = null): self
    {
        $clone = clone $this;
        $clone->expanding = $expanding;
        if (null !== $below) {
            $clone->expandBelow = max(1, $below);
        }

        return $clone;
    }

    /**
     * Bei null Treffern einen zweiten Versuch mit korrigierter Schreibweise unternehmen.
     */
    public function withCorrection(bool $correcting = true): self
    {
        $clone = clone $this;
        $clone->correcting = $correcting;

        return $clone;
    }

    /**
     * Die Bedeutung mitsuchen, nicht nur die Woerter.
     *
     * Vorgabe ist aus, und zwar aus zwei Gruenden: es kostet einen Aufruf beim KI-Dienst
     * (gemessen 172 Millisekunden fuer eine Frage, die noch nicht im Zwischenspeicher
     * steht, gegenueber sieben fuer die Volltextsuche), und es setzt voraus, dass fuer
     * die Quellen ueberhaupt Vektoren erzeugt wurden. Eine Suche, die beides stillschweigend
     * annimmt, waere auf der Haelfte der Installationen langsamer ohne besser zu sein.
     */
    public function withSemantic(bool $semantic = true, ?float $minSimilarity = null): self
    {
        $clone = clone $this;
        $clone->semantic = $semantic;
        if (null !== $minSimilarity) {
            $clone->semanticMinSimilarity = Similarity::clamp($minSimilarity);
        }

        return $clone;
    }

    public function withSemanticWeight(float $weight): self
    {
        $clone = clone $this;
        $clone->semanticWeight = max(0.0, $weight);

        return $clone;
    }

    public function withSemanticLimit(int $limit): self
    {
        $clone = clone $this;
        $clone->semanticLimit = max(1, $limit);

        return $clone;
    }

    public function withTitleBoost(float $titleBoost): self
    {
        $clone = clone $this;
        $clone->titleBoost = max(0.0, $titleBoost);

        return $clone;
    }

    /**
     * Zuschlag fuer die exakte Wortform gegenueber dem blossen Wortanfang. Ohne ihn liegt
     * eine Seite ueber das Burgfest gleichauf mit einer ueber die Burg.
     */
    public function withExactBoost(float $exactBoost): self
    {
        $clone = clone $this;
        $clone->exactBoost = max(0.0, $exactBoost);

        return $clone;
    }

    /**
     * Zuschlag, wenn die gesuchten Woerter nahe beieinander stehen.
     */
    public function withProximityBoost(float $proximityBoost, ?int $distance = null): self
    {
        $clone = clone $this;
        $clone->proximityBoost = max(0.0, $proximityBoost);
        if (null !== $distance) {
            $clone->proximityDistance = max(1, $distance);
        }

        return $clone;
    }

    /**
     * Treffer unterhalb dieses Anteils der besten Bewertung weglassen, 0 bis 1.
     *
     * Gemeint ist ein Anteil, kein absoluter Wert: die Bewertung haengt am Wortschatz des
     * Bestands, "mindestens 4,5" waere auf jeder Installation etwas anderes. "mindestens
     * dreissig Prozent des besten Treffers" ist dagegen ueberall dasselbe.
     */
    public function withMinRelevance(float $minRelevance): self
    {
        $clone = clone $this;
        $clone->minRelevance = min(1.0, max(0.0, $minRelevance));

        return $clone;
    }

    /**
     * Spalten, fuer die das Ergebnis eine Haeufigkeitsverteilung mitliefern soll --
     * "12 Artikel, 3 Dokumente". Erlaubt sind namespace, type, source_id und clang_id.
     */
    public function withFacets(string ...$facets): self
    {
        $clone = clone $this;
        $clone->facets = array_values(array_intersect(self::FACET_COLUMNS, array_unique($facets)));

        return $clone;
    }

    public function withLengthScale(int $lengthScale): self
    {
        $clone = clone $this;
        $clone->lengthScale = max(0, $lengthScale);

        return $clone;
    }

    /**
     * Faktoren je Quelle, etwa um Dokumente gegenueber Artikeln zurueckzunehmen.
     *
     * @param array<int, float> $weights Quellen-Id auf Faktor, 1.0 ist neutral
     */
    public function withSourceWeights(array $weights): self
    {
        $clone = clone $this;
        $clone->sourceWeights = $weights;

        return $clone;
    }

    public function withCandidateLimit(int $candidateLimit): self
    {
        $clone = clone $this;
        $clone->candidateLimit = max(1, $candidateLimit);

        return $clone;
    }

    /**
     * Uebernimmt alles ausser dem Suchbegriff, fuer {@see withTerm()}.
     */
    private function copyOptionsFrom(self $other): self
    {
        $this->sourceIds = $other->sourceIds;
        $this->namespaces = $other->namespaces;
        $this->types = $other->types;
        $this->clangIds = $other->clangIds;
        $this->languages = $other->languages;
        $this->limit = $other->limit;
        $this->offset = $other->offset;
        $this->grouped = $other->grouped;
        $this->expanding = $other->expanding;
        $this->expandBelow = $other->expandBelow;
        $this->correcting = $other->correcting;
        $this->semantic = $other->semantic;
        $this->semanticMinSimilarity = $other->semanticMinSimilarity;
        $this->semanticWeight = $other->semanticWeight;
        $this->semanticLimit = $other->semanticLimit;
        $this->titleBoost = $other->titleBoost;
        $this->exactBoost = $other->exactBoost;
        $this->proximityBoost = $other->proximityBoost;
        $this->proximityDistance = $other->proximityDistance;
        $this->minRelevance = $other->minRelevance;
        $this->facets = $other->facets;
        $this->lengthScale = $other->lengthScale;
        $this->sourceWeights = $other->sourceWeights;
        $this->candidateLimit = $other->candidateLimit;

        return $this;
    }

    /**
     * Nur fuer die Suche selbst: die Filterwerte als Spalte-zu-Werte-Abbildung.
     *
     * @return array<string, list<int|string>>
     */
    public function getFilters(): array
    {
        $filters = [];
        foreach ([
            'source_id' => $this->sourceIds,
            'namespace' => $this->namespaces,
            'type' => $this->types,
            'clang_id' => $this->clangIds,
            'language' => $this->languages,
        ] as $column => $values) {
            if ([] !== $values) {
                // Mehr Werte als Zeilen im Index waere ein Programmierfehler, aber die Abfrage
                // soll daran nicht zerbrechen.
                $filters[$column] = array_slice(array_values($values), 0, 500);
            }
        }

        return $filters;
    }

    public function hasFilters(): bool
    {
        return 0 < count($this->getFilters());
    }
}
