<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

/**
 * Ein Abschnitt eines Dokuments, so wie ihn eine {@see ChunkStrategy} schneidet.
 *
 * $headingPath ist der Weg durch die Ueberschriften bis hierher, etwa
 * "Haushalt 2026 > Ergebnishaushalt > Ertraege". Er steht aus zwei Gruenden daneben und
 * nicht im Text: eine Antwortmaschine braucht ihn, um zu sagen, woher ein Satz stammt,
 * und er wird dem Text beim Einbetten vorangestellt, damit ein Abschnitt auch dann
 * einordenbar bleibt, wenn er selbst keine Ueberschrift enthaelt.
 */
final class Chunk
{
    public function __construct(
        public readonly string $text,
        public readonly string $headingPath = '',
    ) {}

    /**
     * Der Text, wie er eingebettet wird: Ueberschriftenpfad voran.
     */
    public function getEmbeddableText(): string
    {
        return '' === $this->headingPath ? $this->text : $this->headingPath . "\n\n" . $this->text;
    }

    /**
     * Der Schluessel, an dem ein unveraenderter Abschnitt wiedererkannt wird.
     *
     * Er entscheidet, ob ein Vektor einen Neuaufbau ueberlebt, und muss deshalb alles
     * enthalten, was eingebettet wird -- der Ueberschriftenpfad gehoert dazu.
     */
    public function getHash(): string
    {
        return sha1($this->headingPath . "\0" . $this->text);
    }
}
