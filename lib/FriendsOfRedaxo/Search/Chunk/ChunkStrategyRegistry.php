<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

use FriendsOfRedaxo\Search\Chunk\Strategy\FixedChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\Strategy\MarkdownChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\Strategy\WholeDocumentChunkStrategy;
use rex_exception;
use rex_extension;
use rex_extension_point;

use function get_debug_type;

/**
 * Kennt alle Zerlegungsverfahren. Fremde AddOns ergaenzen ihre ueber den Extension Point
 * SEARCH_CHUNK_STRATEGIES:
 *
 *     rex_extension::register('SEARCH_CHUNK_STRATEGIES', static function (rex_extension_point $ep) {
 *         $strategies = $ep->getSubject();
 *         $strategies[] = new MyChunkStrategy();
 *         return $strategies;
 *     });
 */
final class ChunkStrategyRegistry
{
    /** Womit eine Quelle anfaengt, wenn nichts eingestellt ist. */
    public const DEFAULT_KEY = 'markdown';

    /** @var array<string, ChunkStrategy>|null */
    private static ?array $strategies = null;

    /**
     * @return array<string, ChunkStrategy> nach Key
     */
    public static function all(): array
    {
        if (null !== self::$strategies) {
            return self::$strategies;
        }

        $strategies = [
            new MarkdownChunkStrategy(),
            new FixedChunkStrategy(),
            new WholeDocumentChunkStrategy(),
        ];
        $strategies = rex_extension::registerPoint(new rex_extension_point('SEARCH_CHUNK_STRATEGIES', $strategies));

        $registry = [];
        foreach ($strategies as $strategy) {
            if (!$strategy instanceof ChunkStrategy) {
                throw new rex_exception('SEARCH_CHUNK_STRATEGIES expects instances of ' . ChunkStrategy::class . ', got ' . get_debug_type($strategy));
            }
            $registry[$strategy->getKey()] = $strategy;
        }

        return self::$strategies = $registry;
    }

    public static function get(string $key): ?ChunkStrategy
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Das eingestellte Verfahren, oder das vorgegebene, wenn der Schluessel nichts
     * trifft -- ein AddOn, das sein Verfahren mitgebracht hat, kann deinstalliert werden.
     */
    public static function getOrDefault(string $key): ChunkStrategy
    {
        return self::get($key) ?? self::get(self::DEFAULT_KEY) ?? new MarkdownChunkStrategy();
    }

    /**
     * @return array<string, string> Key auf Beschriftung
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::all() as $key => $strategy) {
            $options[$key] = $strategy->getLabel();
        }

        return $options;
    }

    public static function reset(): void
    {
        self::$strategies = null;
    }
}
