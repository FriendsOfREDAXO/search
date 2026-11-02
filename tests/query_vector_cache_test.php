<?php

use FriendsOfRedaxo\Search\Vector\QueryVectorCache;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_query_vector_cache_test extends TestCase
{
    /**
     * Die Obergrenze ist eine Vorgabe, keine feste Groesse: Ein Betrieb mit viel
     * Verkehr und wiederkehrenden Fragen traegt mehr Eintraege.
     */
    public function testTheLimitCanBeRaised(): void
    {
        $before = rex_config::get('search', 'query_cache_entries');

        try {
            rex_config::set('search', 'query_cache_entries', 10000);
            self::assertSame(10000, QueryVectorCache::maxEntries());

            // Ein versehentlich leeres Feld darf nicht heissen "nichts behalten" --
            // das Aufraeumen wuerde den Zwischenspeicher sonst bei jedem Schreiben
            // vollstaendig leeren.
            rex_config::set('search', 'query_cache_entries', 0);
            self::assertSame(QueryVectorCache::MAX_ENTRIES, QueryVectorCache::maxEntries());
        } finally {
            rex_config::set('search', 'query_cache_entries', $before);
        }
    }

    /**
     * Das Profil gehoert in den Schluessel. Nach einem Wechsel des Modells waere der
     * gemerkte Vektor derselben Frage sonst falsch -- und zwar auf eine Weise, die
     * niemandem auffiele: er haette die richtige Laenge und zeigte nur woandershin.
     */
    public function testTheSameQuestionUnderTwoProfilesIsTwoEntries(): void
    {
        self::assertNotSame(
            QueryVectorCache::hash('Wie hoch ist die Steuer?', 16),
            QueryVectorCache::hash('Wie hoch ist die Steuer?', 17),
        );
    }

    public function testCaseAndSurroundingSpacesDoNotMatter(): void
    {
        $erwartet = QueryVectorCache::hash('burg eppstein', 16);

        self::assertSame($erwartet, QueryVectorCache::hash('Burg Eppstein', 16));
        self::assertSame($erwartet, QueryVectorCache::hash('  BURG EPPSTEIN  ', 16));
    }

    /**
     * Weiter wird nicht vereinheitlicht: jede zusaetzliche Normalisierung veraendert die
     * Frage, und eine veraenderte Frage hat einen anderen Vektor.
     */
    public function testDifferentWordingIsADifferentQuestion(): void
    {
        self::assertNotSame(
            QueryVectorCache::hash('burg eppstein', 16),
            QueryVectorCache::hash('eppstein burg', 16),
        );
        self::assertNotSame(
            QueryVectorCache::hash('Wie hoch ist die Steuer?', 16),
            QueryVectorCache::hash('Wie hoch ist die Steuer', 16),
        );
    }
}
