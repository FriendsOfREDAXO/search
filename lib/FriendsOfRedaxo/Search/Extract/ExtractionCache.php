<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

use rex_dir;
use rex_file;
use rex_path;
use Throwable;

use function is_array;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Legt extrahiertes Markdown ab, damit ein Neuaufbau eine Datei nicht erneut liest.
 *
 * Das ist keine Feinoptimierung. Ein grosses PDF kostet mit der PHP-Bibliothek gemessen
 * rund fuenfzehn Sekunden; zweihundert Dokumente waeren damit knapp eine Stunde je Lauf.
 * Der Schluessel kommt ohne Dateizugriff aus, er entsteht aus Name, Groesse und Aenderungs-
 * zeitpunkt des Medienpool-Eintrags.
 *
 * Abgelegt wird unter data/ und nicht unter cache/, obwohl es ein Cache ist: rex_delete_cache()
 * raeumt den gesamten cache-Baum ab, und ein Klick auf "Cache leeren" wuerde sonst Stunden an
 * Extraktionsarbeit vernichten. Wegwerfbar bleibt das Verzeichnis trotzdem, siehe {@see clear()}.
 */
final class ExtractionCache
{
    private const DIRECTORY = 'documents';

    public static function key(string $filename, int $filesize, string $updatedAt, string $extractorKey, int $maxCharacters): string
    {
        return sha1(implode("\0", [$filename, (string) $filesize, $updatedAt, $extractorKey, (string) $maxCharacters]));
    }

    public static function get(string $key): ?ExtractedDocument
    {
        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) rex_file::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return ExtractedDocument::fromArray($data);
    }

    public static function put(string $key, ExtractedDocument $document): void
    {
        try {
            rex_file::put(self::path($key), json_encode(
                $document->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } catch (Throwable) {
            // Ein nicht beschreibbarer Cache darf den Indexlauf nicht anhalten.
        }
    }

    public static function clear(): void
    {
        rex_dir::delete(rex_path::addonData('search', self::DIRECTORY));
    }

    /**
     * Zwei Zeichen als Unterverzeichnis, damit nicht zehntausend Dateien nebeneinander liegen.
     */
    private static function path(string $key): string
    {
        return rex_path::addonData('search', self::DIRECTORY . '/' . substr($key, 0, 2) . '/' . $key . '.json');
    }
}
