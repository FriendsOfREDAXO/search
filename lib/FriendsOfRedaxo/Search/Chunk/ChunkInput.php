<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

use FriendsOfRedaxo\Search\Document\IndexDocument;

/**
 * Was eine {@see ChunkStrategy} von einem Dokument zu sehen bekommt.
 *
 * Gelesen wird aus dem Index, nicht aus der Quelle: so laesst sich die Zerlegung
 * aendern, ohne jedes PDF noch einmal durch pdftotext zu schicken. Deshalb liegt neben
 * dem Volltext auch der Originalinhalt -- fuer Artikel und Dokumente ist das Markdown,
 * und erst daran ist eine Gliederung zu erkennen.
 */
final class ChunkInput
{
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly ?string $rawContent = null,
        public readonly ?string $rawFormat = null,
    ) {}

    /**
     * Ob der Originalinhalt eine Gliederung mitbringt.
     */
    public function hasStructure(): bool
    {
        return IndexDocument::FORMAT_MARKDOWN === $this->rawFormat && '' !== trim((string) $this->rawContent);
    }

    /**
     * Der Text, auf dem gearbeitet wird: das Markdown, wenn es eines gibt, sonst der
     * Volltext -- und wenn es beides nicht gibt, der Titel.
     *
     * Der Rueckfall auf den Titel ist nicht Verlegenheit, sondern gemessen die bessere
     * Antwort. Ein Datensatz, von dem nur der Name dasteht, ist ueber genau diesen Namen
     * auffindbar: die Frage "Kaninchen" findet den Artikel "Hasen" (fuenf Zeichen) mit
     * einer Aehnlichkeit von 0,501, "Wo finde ich die Anmeldung?" findet
     * "Registrierung" (dreizehn Zeichen) mit 0,665. Ohne den Rueckfall faellt eine
     * solche Quelle stillschweigend ganz aus -- bei einer YForm-Tabelle, deren gewaehltes
     * Feld leer ist, waren das achtzehn von achtzehn Datensaetzen.
     */
    public function getText(): string
    {
        if ($this->hasStructure()) {
            return trim((string) $this->rawContent);
        }

        $content = trim($this->content);

        return '' === $content ? trim($this->title) : $content;
    }

    /**
     * Nichts da, was sich einbetten liesse -- auch kein Titel.
     */
    public function isEmpty(): bool
    {
        return '' === trim($this->title) && '' === trim($this->content) && '' === trim((string) $this->rawContent);
    }
}
