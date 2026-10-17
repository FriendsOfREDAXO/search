<?php

use FriendsOfRedaxo\Search\Vector\EmbeddingResult;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_embedding_result_test extends TestCase
{
    /**
     * Woran Cronjob und Fortschrittsanzeige haengen: fertig ist nicht "nichts
     * eingebettet", sondern "nichts mehr offen". Ein Lauf, der an seinem Zeitbudget
     * endet, hat etwas geschafft und ist trotzdem nicht fertig.
     */
    public function testDoneMeansNothingLeftOverNotNothingDone(): void
    {
        self::assertTrue((new EmbeddingResult(0, 0, 0, 0.1))->isDone());
        self::assertTrue((new EmbeddingResult(500, 0, 0, 9.0))->isDone());
        self::assertFalse((new EmbeddingResult(500, 0, 12, 9.0))->isDone());
        self::assertFalse((new EmbeddingResult(0, 3, 12, 9.0))->isDone());
    }

    public function testTheDescriptionNamesWhatHappened(): void
    {
        self::assertSame('20 eingebettet, 5 offen (1.5 s)', (new EmbeddingResult(20, 0, 5, 1.5))->describe());
    }

    public function testFailuresAndTheReasonAreNotSwallowed(): void
    {
        $text = (new EmbeddingResult(0, 100, 100, 2.0, 'Kontingent erschöpft'))->describe();

        self::assertStringContainsString('100 fehlgeschlagen', $text);
        self::assertStringContainsString('Kontingent erschöpft', $text);
    }
}
