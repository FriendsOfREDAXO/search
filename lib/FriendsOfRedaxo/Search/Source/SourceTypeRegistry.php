<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use FriendsOfRedaxo\Search\Source\Type\ArticleContentSourceType;
use FriendsOfRedaxo\Search\Source\Type\ArticleMetaSourceType;
use FriendsOfRedaxo\Search\Source\Type\YFormTableSourceType;
use rex_exception;
use rex_extension;
use rex_extension_point;

/**
 * Kennt alle Quellentypen. Eingebaute Typen werden hier angelegt, fremde AddOns ergaenzen
 * ihre ueber den Extension Point SEARCH_SOURCE_TYPES:
 *
 *     rex_extension::register('SEARCH_SOURCE_TYPES', static function (rex_extension_point $ep) {
 *         $types = $ep->getSubject();
 *         $types[] = new MySourceType();
 *         return $types;
 *     });
 *
 * Typen, deren isAvailable() false liefert, werden ausgeblendet.
 */
final class SourceTypeRegistry
{
    /** @var array<string, SourceType>|null */
    private static ?array $types = null;

    /**
     * @return array<string, SourceType> nach Key
     */
    public static function all(): array
    {
        if (null !== self::$types) {
            return self::$types;
        }

        $types = [
            new YFormTableSourceType(),
            new ArticleContentSourceType(),
            new ArticleMetaSourceType(),
        ];
        $types = rex_extension::registerPoint(new rex_extension_point('SEARCH_SOURCE_TYPES', $types));

        $registry = [];
        foreach ($types as $type) {
            if (!$type instanceof SourceType) {
                throw new rex_exception('SEARCH_SOURCE_TYPES expects instances of ' . SourceType::class . ', got ' . get_debug_type($type));
            }
            if (!$type->isAvailable()) {
                continue;
            }
            $registry[$type->getKey()] = $type;
        }

        return self::$types = $registry;
    }

    public static function get(string $key): ?SourceType
    {
        return self::all()[$key] ?? null;
    }

    public static function reset(): void
    {
        self::$types = null;
    }
}
