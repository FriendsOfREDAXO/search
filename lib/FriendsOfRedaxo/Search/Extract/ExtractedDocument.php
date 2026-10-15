<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

/**
 * Ergebnis einer Extraktion: der Inhalt einer Datei als Markdown.
 *
 * $title ist das, was die Datei selbst ueber sich sagt, etwa der Titel aus den PDF-Metadaten
 * oder die erste Ueberschrift. Er ist ein Vorschlag; der Quellentyp entscheidet, ob er ihn
 * dem Titel aus dem Medienpool vorzieht.
 */
final class ExtractedDocument
{
    /**
     * @param array<string, mixed> $meta Angaben ueber die Datei, etwa Seitenzahl oder Blattnamen
     */
    public function __construct(
        public readonly string $markdown,
        public readonly ?string $title = null,
        public readonly array $meta = [],
        public readonly bool $truncated = false,
    ) {}

    public function isEmpty(): bool
    {
        return '' === trim($this->markdown);
    }

    /**
     * @return array{markdown: string, title: string|null, meta: array<string, mixed>, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'markdown' => $this->markdown,
            'title' => $this->title,
            'meta' => $this->meta,
            'truncated' => $this->truncated,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['markdown'] ?? ''),
            isset($data['title']) ? (string) $data['title'] : null,
            (array) ($data['meta'] ?? []),
            (bool) ($data['truncated'] ?? false),
        );
    }
}
