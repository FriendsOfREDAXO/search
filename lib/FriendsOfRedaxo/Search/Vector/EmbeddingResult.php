<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use function sprintf;

/**
 * Was ein Durchgang des {@see Embedder} geschafft hat.
 */
final class EmbeddingResult
{
    public function __construct(
        public readonly int $embedded,
        public readonly int $failed,
        public readonly int $remaining,
        public readonly float $seconds,
        public readonly string $message = '',
    ) {}

    /**
     * Ob nichts mehr offen ist. Der Cronjob und die Fortschrittsanzeige haengen daran.
     */
    public function isDone(): bool
    {
        return 0 === $this->remaining;
    }

    public function describe(): string
    {
        $text = sprintf('%d eingebettet, %d offen', $this->embedded, $this->remaining);
        if (0 < $this->failed) {
            $text .= sprintf(', %d fehlgeschlagen', $this->failed);
        }
        if ('' !== $this->message) {
            $text .= ' – ' . $this->message;
        }

        return $text . sprintf(' (%.1f s)', $this->seconds);
    }
}
