<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use rex;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_logger;
use rex_sql;
use Throwable;

/**
 * Schreibt Artikelquellen nach Aenderungen in der Struktur fort, sofern die Quelle es will
 * ("Bei Aenderungen sofort aktualisieren").
 *
 * Die Extension Points sammeln nur ein, welche Artikel betroffen sind; indiziert wird einmal
 * am Ende des Requests. Wer zehn Bloecke eines Artikels nacheinander speichert, loest so in
 * jedem Request genau eine Aktualisierung aus, und eine Kategorie mit hundert Artikeln wird
 * nicht hundertmal abgefragt, wenn mehrere Punkte sie melden.
 *
 * Was ein Artikel aus anderen Tabellen ausgibt -- etwa eine Terminliste aus YForm --, sieht
 * das nicht: geaendert hat sich dann ein Datensatz, kein Artikel. Dafuer bleibt der Cronjob.
 *
 * Nicht final, damit Tests Datenbank und Indexer durch ein Doppel ersetzen koennen.
 */
class ArticleReindexer
{
    /** Artikel-Aenderungen mit den Parametern "id" und "clang". */
    public const ARTICLE_POINTS = [
        'ART_ADDED', 'ART_UPDATED', 'ART_STATUS', 'ART_DELETED', 'ART_MOVED', 'ART_COPIED',
        'ART_TO_STARTARTICLE', 'ART_TO_CAT', 'CAT_TO_ART', 'ART_META_UPDATED',
    ];

    /** Kategorie-Aenderungen wirken auf alle Artikel darunter: Status, Pfad, Name. */
    public const CATEGORY_POINTS = ['CAT_ADDED', 'CAT_UPDATED', 'CAT_STATUS', 'CAT_DELETED', 'CAT_MOVED'];

    /** Bloecke angelegt, geaendert, verschoben, geloescht; Parameter "article_id" und "clang". */
    public const CONTENT_POINT = 'ART_CONTENT_UPDATED';

    /** @var array<string, true> Datensatz-IDs "{article_id}-{clang_id}" */
    private array $items = [];

    /** @var list<array{int, ?int}> Kategorie-ID und Sprache, null fuer alle */
    private array $categories = [];

    private bool $scheduled = false;

    private ?Indexer $indexer = null;

    /**
     * Meldet die Extension Points an. Kostet nichts, solange nichts geaendert wird: ob eine
     * Quelle mitlaufen will, wird erst gefragt, wenn ein Artikel betroffen ist.
     */
    public static function register(): void
    {
        $reindexer = new self();

        foreach (self::ARTICLE_POINTS as $point) {
            rex_extension::register($point, static function (rex_extension_point $ep) use ($reindexer): void {
                $reindexer->addArticle((int) $ep->getParam('id'), self::clangParam($ep, 'clang'));
                // Beim Wechsel des Startartikels aendert sich der bisherige mit
                $reindexer->addArticle((int) $ep->getParam('id_old'), self::clangParam($ep, 'clang'));
            });
        }
        foreach (self::CATEGORY_POINTS as $point) {
            rex_extension::register($point, static function (rex_extension_point $ep) use ($reindexer): void {
                $reindexer->addCategory((int) $ep->getParam('id'), self::clangParam($ep, 'clang'));
            });
        }
        rex_extension::register(self::CONTENT_POINT, static function (rex_extension_point $ep) use ($reindexer): void {
            $reindexer->addArticle((int) $ep->getParam('article_id'), self::clangParam($ep, 'clang'));
        });
    }

    /**
     * Merkt einen Artikel vor. Ohne Sprache gilt er in allen Sprachen als geaendert.
     */
    public function addArticle(int $articleId, ?int $clangId): void
    {
        if ($articleId <= 0) {
            return;
        }

        foreach (null === $clangId ? rex_clang::getAllIds() : [$clangId] as $id) {
            $this->items[$articleId . '-' . $id] = true;
        }
        $this->book();
    }

