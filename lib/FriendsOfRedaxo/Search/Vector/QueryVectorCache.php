<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use DateTimeImmutable;
use rex;
use rex_sql;
use Throwable;

/**
 * Merkt sich die Vektoren schon gestellter Fragen.
 *
 * Gemessen dauert das Einbetten einer Frage 172 Millisekunden, die Volltextsuche selbst
 * sieben. Bei einem Suchfeld auf einer Website ist das der Unterschied zwischen "sofort"
 * und "spuerbar" -- und Suchbegriffe wiederholen sich dort stark.
 *
 * Gespeichert wird die Textform, die VEC_FromText ohnehin erwartet: so wird beim Lesen
 * nichts umgerechnet. Die Tabelle braucht deshalb auch keinen VECTOR-Spaltentyp und
 * keine feste Laenge.
 *
 * Die Spalte heisst query_vector und nicht vector: seit MariaDB 11.7 ist "vector" ein
 * reserviertes Wort.
 *
 * Nicht final, damit Tests sie durch ein Doppel ersetzen koennen.
 */
class QueryVectorCache
{
    /** So viele Fragen werden behalten, danach fliegen die aeltesten heraus. */
    public const MAX_ENTRIES = 2000;

    /** Nur ab und zu aufraeumen; bei jedem Schreiben waere es verschwendete Zeit. */
    private const PRUNE_CHANCE = 20;

    public static function table(): string
    {
        return rex::getTable('search_query_vector');
    }

    /**
     * Die Textform des Vektors zu dieser Frage, oder null.
     */
    public function get(string $question, int $profileId): ?string
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT query_vector FROM ' . self::table() . ' WHERE hash = :h',
                ['h' => self::hash($question, $profileId)],
            );
        } catch (Throwable) {
            return null;
        }

        if ([] === $rows) {
            return null;
        }

        $this->touch(self::hash($question, $profileId));

        return (string) $rows[0]['query_vector'];
    }

    public function put(string $question, int $profileId, string $vector): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            rex_sql::factory()->setQuery(
                'REPLACE INTO ' . self::table() . ' (hash, query_vector, created_at, used_at) VALUES (:h, :v, :c, :c)',
                ['h' => self::hash($question, $profileId), 'v' => $vector, 'c' => $now],
            );
        } catch (Throwable) {
            // Ein voller oder gesperrter Zwischenspeicher darf keine Suche verhindern.
            return;
        }

        if (1 === random_int(1, self::PRUNE_CHANCE)) {
            $this->prune();
        }
    }

    public function clear(): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table());
    }

    public function countAll(): int
    {
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . self::table());

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Der Schluessel einer Frage.
     *
     * Das Profil gehoert hinein: nach einem Wechsel des Modells waere der gemerkte
     * Vektor derselben Frage falsch, und zwar auf eine Weise, die niemandem auffiele --
     * er haette die richtige Laenge und zeigte nur woandershin.
     *
     * Gross- und Kleinschreibung und umgebende Leerzeichen spielen keine Rolle; mehr
     * wird nicht vereinheitlicht, weil jede weitere Normalisierung die Frage veraendert.
     */
    public static function hash(string $question, int $profileId): string
    {
        return sha1($profileId . "\0" . mb_strtolower(trim($question)));
    }

    private function touch(string $hash): void
    {
        rex_sql::factory()->setQuery(
            'UPDATE ' . self::table() . ' SET used_at = :u, uses = uses + 1 WHERE hash = :h',
            ['u' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'h' => $hash],
        );
    }

    private function prune(): void
    {
        $count = $this->countAll();
        if ($count <= self::MAX_ENTRIES) {
            return;
        }

        rex_sql::factory()->setQuery(
            'DELETE FROM ' . self::table() . ' ORDER BY used_at ASC LIMIT ' . ($count - self::MAX_ENTRIES),
        );
    }
}
