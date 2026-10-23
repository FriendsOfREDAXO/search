<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Suggest;

use Closure;
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Query\Searcher;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Word\WordFolder;
use FriendsOfRedaxo\Search\Word\WordRepository;
use rex_extension;
use rex_extension_point;

use function array_keys;
use function array_slice;
use function arsort;
use function count;
use function in_array;
use function is_string;
use function levenshtein;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Vorschlaege fuer das Suchfeld, waehrend getippt wird.
 *
 * Ergaenzt wird das letzte, angefangene Wort aus der Wortliste des Index, in drei Stufen:
 *
 * 1. Woerter, die so beginnen ("Wärmep" -> "Wärmepumpe"),
 * 2. Woerter, die es enthalten ("pumpe" -> "Luftwärmepumpe"),
 * 3. und erst wenn beides nichts brachte, Woerter mit einem Tippfehler im Anfang
 *    ("Energiber" -> "Energieberatung").
 *
 * Jeder Vorschlag wird samt der Woerter davor gesucht und nur genommen, wenn er Treffer hat.
 * Die Wortliste kennt Woerter, die fuer sich vorkommen, aber nicht zusammen mit dem, was
 * davor steht -- und ein Vorschlag, der ins Leere fuehrt, ist schlimmer als keiner.
 *
 * Der Rahmen kommt als {@see SearchQuery}: dieselben Quellen, Sprachen und clangs wie die
 * Suche, die das Feld abschickt. Was dort nichts findet, wird hier nicht vorgeschlagen.
 */
final class Suggester
{
    public const DEFAULT_LIMIT = 8;

    /** Kuerzer tippt niemand gezielt; "e" oder "wa" passt auf alles. */
    public const MIN_PREFIX = 2;

    /** Ab hier lohnt die Suche im Wortinneren. */
    public const MIN_INFIX = 4;

    /** Ab hier wird ein Tippfehler im Wortanfang toleriert; kuerzer passt fast alles. */
    public const MIN_TYPO = 5;

    /** So viele Woerter werden je Stufe geholt und der Reihe nach geprueft. */
    private const CANDIDATES = 40;

    /**
     * Hoechstens so viele Suchen je Anfrage. Gemessen kostet eine 1 bis 3 ms, eine Anfrage
     * damit hoechstens um die 70 ms -- bei einer Eingabe, die nach jedem Buchstaben fragt,
     * die Grenze dessen, was sich noch nicht nach Warten anfuehlt.
     */
    private const MAX_CHECKS = 24;

    /** Endungen, an denen zwei Woerter als dieselbe Form gelten: "Wärmepumpe", "Wärmepumpen". */
    private const INFLECTIONS = ['e', 'n', 's', 'en', 'es', 'er', 'ern', 'ens', 'ns'];

    /**
     * Funktionswoerter je Sprache. Sie stehen in fast jedem Text und laegen nach Haeufigkeit
     * vorn, gesucht werden sie nie. Die Stoppwortliste von InnoDB hilft dabei nicht: sie ist
     * englisch und kennt aus dem Deutschen nur "und".
     */
    private const STOPWORDS = [
        'de' => [
            'aber', 'alle', 'allen', 'aller', 'alles', 'als', 'also', 'auch', 'auf', 'aus', 'bei', 'beim', 'bereits',
            'bis', 'bzw', 'dabei', 'dadurch', 'dafür', 'damit', 'dann', 'darauf', 'darüber', 'das', 'dass', 'dem',
            'den', 'denen', 'denn', 'der', 'deren', 'des', 'dessen', 'die', 'dies', 'diese', 'diesem', 'diesen',
            'dieser', 'dieses', 'doch', 'dort', 'durch', 'ein', 'eine', 'einem', 'einen', 'einer', 'eines', 'etwa',
            'für', 'gegen', 'gibt', 'habe', 'haben', 'hat', 'hier', 'ihnen', 'ihr', 'ihre', 'ihrem', 'ihren', 'ihrer',
            'ist', 'jede', 'jedoch', 'kann', 'keine', 'können', 'man', 'mehr', 'mit', 'muss', 'müssen', 'nach',
            'nicht', 'noch', 'nur', 'oder', 'ohne', 'schon', 'sehr', 'sei', 'sein', 'seine', 'seinen',
            'seiner', 'seit', 'sich', 'sie', 'sind', 'sollen', 'soll', 'sowie', 'über', 'und', 'uns',
            'unsere', 'unter', 'viele', 'vom', 'von', 'vor', 'während', 'war', 'wäre', 'waren', 'was', 'weil',
            'welche', 'wenn', 'wer', 'werden', 'wie', 'wieder', 'wir', 'wird', 'wurde', 'würde', 'wurden',
            'zudem', 'zum', 'zur', 'zwischen',
        ],
        'en' => [
            'about', 'after', 'all', 'also', 'and', 'any', 'are', 'because', 'been', 'before', 'being', 'but',
            'can', 'could', 'did', 'does', 'each', 'for', 'from', 'had', 'has', 'have', 'her', 'his', 'how',
            'into', 'its', 'may', 'more', 'most', 'must', 'not', 'one', 'only', 'other', 'our', 'over', 'should',
            'some', 'such', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this',
            'those', 'very', 'was', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'will', 'with',
            'would', 'you', 'your',
        ],
    ];

