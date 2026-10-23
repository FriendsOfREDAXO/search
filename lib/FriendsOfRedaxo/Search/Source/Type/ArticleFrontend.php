<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use FriendsOfRedaxo\Search\Source\Source;
use rex;
use rex_article;
use rex_exception;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_socket;
use rex_socket_exception;
use rex_yrewrite;

use function count;
use function in_array;

/**
 * Holt den Inhalt eines Artikels so, wie ihn ein Besucher bekommt: per HTTP aus dem Frontend.
 *
 * Der Weg fuer Module, die nur im Frontend funktionieren -- die rex::isFrontend() fragen,
 * auf yrewrite-Domains bauen oder Daten erst im Template bereitstellen. Gerendert im Backend
 * oder auf der Konsole scheitern sie und fallen auf die Slice-Werte zurueck.
 *
 * Indiziert wird nicht die ganze Seite, sonst kaemen Navigation, Kopf und Fuss in jedes
 * Dokument. Mit dem Parameter PARAM setzt das Frontend Markierungen um jede Ausgabe von
 * getArticle() fuer den aufgerufenen Artikel; nur was dazwischen steht, kommt in den Index.
 * Inhalte anderer Artikel, die ein Template einbindet -- etwa ein Fussbereich --, bleiben so
 * draussen.
 *
 * Die Markierung nennt Artikel und Sprache. Liefert das Frontend fuer einen Artikel eine
 * andere Seite aus, etwa die Fehlerseite mit Status 200, passt sie nicht zum Abruf und
 * landet nicht unter dem falschen Artikel im Index.
 */
final class ArticleFrontend
{
    public const PARAM = 'search_index';

    public const MARK_END = '<!-- /search:content -->';

    /** Ohne Antwort binnen dieser Zeit gilt das Frontend als nicht erreichbar. */
    private const TIMEOUT = 15;

    /**
     * Meldet im Frontend die Markierungen an, wenn der Abruf vom AddOn kommt. Spaet, damit
     * andere AddOns den Inhalt vorher fertig bearbeitet haben.
     */
    public static function registerMarkers(): void
    {
        if (!rex::isFrontend() || '' === rex_get(self::PARAM, 'string', '')) {
            return;
        }

        rex_extension::register('ART_CONTENT', static function (rex_extension_point $ep): ?string {
            $article = $ep->getParam('article');
            if (null === $article || (int) $article->getArticleId() !== rex_article::getCurrentId()) {
                return null;
            }

            return self::startMarker((int) $article->getArticleId(), (int) $article->getClangId()) . $ep->getSubject() . self::MARK_END;
        }, rex_extension::LATE);
    }

    public static function startMarker(int $articleId, int $clangId): string
    {
        return '<!-- search:content ' . $articleId . '-' . $clangId . ' -->';
    }

    /**
     * Was zwischen den Markierungen des Artikels steht, aneinandergehaengt; null ohne Markierung.
     */
    public static function extract(string $html, int $articleId, int $clangId): ?string
    {
        $marker = self::startMarker($articleId, $clangId);
        $parts = [];
        $offset = 0;
        while (false !== $start = strpos($html, $marker, $offset)) {
            $start += strlen($marker);
            $end = strpos($html, self::MARK_END, $start);
            if (false === $end) {
                break;
            }
            $parts[] = substr($html, $start, $end - $start);
            $offset = $end + strlen(self::MARK_END);
        }

        return 0 === count($parts) ? null : implode("\n", $parts);
    }

    /**
     * Die Adresse, unter der das Frontend den Artikel ausliefert, immer absolut.
     */
    public static function buildUrl(rex_article $article): string
    {
        if (class_exists(rex_yrewrite::class)) {
            $url = (string) rex_yrewrite::getFullUrlByArticleId($article->getId(), $article->getClangId());
        } else {
            // Trennzeichen ausdruecklich: REDAXO stellt arg_separator.output auf "&amp;".
            $url = rtrim(rex::getServer(), '/') . '/index.php?' . http_build_query(['article_id' => $article->getId(), 'clang' => $article->getClangId()], '', '&');
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . self::PARAM . '=1';
    }

    /**
     * Der markierte Inhalt des Artikels, oder null, wenn das Frontend ihn so nicht liefert.
     *
     * Ein einzelner Artikel, der weiterleitet, fehlt oder einen Fehler wirft, faellt auf die
     * Slice-Werte zurueck -- wie beim Rendern. Ist das Frontend dagegen gar nicht erreichbar
     * oder verlangt eine Anmeldung, scheitert jeder Artikel. Das wird nicht verschluckt: der
     * Lauf bricht ab, und der Index bleibt, wie er war.
     *
     * @throws rex_exception
     */
    public static function fetch(Source $source, rex_article $article): ?string
    {
        $url = self::buildUrl($article);

        try {
            $socket = rex_socket::factoryUrl($url);
            $socket->setTimeout(self::TIMEOUT);
            $socket->addHeader('Cache-Control', 'no-cache');

            $user = (string) $source->getConfig('frontend_auth_user', '');
            if ('' !== $user) {
                $socket->addBasicAuthorization($user, (string) $source->getConfig('frontend_auth_password', ''));
            }
            if (!$source->getConfig('frontend_verify_ssl', true)) {
                $socket->setOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            }

            $response = $socket->doGet();
        } catch (rex_socket_exception $e) {
            throw new rex_exception(rex_i18n::rawMsg('search_article_frontend_unreachable', $url, $e->getMessage()), $e);
        }

        if (in_array($response->getStatusCode(), [401, 403, 407], true)) {
            throw new rex_exception(rex_i18n::rawMsg('search_article_frontend_unreachable', $url, $response->getStatusCode() . ' ' . $response->getStatusMessage()));
        }
        if (!$response->isOk()) {
            return null;
        }

        return self::extract($response->getBody(), $article->getId(), $article->getClangId());
    }
}
