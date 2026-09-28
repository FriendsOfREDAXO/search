<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

/**
 * $items zaehlt die indizierten Datensaetze, $skipped die von Filtern abgelehnten.
 */
final class IndexResult
{
    public function __construct(
        public readonly int $items,
        public readonly int $documentsWritten,
        public readonly int $documentsDeleted,
        public readonly int $skipped = 0,
    ) {}
}
