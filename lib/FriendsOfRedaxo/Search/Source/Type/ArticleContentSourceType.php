<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Text;
use rex;
use rex_article_content;
use rex_i18n;
use rex_sql;
use Throwable;

use function is_string;

/**
 * Artikel-Inhalt: ein Dokument "{article_id}-{clang_id}-fullcontent" je Artikel und Sprache,
 * Inhalt aus den Slice-Werten oder dem gerenderten Artikel.
 */
final class ArticleContentSourceType extends AbstractArticleSourceType
{
    public const MODE_SLICES = 'slices';
    public const MODE_RENDERED = 'rendered';

    public function getKey(): string
    {
        return 'article';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_type_article');
    }

    public function getDescription(): string
    {
        return rex_i18n::msg('search_type_article_description');
    }

    public function getType(Source $source): string
    {
        return 'fullcontent';
    }

    public function getConfigFields(array $config): array
    {
        $fields = parent::getConfigFields($config);
        $fields[] = new ConfigField('mode', rex_i18n::msg('search_article_mode'), ConfigField::SELECT, [
            self::MODE_SLICES => rex_i18n::msg('search_article_mode_slices'),
            self::MODE_RENDERED => rex_i18n::msg('search_article_mode_rendered'),
        ], required: true, default: self::MODE_SLICES);

        return $fields;
    }

    public function describe(Source $source): string
    {
        return parent::describe($source) . ', ' . (self::MODE_RENDERED === $source->getConfig('mode') ? 'rendered' : 'slices');
    }

    public function createDocuments(Source $source, string $itemId): iterable
    {
        $article = $this->resolveArticle($source, $itemId);
        if (null === $article) {
            return [];
        }

        return [
            new IndexDocument(
                $itemId,
                $itemId . '-' . $this->getType($source),
                Text::truncate(Text::fromHtml($article->getName()), 255),
                $this->readContent($source, $article->getId(), $article->getClangId()),
                $this->buildUrl($article),
                $this->readUpdatedAt($article),
                $article->getClangId(),
                $this->buildMeta($article),
            ),
        ];
    }

    private function readContent(Source $source, int $articleId, int $clangId): string
    {
        if (self::MODE_RENDERED === $source->getConfig('mode') && class_exists(rex_article_content::class)) {
            try {
                $content = new rex_article_content($articleId, $clangId);
                $html = $content->getArticle();
                if ('' !== trim($html)) {
                    return Text::fromHtml($html);
                }
            } catch (Throwable) {
                // Module mit Frontend-Annahmen scheitern im Backend/CLI, dann greifen die Slice-Werte.
            }
        }

        $columns = [];
        for ($i = 1; $i <= 20; ++$i) {
            $columns[] = 'value' . $i;
        }
        $rows = rex_sql::factory()->getArray(
            'SELECT ' . implode(', ', $columns) . ' FROM ' . rex::getTable('article_slice')
            . ' WHERE article_id = :article AND clang_id = :clang AND revision = 0 ORDER BY priority',
            ['article' => $articleId, 'clang' => $clangId],
        );

        $parts = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (!is_string($value) || '' === trim($value)) {
                    continue;
                }
                $parts[] = Text::fromHtml($value);
            }
        }

        return Text::join($parts);
    }
}
