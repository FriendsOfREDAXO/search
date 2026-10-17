<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategyRegistry;
use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use rex_exception;
use rex_i18n;

/**
 * Eine konfigurierte Quelle, Zeile in rex_search_source.
 */
final class Source
{
    /**
     * $filters ordnet jedem gesetzten Indexfilter seine Einstellungen zu, Schluessel ist der
     * Filterschluessel, siehe FriendsOfRedaxo\Search\Index\IndexFilter.
     *
     * @param array<string, mixed> $config
     * @param array<string, array<string, mixed>> $filters
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $typeKey,
        public array $config = [],
        public array $filters = [],
        public bool $status = true,
        public ?DateTimeImmutable $lastIndexedAt = null,
        public int $itemCount = 0,
    ) {}

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Die Sprache aller Dokumente dieser Quelle, leer wenn keine angegeben ist.
     *
     * Sie steht in der Konfiguration und wird nirgends hergeleitet; die Begruendung steht
     * bei {@see Language}. Normalisiert wird hier, damit ein ueber einen Extension Point
     * oder von Hand gesetzter Wert wie "de_DE" nicht als unbekannt durchfaellt.
     */
    public function getLanguage(): string
    {
        return Language::normalize((string) $this->getConfig('language', Language::UNKNOWN));
    }

    /**
     * Ob aus dieser Quelle Abschnitte fuer die semantische Suche entstehen.
     *
     * Die Pruefung auf die Datenbank gehoert hierher und nicht an die Aufrufer: auf einem
     * Server ohne Vektoren kann in der Konfiguration trotzdem ein Haken stehen, etwa weil
     * sie von einer anderen Installation uebernommen wurde.
     */
    public function createsChunks(): bool
    {
        return VectorSupport::isAvailable() && (bool) $this->getConfig('create_chunks', false);
    }

    public function getChunkStrategy(): ChunkStrategy
    {
        return ChunkStrategyRegistry::getOrDefault((string) $this->getConfig('chunk_strategy', ChunkStrategyRegistry::DEFAULT_KEY));
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
