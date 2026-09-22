<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

final class IndexResult
{
    public function __construct(
        public readonly int $items,
        public readonly int $documentsWritten,
        public readonly int $documentsDeleted,
    ) {}
}
