<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Source\SourceType;
use rex_extension_point;
use rex_logger;
use Throwable;

/**
 * Verbindet Extension Points mit dem Indexer. Fehler beim Indizieren duerfen den auslösenden
 * Vorgang (Speichern eines Datensatzes) nicht abbrechen, sie landen im Systemlog.
 */
final class IncrementalUpdater
{
    public static function handle(rex_extension_point $ep, SourceType $type): void
    {
        try {
            $sources = (new SourceRepository())->findByType($type->getKey(), true);
            if ([] === $sources) {
                return;
            }

            $indexer = new Indexer();
            foreach ($sources as $source) {
                foreach ($type->resolveItemIds($ep, $source) as $itemId) {
                    $indexer->reindexItem($source, $itemId);
                }
            }
        } catch (Throwable $exception) {
            rex_logger::logException($exception);
        }
    }
}
