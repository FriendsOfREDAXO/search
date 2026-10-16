<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceType;
use rex;
use rex_addon;
use rex_i18n;
use rex_media;
use rex_media_category;
use rex_sql;
use rex_url;

use function array_key_exists;
use function in_array;
use function is_array;

/**
 * Gemeinsames fuer Quellentypen, die den Medienpool lesen.
 *
 * Das ist die Auswahl der Medienkategorien samt Unterkategorien, das Aufloesen einer
 * Medien-ID und die Angaben, die in jeden Indexeintrag gehoeren. Was eine Quelle daraus
 * macht, bleibt ihr ueberlassen: {@see MediaSourceType} liest den Dateiinhalt,
 * {@see MediaMetaSourceType} die Angaben am Eintrag.
 */
abstract class AbstractMediaSourceType extends SourceType
{
    public function getNamespace(): string
    {
        return 'media';
    }

    public function isAvailable(): bool
    {
        return rex_addon::get('mediapool')->isAvailable();
    }

    /**
     * Die Kategorienauswahl, die beide Typen haben.
     *
     * @param array<string, mixed> $config
     * @return list<ConfigField>
     */
    protected function getCategoryConfigFields(array $config): array
    {
        $fields = [
            new ConfigField('all_categories', rex_i18n::msg('search_media_all_categories'), ConfigField::CHECKBOX, reloadOnChange: true, default: true),
        ];

        if (!$this->isAllCategories($config)) {
            $fields[] = new ConfigField(
                'category_ids',
                rex_i18n::msg('search_media_categories'),
                ConfigField::MULTISELECT,
                $this->getCategoryTreeOptions(),
                rex_i18n::msg('search_media_categories_notice'),
                required: true,
            );
        }

        return $fields;
    }

    /**
     * Die gewaehlten Kategorien als Text, fuer die Quellenliste.
     */
    protected function describeCategories(Source $source): string
    {
        $categories = $this->getCategoryIds($source);
        if ([] === $categories) {
            return rex_i18n::msg('search_media_all_categories');
        }

        $names = [];
        foreach ($categories as $categoryId) {
            $category = rex_media_category::get($categoryId);
            $names[] = null === $category ? '#' . $categoryId : $category->getName();
        }

        return rex_i18n::msg('search_media_categories') . ': ' . implode(', ', $names);
    }

    /**
     * Die Bedingung auf die gewaehlten Kategorien, leer wenn alle gemeint sind.
     *
     * @param array<string, int|string> $params
     */
    protected function buildCategoryCondition(Source $source, array &$params): ?string
    {
        $categoryIds = $this->collectCategoryIds($this->getCategoryIds($source));
        if ([] === $categoryIds) {
            return null;
        }

        $placeholders = [];
        foreach (array_values($categoryIds) as $i => $categoryId) {
            $placeholders[] = ':cat' . $i;
            $params['cat' . $i] = $categoryId;
        }

        return 'category_id IN (' . implode(', ', $placeholders) . ')';
    }

    protected function isInSelectedCategories(Source $source, rex_media $media): bool
    {
        $categoryIds = $this->getCategoryIds($source);
        if ([] === $categoryIds) {
            return true;
        }

        return in_array($media->getCategoryId(), $this->collectCategoryIds($categoryIds), true);
    }

    /**
     * Die Datensatz-ID ist die numerische Medien-ID, weil sie eine Umbenennung uebersteht.
     * rex_media::get() nimmt dagegen den Dateinamen, deshalb der Umweg ueber eine Abfrage.
     */
    protected function findMedia(string $itemId): ?rex_media
    {
        if (!ctype_digit($itemId)) {
            return null;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT filename FROM ' . rex::getTable('media') . ' WHERE id = :id',
            ['id' => (int) $itemId],
        );
        if ([] === $rows) {
            return null;
        }

        return rex_media::get((string) $rows[0]['filename']);
    }

    protected function readUpdatedAt(rex_media $media): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($media->getUpdateDate());
    }

    protected function buildUrl(rex_media $media): string
    {
        return rex_url::media($media->getFileName());
    }

    /**
     * @return list<int>
     */
    protected function getCategoryIds(Source $source): array
    {
        if ($this->isAllCategories($source->config)) {
            return [];
        }

        $raw = $source->getConfig('category_ids', []);
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        $ids = [];
        foreach ($values as $id) {
            $id = (int) trim((string) $id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Gewaehlte Kategorien samt aller Unterkategorien.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    protected function collectCategoryIds(array $categoryIds): array
    {
        if ([] === $categoryIds) {
            return [];
        }

        $collected = [];
        $walk = static function (int $id) use (&$walk, &$collected): void {
            if (isset($collected[$id])) {
                return;
            }
            $collected[$id] = true;
            $category = rex_media_category::get($id);
            if (null === $category) {
                return;
            }
            foreach ($category->getChildren() as $child) {
                $walk($child->getId());
            }
        };
        foreach ($categoryIds as $id) {
            $walk($id);
        }

        return array_map('intval', array_keys($collected));
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function isAllCategories(array $config): bool
    {
        if (array_key_exists('all_categories', $config)) {
            return (bool) $config['all_categories'];
        }

        $raw = $config['category_ids'] ?? [];

        return is_array($raw) ? [] === array_filter($raw) : '' === trim((string) $raw);
    }

    /**
     * Medienkategorien als flache Optionsliste, Unterkategorien eingerueckt.
     *
     * @return array<string, string>
     */
    protected function getCategoryTreeOptions(): array
    {
        $options = [];
        $walk = static function (array $categories, int $depth) use (&$walk, &$options): void {
            foreach ($categories as $category) {
                $options[(string) $category->getId()] = str_repeat('– ', $depth) . $category->getName() . ' [' . $category->getId() . ']';
                $walk($category->getChildren(), $depth + 1);
            }
        };
        $walk(rex_media_category::getRootCategories() ?? [], 0);

        return $options;
    }
}