    /**
     * Merkt eine Kategorie vor: sie selbst (ihr Startartikel) und alles darunter.
     */
    public function addCategory(int $categoryId, ?int $clangId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        $this->categories[] = [$categoryId, $clangId];
        $this->book();
    }

    /**
     * Aktualisiert die vorgemerkten Artikel in allen Quellen, die das wollen. Ein Fehler
     * in einer Quelle wird protokolliert und haelt die anderen nicht auf -- und vor allem
     * nicht die Antwort an den Redakteur, der gerade gespeichert hat.
     */
    public function flush(): void
    {
        $categories = $this->categories;
        $items = $this->items;
        $this->categories = [];
        $this->items = [];
        $this->scheduled = false;
        if ([] === $categories && [] === $items) {
            return;
        }

        $sources = $this->findSources();
        if ([] === $sources) {
            return;
        }

        foreach ($categories as [$categoryId, $clangId]) {
            foreach (null === $clangId ? rex_clang::getAllIds() : [$clangId] as $id) {
                $items[$categoryId . '-' . $id] = true;
            }
            foreach ($this->findArticlesBelow($categoryId, $clangId) as $itemId) {
                $items[$itemId] = true;
            }
        }
        if ([] === $items) {
            return;
        }

        foreach ($sources as $source) {
            try {
                foreach (array_keys($items) as $itemId) {
                    $this->reindex($source, (string) $itemId);
                }
            } catch (Throwable $e) {
                $this->report($e);
            }
        }
    }

    /**
     * Aktive Artikelquellen, die bei Aenderungen mitlaufen wollen.
     *
     * @param iterable<Source> $sources
     * @return list<Source>
     */
    public static function selectSources(iterable $sources): array
    {
        $selected = [];
        foreach ($sources as $source) {
            if ('article' === $source->getType()?->getNamespace() && $source->getConfig('reindex_on_change', false)) {
                $selected[] = $source;
            }
        }

        return $selected;
    }

    protected function report(Throwable $e): void
    {
        rex_logger::logException($e);
    }

    protected function reindex(Source $source, string $itemId): void
    {
        $this->indexer ??= new Indexer();
        $this->indexer->reindexItem($source, $itemId);
    }

    /**
     * @return list<Source>
     */
    protected function findSources(): array
    {
        return self::selectSources((new SourceRepository())->findAll(true));
    }

    /**
     * Datensatz-IDs aller Artikel unterhalb einer Kategorie, ueber alle Ebenen.
     *
     * @return list<string>
     */
    protected function findArticlesBelow(int $categoryId, ?int $clangId): array
    {
        $params = ['path' => '%|' . $categoryId . '|%'];
        $where = 'path LIKE :path';
        if (null !== $clangId) {
            $where .= ' AND clang_id = :clang';
            $params['clang'] = $clangId;
        }

        $rows = rex_sql::factory()->getArray('SELECT id, clang_id FROM ' . rex::getTable('article') . ' WHERE ' . $where, $params);

        return array_map(static fn (array $row): string => $row['id'] . '-' . $row['clang_id'], $rows);
    }

    /**
     * Bestellt das Ende des Requests, einmal je Sammlung.
     */
    private function book(): void
    {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        $this->schedule();
    }

    /**
     * RESPONSE_SHUTDOWN laeuft, nachdem die Seite beim Browser ist; wer speichert, wartet
     * also nicht auf das Rendern. Den Punkt gibt es aber nur bei rex_response::sendPage() --
     * nicht bei JSON-Antworten, nach exit oder auf der Konsole. Dafuer die Shutdown-Funktion;
     * was der erste Aufruf erledigt hat, findet der zweite nicht mehr vor.
     */
    protected function schedule(): void
    {
        rex_extension::register('RESPONSE_SHUTDOWN', fn () => $this->flush());
        register_shutdown_function($this->flush(...));
    }

    private static function clangParam(rex_extension_point $ep, string $name): ?int
    {
        $clang = $ep->getParam($name);

        return null === $clang || '' === $clang ? null : (int) $clang;
    }
}
