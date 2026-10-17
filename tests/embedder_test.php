<?php

use FriendsOfRedaxo\Search\Vector\Embedder;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\EmbeddingException;
use PHPUnit\Framework\TestCase;

/**
 * Geprueft wird die Schicht vor dem Dienst, nicht der Dienst: ein Testlauf darf weder
 * Geld kosten noch eine Netzverbindung brauchen.
 *
 * @internal
 */
final class rex_search_embedder_test extends TestCase
{
    public function testTextsAreSentInBatchesNotOneByOne(): void
    {
        // Der gemessene Unterschied: einzeln 172 ms je Abschnitt, zu hundert 4 ms. Fast
        // alles davon ist Wartezeit auf die Verbindung -- wer in kleinen Haeppchen
        // fragt, zahlt sie jedes Mal neu.
        self::assertGreaterThanOrEqual(50, EmbeddingClient::BATCH_SIZE);
    }

    /**
     * Die Antwort wird der Reihe nach den Abschnitten zugeordnet. Stimmt die Anzahl
     * nicht, bekaeme jeder Abschnitt ab der Luecke den Vektor eines anderen -- lautlos,
     * und erst eine Suche lieferte dann Unsinn.
     */
    public function testTooFewVectorsAreRefusedInsteadOfShiftingTheRest(): void
    {
        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('2 Vektoren zu 3 Texten');
        EmbeddingClient::checkShape([[0.1], [0.2]], 3);
    }

    public function testAFlatVectorInsteadOfAListIsRefused(): void
    {
        // generateEmbedding() antwortet bei einem einzelnen Text flach. Ungeprueft
        // wanderte die erste Zahl als ganzer Vektor in die Datenbank.
        $this->expectException(EmbeddingException::class);
        EmbeddingClient::checkShape([0.1, 0.2, 0.3], 3);
    }

    public function testTheRightShapePassesThrough(): void
    {
        self::assertSame([[0.1], [0.2]], EmbeddingClient::checkShape([[0.1], [0.2]], 2));
        self::assertSame([], EmbeddingClient::checkShape([], 0));
    }

    public function testAFailingServiceRaisesItsOwnException(): void
    {
        $client = new class extends EmbeddingClient {
            public function embed(array $texts): array
            {
                throw new EmbeddingException('Kontingent erschöpft');
            }
        };

        $this->expectException(EmbeddingException::class);
        $client->embed(['a']);
    }

    /**
     * Drei Fehlversuche, dann wird der Abschnitt uebergangen. Sonst blockiert ein
     * einziger kaputter Abschnitt bei jedem Lauf dieselben hundert Aufrufe.
     */
    public function testThereIsALimitOnRetries(): void
    {
        self::assertGreaterThan(0, Embedder::MAX_ATTEMPTS);
        self::assertLessThan(10, Embedder::MAX_ATTEMPTS);
    }

    /**
     * Das Budget eines Laufs aus dem Backend richtet sich nach dem Zeitlimit des
     * Servers. Wer darueber hinauslaeuft, bekommt keine Meldung, sondern eine weisse
     * Seite -- und weiss nicht, was von seinem Lauf angekommen ist.
     */
    public function testTheRequestBudgetStaysUnderTheServerLimit(): void
    {
        $before = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '30');
            self::assertLessThan(30.0, Embedder::requestBudget());
            self::assertGreaterThan(10.0, Embedder::requestBudget());

            ini_set('max_execution_time', '12');
            self::assertLessThan(12.0, Embedder::requestBudget());
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }

    /**
     * Und nach oben eine Grenze: eine Anfrage, die anderthalb Minuten schweigt, hilft
     * auch bei erlaubten fuenf Minuten niemandem. Fuer mehr gibt es Cronjob und Konsole.
     */
    public function testTheRequestBudgetIsCappedEvenWithoutAServerLimit(): void
    {
        $before = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '0');
            self::assertSame(Embedder::MAX_REQUEST_SECONDS, Embedder::requestBudget());

            ini_set('max_execution_time', '3600');
            self::assertSame(Embedder::MAX_REQUEST_SECONDS, Embedder::requestBudget());
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }

    /**
     * Ein sehr knappes Limit darf nicht zu einem Budget fuehren, bei dem kein einziger
     * Stapel durchlaeuft -- dann kaeme der Lauf nie voran.
     */
    public function testAVeryTightLimitStillLeavesTimeForOneBatch(): void
    {
        $before = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '5');
            self::assertGreaterThanOrEqual(10.0, Embedder::requestBudget());
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }
}
