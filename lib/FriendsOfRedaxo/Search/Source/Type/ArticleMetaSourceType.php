<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Text;
use rex;
use rex_article;
use rex_i18n;
use rex_sql;
use Throwable;

use function is_float;
use function is_int;
use function is_string;

/**
 * Artikel-Metadaten: ein Dokument "{article_id}-{clang_id}-meta" je Artikel und Sprache aus
 * den gewaehlten Feldern der Artikeltabelle, also Name plus die vorhandenen Metainfo-Felder.
 */
final class ArticleMetaSourceType extends AbstractArticleSourceType
{
    public function getKey(): string
    {
        return 'article_meta';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_type_article_meta');
    }

    public function getDescription(): string
    {
        return rex_i18n::msg('search_type_article_meta_description');
    }

    public function getType(Source $source): string
    {
        return 'meta';
    }

    public function getConfigFields(array $config): array
    {
        $fields = parent::getConfigFields($config);
        $fields[] = new ConfigField(
            'fields',
            rex_i18n::msg('search_article_meta_fields'),
            ConfigField::MULTISELECT,
            $this->getAvailableFields(),
            rex_i18n::msg('search_article_meta_fields_notice'),
            required: true,
            default: ['name'],
        );

        return $fields;
    }

    public function describe(Source $source): string
    {
        return parent::describe($source) . ', ' . implode(', ', $this->getSelectedFields($source));
    }

    public function createDocuments(Source $source, string $itemId): iterable
    {
        $article = $this->resolveArticle($source, $itemId);
        if (null === $article) {
            return [];
        }

        $parts = [];
        $rawParts = [];
        foreach ($this->getSelectedFields($source) as $field) {
            $value = $article->getValue($field);
            if (is_string($value) || is_int($value) || is_float($value)) {
                $parts[$field] = Text::fromHtml((string) $value);
                $rawParts[$field] = (string) $value;
            }
        }

        return [
            new IndexDocument(
                $itemId,
                $itemId . '-' . $this->getType($source),
                Text::truncate(Text::fromHtml($article->getName()), 255),
                Text::join($parts),
                $this->buildUrl($article),
                $this->readUpdatedAt($article),
                $article->getClangId(),
                $this->buildMeta($article) + ['fields' => array_keys($parts)],
                Text::join($rawParts),
                IndexDocument::FORMAT_TEXT,
            ),
        ];
    }

    /**
     * Name plus die aktuell definierten Artikel-Metainfo-Felder (art_*), Label aus der
     * Metainfo-Definition.
     *
     * @return array<string, string>
     */
    private function getAvailableFields(): array
    {
        $fields = ['name' => rex_i18n::msg('search_article_meta_field_name') . ' (name)'];

        $titles = [];
        try {
            foreach (rex_sql::factory()->getArray('SELECT name, title FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE "art_%" ORDER BY priority, name') as $row) {
                $titles[(string) $row['name']] = rex_i18n::translate((string) $row['title'], false);
            }
        } catch (Throwable) {
            // metainfo nicht installiert: nur die art_*-Spalten der Artikeltabelle bleiben
        }

        foreach (rex_article::getClassVars() as $column) {
            if (!str_starts_with($column, 'art_')) {
                continue;
            }
            $label = trim($titles[$column] ?? '');
            $fields[$column] = ('' !== $label ? $label . ' ' : '') . '(' . $column . ')';
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function getSelectedFields(Source $source): array
    {
        $available = $this->getAvailableFields();
        $selected = array_values(array_filter(
            (array) $source->getConfig('fields', ['name']),
            static fn ($field) => is_string($field) && isset($available[$field]),
        ));

        return [] === $selected ? ['name'] : $selected;
    }
}
