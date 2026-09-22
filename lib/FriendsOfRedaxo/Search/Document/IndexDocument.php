<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Document;

use DateTimeImmutable;

use const JSON_THROW_ON_ERROR;

/**
 * Ein Eintrag im Volltextindex, so wie ihn ein Quellentyp erzeugt.
 *
 * $itemId identifiziert den Datensatz der Quelle (z. B. die YForm-ID oder "artikel-clang"),
 * $indexId das konkrete Dokument daraus (z. B. "17-content" oder "42-1-fullcontent").
 * Ein Datensatz kann mehrere Dokumente liefern; beim Neuaufbau eines Datensatzes werden
 * alle Dokumente mit derselben $itemId ersetzt.
 */
final class IndexDocument
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $itemId,
        public readonly string $indexId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $url = '',
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly ?int $clangId = null,
        public readonly array $meta = [],
    ) {}

    public function getContentHash(): string
    {
        return sha1(implode("\0", [
            $this->title,
            $this->content,
            $this->url,
            (string) $this->clangId,
            json_encode($this->meta, JSON_THROW_ON_ERROR),
        ]));
    }
}
