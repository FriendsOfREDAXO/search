<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Source\SourceType;

use function count;

/**
 * Fuehrt Quellentyp und Index zusammen. Kennt weder YForm noch Artikel.
 */
final class Indexer
{
    public const CHUNK_SIZE = 100;

    private IndexRepository $index;
    private SourceRepository $sources;

    public function __construct(?IndexRepository $index = null, ?SourceRepository $sources = null)
    {
        $this->index = $index ?? new IndexRepository();
        $this->sources = $sources ?? new SourceRepository();
    }

    /**
     * Baut den Index einer Quelle komplett neu auf. Dokumente, die der Lauf nicht angefasst hat,
     * werden am Ende entfernt; so verschwinden geloeschte Datensaetze ohne eigene Buchfuehrung.
     *
     * @param (callable(int $done, ?int $total): void)|null $onProgress
     */
    public function rebuild(Source $source, ?callable $onProgress = null): IndexResult
    {
        $type = $source->requireType();
        $startedAt = new DateTimeImmutable();
        $total = $type->countItems($source);

        // Ein abgelehnter Datensatz wird nicht angefasst und faellt deshalb am Ende des Laufs
        // zusammen mit den veralteten Dokumenten aus dem Index.
        $filters = IndexFilterRegistry::forSource($source);

        $offset = 0;
        $items = 0;
        $written = 0;
        $skipped = 0;
        while (true) {
            $itemIds = $type->getItemIds($source, $offset, self::CHUNK_SIZE);
            if ([] === $itemIds) {
                break;
            }
            foreach ($itemIds as $itemId) {
                if (!$this->accepted($source, $itemId, $filters)) {
                    ++$skipped;
                    continue;
                }
                $written += $this->indexItem($source, $type, $itemId, $startedAt, false);
                ++$items;
            }
            $offset += count($itemIds);
            if (null !== $onProgress) {
                $onProgress($items + $skipped, $total);
            }
            if (count($itemIds) < self::CHUNK_SIZE) {
                break;
            }
        }

        $deleted = $this->index->deleteStale($source->requireId(), $startedAt);
        $this->sources->updateStats($source->requireId(), $items, $startedAt);

        return new IndexResult($items, $written, $deleted, $skipped);
    }

    /**
     * Alle gesetzten Filter muessen zustimmen.
     *
     * @param array<string, IndexFilter> $filters
     */
    private function accepted(Source $source, string $itemId, array $filters): bool
    {
        foreach ($filters as $key => $filter) {
            if (!$filter->accepts($source, $itemId, $source->filters[$key] ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aktualisiert einen einzelnen Datensatz. Wird vom AddOn selbst nicht aufgerufen, der
     * Index wird immer vollstaendig neu aufgebaut; gedacht fuer fremden Code, der eine
     * gezielte Aktualisierung braucht. Liefert der Typ keine Dokumente mehr, werden die
     * alten entfernt.
     */
    public function reindexItem(Source $source, string $itemId): int
    {
        if (!$this->accepted($source, $itemId, IndexFilterRegistry::forSource($source))) {
            $this->index->deleteByItem($source->requireId(), $itemId);

            return 0;
        }

        return $this->indexItem($source, $source->requireType(), $itemId, new DateTimeImmutable(), true);
    }

    private function indexItem(Source $source, SourceType $type, string $itemId, DateTimeImmutable $at, bool $cleanup): int
    {
        $namespace = $type->getNamespace();
        $typeName = $type->getType($source);

        $written = 0;
        $indexIds = [];
        foreach ($type->createDocuments($source, $itemId) as $document) {
            $indexIds[] = $document->indexId;
            if ($this->index->store($source, $namespace, $typeName, $document, $at)) {
                ++$written;
            }
        }

        if ($cleanup) {
            $this->index->deleteByItem($source->requireId(), $itemId, $indexIds);
        }

        return $written;
    }
}
