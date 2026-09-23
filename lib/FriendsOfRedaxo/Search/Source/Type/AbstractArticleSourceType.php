<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceType;
use rex;
use rex_addon;
use rex_article;
use rex_category;
use rex_clang;
use rex_i18n;
use rex_sql;
use rex_yrewrite;
use Throwable;

use function array_key_exists;
use function in_array;
use function is_array;

/**
 * Gemeinsame Basis der Artikel-Quellentypen: Auswahl der Artikel ueber Sprachen, Kategorien
 * und Online-Status, Datensatz-ID "{article_id}-{clang_id}", Einzelaktualisierung ueber die
 * ART_*- und SLICE_*-Extension-Points. Was aus einem Artikel indiziert wird, bestimmt die
 * konkrete Unterklasse.
 */
abstract class AbstractArticleSourceType extends SourceType
{
    public function getNamespace(): string
    {
        return 'article';
    }

    public function isAvailable(): bool
    {
        return rex_addon::get('structure')->isAvailable();
    }

    public function getConfigFields(array $config): array
    {
        $clangs = [];
        foreach (rex_clang::getAll() as $clang) {
            $clangs[(string) $clang->getId()] = $clang->getName() . ' (' . $clang->getCode() . ')';
        }

        $fields = [
            new ConfigField('clang_ids', rex_i18n::msg('search_article_clangs'), ConfigField::MULTISELECT, $clangs, rex_i18n::msg('search_article_clangs_notice')),
            new ConfigField('all_categories', rex_i18n::msg('search_article_all_categories'), ConfigField::CHECKBOX, reloadOnChange: true, default: true),
        ];

        if (!$this->isAllCategories($config)) {
            $fields[] = new ConfigField(
                'category_ids',
                rex_i18n::msg('search_article_categories'),
                ConfigField::MULTISELECT,
                $this->getCategoryTreeOptions(),
                rex_i18n::msg('search_article_categories_notice'),
                required: true,
            );
        }

        $fields[] = new ConfigField('include_offline', rex_i18n::msg('search_article_include_offline'), ConfigField::CHECKBOX);

        return $fields;
    }

    public function getIndexIdPattern(Source $source): string
    {
        return '{article_id}-{clang_id}-' . $this->getType($source);
    }

    public function describe(Source $source): string
    {
        $categories = $this->getCategoryIds($source);
        if ([] === $categories) {
            return rex_i18n::msg('search_article_all_categories');
        }

        $names = [];
        foreach ($categories as $categoryId) {
            $category = rex_category::get($categoryId);
            $names[] = null === $category ? '#' . $categoryId : $category->getName();
        }

        return rex_i18n::msg('search_article_categories') . ': ' . implode(', ', $names);
    }

    public function countItems(Source $source): ?int
    {
        [$where, $params] = $this->buildWhere($source);
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . rex::getTable('article') . $where, $params);

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function getItemIds(Source $source, int $offset, int $limit): array
    {
        [$where, $params] = $this->buildWhere($source);
        $rows = rex_sql::factory()->getArray(
            'SELECT id, clang_id FROM ' . rex::getTable('article') . $where . ' ORDER BY id, clang_id LIMIT ' . max(0, $offset) . ', ' . max(1, $limit),
            $params,
        );

        return array_map(static fn (array $row): string => $row['id'] . '-' . $row['clang_id'], $rows);
    }

    /**
     * Loest eine Datensatz-ID in den Artikel auf, wenn er in den Filter der Quelle faellt.
     */
    protected function resolveArticle(Source $source, string $itemId): ?rex_article
    {
        if (!preg_match('/^(\d+)-(\d+)$/', $itemId, $match)) {
            return null;
        }

        $article = rex_article::get((int) $match[1], (int) $match[2]);
        if (null === $article || !$this->matches($source, $article)) {
            return null;
        }

        return $article;
    }

    protected function buildUrl(rex_article $article): string
    {
        try {
            if (class_exists('rex_yrewrite')) {
                return (string) rex_yrewrite::getFullUrlByArticleId($article->getId(), $article->getClangId());
            }

            return rex_getUrl($article->getId(), $article->getClangId());
        } catch (Throwable) {
            return '';
        }
    }

