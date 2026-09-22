<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use DateTimeImmutable;
use rex_exception;
use rex_i18n;

/**
 * Eine konfigurierte Quelle, Zeile in rex_search_source.
 */
final class Source
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $typeKey,
        public array $config = [],
        public bool $status = true,
        public ?DateTimeImmutable $lastIndexedAt = null,
        public int $itemCount = 0,
    ) {}

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getType(): ?SourceType
    {
        return SourceTypeRegistry::get($this->typeKey);
    }

    public function requireType(): SourceType
    {
        $type = $this->getType();
        if (null === $type) {
            throw new rex_exception(rex_i18n::rawMsg('search_source_type_missing', $this->typeKey));
        }

        return $type;
    }

    public function requireId(): int
    {
        if (null === $this->id) {
            throw new rex_exception('Source is not saved yet.');
        }

        return $this->id;
    }
}
