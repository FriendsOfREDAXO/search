<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Markdown;
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

        $html = $this->readHtml($source, $article->getId(), $article->getClangId());
        [$raw, $format, $content] = $this->convert($html);

        return [
            new IndexDocument(
                $itemId,
                $itemId . '-' . $this->getType($source),
                Text::truncate(Text::fromHtml($article->getName()), 255),
                $content,
                $this->buildUrl($article),
                $this->readUpdatedAt($article),
                $article->getClangId(),
                $this->buildMeta($article),
                $raw,
                $format,
            ),
        ];
    }

    /**
     * Aus dem HTML des Artikels werden Originalinhalt, Format und Klartext in einem Schritt.
     *
     * Markdown ist hier der Regelfall: Ueberschriften, Listen und Tabellen bleiben erhalten,
     * und der Klartext fuer den Volltext faellt aus demselben Ergebnis ab. Nebenbei wandert
     * damit auch der Alt-Text von Bildern in den Index, der beim blossen Entfernen der Tags
     * verloren ginge. Ohne das vendor-Verzeichnis bleibt es beim bisherigen Verhalten.
     *
     * @return array{string, string, string} Originalinhalt, Format, Klartext
     */
    private function convert(string $html): array
    {
        if (Markdown::isAvailable()) {
            $markdown = Markdown::fromHtml($html);

            return [$markdown, IndexDocument::FORMAT_MARKDOWN, Markdown::toPlainText($markdown)];
        }

        return [$html, IndexDocument::FORMAT_HTML, Text::fromHtml($html)];
    }

    /**
     * Der Inhalt als HTML.
     *
     * Bewusst wird hier nur einmal gerendert: alles Weitere wird aus demselben Ergebnis
     * abgeleitet, ein zweiter Rendervorgang waere der teuerste Teil des Aufbaus.
     */
    private function readHtml(Source $source, int $articleId, int $clangId): string
    {
        if (self::MODE_RENDERED === $source->getConfig('mode') && class_exists(rex_article_content::class)) {
            try {
                $content = new rex_article_content($articleId, $clangId);
                $html = $content->getArticle();
                if ('' !== trim($html)) {
                    return $html;
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
                if (is_string($value) && '' !== trim($value)) {
                    $parts[] = $value;
                }
            }
        }

        // Slice-Werte koennen Markup enthalten und werden deshalb wie HTML behandelt.
        return Text::join($parts);
    }
}