    protected function readUpdatedAt(rex_article $article): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($article->getUpdateDate());
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMeta(rex_article $article): array
    {
        return ['article_id' => $article->getId(), 'category_id' => $article->getCategoryId()];
    }

    /**
     * @return array{string, array<string, int|string>}
     */
    private function buildWhere(Source $source): array
    {
        $conditions = [];
        $params = [];

        if (!$source->getConfig('include_offline', false)) {
            $conditions[] = 'status = 1';
        }

        $clangIds = (array) $source->getConfig('clang_ids', []);
        if ([] !== $clangIds) {
            $placeholders = [];
            foreach (array_values($clangIds) as $i => $clangId) {
                $placeholders[] = ':clang' . $i;
                $params['clang' . $i] = (int) $clangId;
            }
            $conditions[] = 'clang_id IN (' . implode(', ', $placeholders) . ')';
        }

        $categoryIds = $this->getCategoryIds($source);
        if ([] !== $categoryIds) {
            $categoryConditions = [];
            foreach ($categoryIds as $i => $categoryId) {
                $categoryConditions[] = '(id = :cat' . $i . ' OR parent_id = :cat' . $i . ' OR path LIKE :catpath' . $i . ')';
                $params['cat' . $i] = $categoryId;
                $params['catpath' . $i] = '%|' . $categoryId . '|%';
            }
            $conditions[] = '(' . implode(' OR ', $categoryConditions) . ')';
        }

        return [[] === $conditions ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function matches(Source $source, rex_article $article): bool
    {
        // Ein Artikel unter einer offline geschalteten Kategorie ist im Frontend nicht
        // erreichbar und gehoert deshalb nicht in den Index.
        if (!$source->getConfig('include_offline', false) && !$article->isOnlineIncludingParents()) {
            return false;
        }

        if (!in_array($article->getClangId(), $this->getClangIds($source), true)) {
            return false;
        }

        $categoryIds = $this->getCategoryIds($source);
        if ([] === $categoryIds) {
            return true;
        }

        $articleCategories = array_merge([$article->getId(), $article->getCategoryId()], array_map('intval', $article->getPathAsArray()));

        return [] !== array_intersect($categoryIds, $articleCategories);
    }

    /**
     * Gewaehlte Kategorien; leer bedeutet die gesamte Struktur.
     *
     * @return list<int>
     */
    private function getCategoryIds(Source $source): array
    {
        if ($this->isAllCategories($source->config)) {
            return [];
        }

        $raw = $source->getConfig('category_ids', []);
        // Aeltere Quellen haben die IDs als kommagetrennten Text gespeichert
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
     * @param array<string, mixed> $config
     */
    private function isAllCategories(array $config): bool
    {
        if (array_key_exists('all_categories', $config)) {
            return (bool) $config['all_categories'];
        }

        // Quellen ohne das Feld: alle Kategorien, sofern keine IDs hinterlegt sind
        $raw = $config['category_ids'] ?? [];

        return is_array($raw) ? [] === array_filter($raw) : '' === trim((string) $raw);
    }

    /**
     * Kategoriebaum als flache Optionsliste, Unterkategorien eingerueckt.
     *
     * @return array<string, string>
     */
    private function getCategoryTreeOptions(): array
    {
        $options = [];
        $walk = static function (array $categories, int $depth) use (&$walk, &$options): void {
            foreach ($categories as $category) {
                $options[(string) $category->getId()] = str_repeat('– ', $depth) . $category->getName() . ' [' . $category->getId() . ']';
                $walk($category->getChildren(), $depth + 1);
            }
        };
        $walk(rex_category::getRootCategories(), 0);

        return $options;
    }

    /**
     * @return list<int>
     */
    private function getClangIds(Source $source): array
    {
        $configured = array_map('intval', (array) $source->getConfig('clang_ids', []));

        return [] === $configured ? rex_clang::getAllIds() : $configured;
    }
}