    private readonly WordRepository $words;

    /** @var Closure(SearchQuery): bool */
    private readonly Closure $hasHits;

    /**
     * @param (Closure(SearchQuery): bool)|null $hasHits Ob eine Suche Treffer hat; Vorgabe ist der {@see Searcher}
     */
    public function __construct(
        ?WordRepository $words = null,
        ?Closure $hasHits = null,
        private readonly ?SourceRepository $sources = null,
    ) {
        $this->words = $words ?? new WordRepository();
        if (null === $hasHits) {
            $searcher = new Searcher(words: $this->words);
            $hasHits = static fn (SearchQuery $query): bool => $searcher->search($query)->total > 0;
        }
        $this->hasHits = $hasHits;
    }

    /**
     * Die Vorschlaege zur Eingabe, so wie sie im Feld steht.
     *
     * Nach einem Leerzeichen am Ende ist das Wort fertig und es wird nichts mehr ergaenzt --
     * die Eingabe darf deshalb nicht getrimmt hier ankommen.
     *
     * @param SearchQuery $scope Quellen, Sprachen und clangs; der Suchbegriff darin zaehlt nicht
     * @return list<Suggestion>
     */
    public function suggest(string $input, SearchQuery $scope, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($limit < 1 || !preg_match('/[\pL\pN]+$/u', $input, $match, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $typed = $match[0][0];
        if (mb_strlen($typed) < self::MIN_PREFIX) {
            return [];
        }
        $head = substr($input, 0, $match[0][1]);
        $folded = WordFolder::fold($typed);
        $sourceIds = $scope->getSourceIds();
        $languages = $scope->getLanguages();
        $stopwords = $this->stopwords($scope);

        // Ein Treffer genuegt als Beleg; Erweiterung, Korrektur und Bedeutung wuerden einen
        // Vorschlag bestaetigen, der wortwoertlich nichts findet.
        $check = $scope
            ->withLimit(1)
            ->withOffset(0)
            ->withExpansion(false)
            ->withCorrection(false)
            ->withSemantic(false)
            ->withFacets();

        $suggestions = [];
        $seen = [$folded];
        $checks = 0;
        $accept = function (Suggestion $suggestion, string $word) use ($check, $limit, &$suggestions, &$seen, &$checks): void {
            if (count($suggestions) >= $limit || $checks >= self::MAX_CHECKS || self::isDuplicate($word, $seen)) {
                return;
            }
            ++$checks;
            if (!($this->hasHits)($check->withTerm($suggestion->value))) {
                return;
            }
            $seen[] = WordFolder::fold($word);
            $suggestions[] = $suggestion;
        };

        // Eigene Kandidaten zuerst, etwa die Langform einer Abkuerzung ("PV" -> "Photovoltaik")
        foreach ($this->extraWords($typed, $input, $scope) as $word) {
            $accept(str_starts_with(WordFolder::fold($word), $folded)
                ? Suggestion::fromWord($head, $word, 0, $folded)
                : Suggestion::replacing($head, $word), $word);
        }

        foreach (self::order($this->words->findByPrefix($typed, $sourceIds, $languages, self::CANDIDATES), $typed, $stopwords) as $word) {
            $accept(Suggestion::fromWord($head, $word, 0, $folded), $word);
        }

        if (mb_strlen($typed) >= self::MIN_INFIX && count($suggestions) < $limit) {
            foreach (self::order($this->words->findByInfix($typed, $sourceIds, $languages, self::CANDIDATES), $typed, $stopwords) as $word) {
                $position = mb_strpos(WordFolder::fold($word), $folded);
                if (false !== $position && $position > 0) {
                    $accept(Suggestion::fromWord($head, $word, $position, $folded), $word);
                }
            }
        }

        if (mb_strlen($typed) >= self::MIN_TYPO && !$suggestions) {
            $rows = $this->words->findByLength(mb_strlen($folded) - 1, WordFolder::MAX_LENGTH, $sourceIds, $languages);
            foreach (self::order(self::matchTypo($folded, $rows), '', $stopwords) as $word) {
                $accept(Suggestion::replacing($head, $word), $word);
            }
        }

        return $suggestions;
    }

    /**
     * Woerter, deren Anfang hoechstens einen Buchstaben vom Getippten abweicht, haeufigste zuerst.
     *
     * Verglichen wird mit dem Wortanfang in drei Laengen, damit ein fehlender ("Wärmpu") und
     * ein ueberzaehliger Buchstabe ebenso zaehlen wie ein falscher. Die letzten beiden getippten
     * Buchstaben muessen dabei stimmen: wer danach richtig weitertippt, hat sich vertippt. Sonst
     * ist das Getippte meist ein anderes Wort, das nur nicht im Index steht -- "Heizöl" wuerde
     * ohne diese Bedingung zu "Heizlast".
     *
     * @param list<array{word: string, folded: string, docs: int}> $rows
     * @return list<array{word: string, docs: int}>
     */
    public static function matchTypo(string $folded, array $rows): array
    {
        $length = mb_strlen($folded);
        $ending = mb_substr($folded, -2);
        $found = [];
        foreach ($rows as $row) {
            foreach ([$length - 1, $length, $length + 1] as $prefixLength) {
                $start = mb_substr($row['folded'], 0, $prefixLength);
                if (str_ends_with($start, $ending) && levenshtein($folded, $start) <= 1) {
                    $found[$row['word']] = ($found[$row['word']] ?? 0) + $row['docs'];
                    break;
                }
            }
        }
        arsort($found);

        $words = [];
        foreach (array_slice($found, 0, self::CANDIDATES, true) as $word => $docs) {
            $words[] = ['word' => (string) $word, 'docs' => $docs];
        }

        return $words;
    }

    /**
     * Die brauchbaren Woerter einer Abfrage, Hauptwoerter zuerst.
     *
     * Die Wortliste vergleicht gefaltet, "wä" findet dort auch "Wasser". Hat jemand einen
     * Umlaut getippt, meint er ihn aber. Kleingeschriebene Formen ("fördert", "dämmst") sind
     * selten das, wonach jemand sucht; sie kommen nach den gross geschriebenen. In Sprachen
     * ohne grosse Hauptwoerter aendert das nichts an der Reihenfolge der Haeufigkeit.
     *
     * @param list<array{word: string, docs: int}> $rows
     * @param list<string> $stopwords
     * @return list<string>
     */
    public static function order(array $rows, string $typed, array $stopwords = []): array
    {
        $lower = mb_strtolower($typed);
        $exact = $lower !== WordFolder::fold($typed);

        $upper = $others = [];
        foreach ($rows as $row) {
            $word = $row['word'];
            if (!self::isSuggestible($word, $stopwords) || ($exact && !str_contains(mb_strtolower($word), $lower))) {
                continue;
            }
            if (preg_match('/^\p{Lu}/u', $word)) {
                $upper[] = $word;
            } else {
                $others[] = $word;
            }
        }

        return [...$upper, ...$others];
    }

    /**
     * @param list<string> $stopwords
     */
    public static function isSuggestible(string $word, array $stopwords = []): bool
    {
        return mb_strlen($word) >= WordFolder::MIN_LENGTH
            && !in_array(mb_strtolower($word), $stopwords, true)
            // Nur Ziffern: Jahreszahlen, Postleitzahlen, Telefonnummern
            && !preg_match('/^\pN+$/u', $word)
            // "WärmepumpeFinden": zwei Woerter, im Quelltext ohne Abstand
            && !preg_match('/\p{Ll}\p{Lu}/u', $word);
    }

    /**
     * Dasselbe Wort in anderer Schreibweise oder Form wie ein schon genommenes (oder das getippte).
     *
     * @param list<string> $seen gefaltete Formen
     */
    public static function isDuplicate(string $word, array $seen): bool
    {
        $folded = WordFolder::fold($word);
        foreach ($seen as $other) {
            [$short, $long] = strlen($other) <= strlen($folded) ? [$other, $folded] : [$folded, $other];
            if (str_starts_with($long, $short) && ($short === $long || in_array(substr($long, strlen($short)), self::INFLECTIONS, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Stoppwoerter der Sprachen, in denen gesucht wird.
     *
     * Die Sprachen kommen aus dem Rahmen, sonst aus den Quellen darin. Ist keine bekannt,
     * gelten alle Listen: ein Funktionswort zu viel ausgelassen kostet einen Vorschlag,
     * eines zu wenig ausgelassen steht bei jeder Eingabe oben.
     *
     * @return list<string>
     */
    private function stopwords(SearchQuery $scope): array
    {
        $languages = $scope->getLanguages();
        if ([] === $languages && [] !== $scope->getSourceIds()) {
            $repository = $this->sources ?? new SourceRepository();
            foreach ($scope->getSourceIds() as $id) {
                $language = $repository->find($id)?->getLanguage();
                if (null !== $language && '' !== $language) {
                    $languages[] = $language;
                }
            }
        }
        if ([] === $languages) {
            $languages = array_keys(self::STOPWORDS);
        }

        $stopwords = [];
        foreach ($languages as $language) {
            foreach (self::STOPWORDS[$language] ?? [] as $word) {
                $stopwords[] = $word;
            }
        }

        return $stopwords;
    }

    /**
     * Woerter, die vor denen aus der Wortliste geprueft werden, ueber SEARCH_SUGGEST_WORDS.
     *
     * @return list<string>
     */
    private function extraWords(string $typed, string $input, SearchQuery $scope): array
    {
        $words = rex_extension::registerPoint(new rex_extension_point('SEARCH_SUGGEST_WORDS', [], [
            'typed' => $typed,
            'input' => $input,
            'scope' => $scope,
        ]));

        $valid = [];
        foreach ((array) $words as $word) {
            if (is_string($word) && '' !== $word) {
                $valid[] = $word;
            }
        }

        return $valid;
    }
}
