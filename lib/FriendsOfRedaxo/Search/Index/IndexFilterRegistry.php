<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use FriendsOfRedaxo\Search\Source\Source;
use rex_exception;
use rex_extension;
use rex_extension_point;

/**
 * Kennt alle Indexfilter. Fremde AddOns melden ihre ueber SEARCH_INDEX_FILTERS an:
 *
 *     rex_extension::register('SEARCH_INDEX_FILTERS', static function (rex_extension_point $ep) {
 *         $filters = $ep->getSubject();
 *         $filters[] = new MyFilter();
 *         return $filters;
 *     });
 */
final class IndexFilterRegistry
{
    /** @var array<string, IndexFilter>|null */
    private static ?array $filters = null;

    /**
     * @return array<string, IndexFilter> nach Schluessel
     */
    public static function all(): array
    {
        if (null !== self::$filters) {
            return self::$filters;
        }

        $filters = rex_extension::registerPoint(new rex_extension_point('SEARCH_INDEX_FILTERS', []));

        $registry = [];
        foreach ($filters as $filter) {
            if (!$filter instanceof IndexFilter) {
                throw new rex_exception('SEARCH_INDEX_FILTERS expects instances of ' . IndexFilter::class . ', got ' . get_debug_type($filter));
            }
            $registry[$filter->getKey()] = $filter;
        }

        return self::$filters = $registry;
    }

    /**
     * Filter, die fuer diese Quelle zur Auswahl stehen.
     *
     * @return array<string, IndexFilter>
     */
    public static function available(Source $source): array
    {
        $available = [];
        foreach (self::all() as $key => $filter) {
            if ($filter->appliesTo($source)) {
                $available[$key] = $filter;
            }
        }

        return $available;
    }

    /**
     * Filter, die an dieser Quelle gesetzt sind und fuer sie gelten, nach Schluessel.
     * Die zugehoerigen Einstellungen stehen in $source->filters.
     *
     * @return array<string, IndexFilter>
     */
    public static function forSource(Source $source): array
    {
        $available = self::available($source);

        $active = [];
        foreach (array_keys($source->filters) as $key) {
            if (isset($available[$key])) {
                $active[$key] = $available[$key];
            }
        }

        return $active;
    }

    public static function reset(): void
    {
        self::$filters = null;
    }
}
