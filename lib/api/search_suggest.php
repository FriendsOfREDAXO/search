<?php

use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Suggest\Suggester;
use FriendsOfRedaxo\Search\Suggest\Suggestion;

/**
 * Vorschlaege fuer das Suchfeld als JSON: ?rex-api-call=search_suggest&q=…
 *
 * Ohne Rahmen antwortet der Endpunkt nicht. Welche Quellen ein Besucher durchsuchen darf,
 * weiss nur die Website -- ein Vorschlag verraet, dass ein Wort im Index steht, und eine
 * Quelle mit internen Datensaetzen gehoert nicht ins Suchfeld. Die Website legt den Rahmen
 * deshalb selbst fest, in der boot.php ihres Projekt-AddOns:
 *
 *   rex_api_search_suggest::setScope(static fn () => SearchQuery::create('')
 *       ->withSourceIds(1, 2)
 *       ->withClangIds(rex_clang::getCurrentId()));
 *
 * Die Funktion laeuft erst beim Aufruf, die Sprache steht dann fest.
 */
final class rex_api_search_suggest extends rex_api_function
{
    public const NAME = 'search_suggest';

    /** Laenger tippt niemand, waehrend er auf Vorschlaege wartet. */
    private const MAX_LENGTH = 100;

    /** @var (Closure(): ?SearchQuery)|null */
    private static ?Closure $scope = null;

    protected $published = true;

    /**
     * @param Closure(): ?SearchQuery $scope null heisst: fuer diesen Aufruf keine Vorschlaege
     */
    public static function setScope(Closure $scope): void
    {
        self::$scope = $scope;
    }

    /**
     * Die Adresse fuer data-search-suggest.
     *
     * Am besten die des Suchartikels: dann stimmt die Sprache, und die Antwort laeuft durch
     * dieselbe Domain wie die Suche.
     */
    public static function getUrl(?int $articleId = null, ?int $clangId = null): string
    {
        return rex_getUrl($articleId, $clangId, [rex_api_function::REQ_CALL_PARAM => self::NAME], '&');
    }

    public function execute(): never
    {
        $scope = null === self::$scope ? null : (self::$scope)();

        // Nicht trimmen: ein Leerzeichen am Ende heisst, das Wort ist fertig
        $input = mb_substr(ltrim(rex_get('q', 'string', '')), 0, self::MAX_LENGTH);
        $limit = min(rex_get('limit', 'int', Suggester::DEFAULT_LIMIT), 20);

        rex_response::cleanOutputBuffers();
        if (!$scope instanceof SearchQuery) {
            rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
            rex_response::sendJson(['suggestions' => []]);
            exit;
        }

        $suggestions = (new Suggester())->suggest($input, $scope, $limit);

        // Der Index aendert sich selten; zurueckgetippte Eingaben kommen so aus dem Browser-Cache
        rex_response::setHeader('Cache-Control', 'private, max-age=300');
        rex_response::sendJson(['suggestions' => array_map(static fn (Suggestion $suggestion): array => $suggestion->toArray(), $suggestions)]);
        exit;
    }
}
