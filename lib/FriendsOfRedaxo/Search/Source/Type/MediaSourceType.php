<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Extract\DocumentExtractorRegistry;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionCache;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Markdown;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Text;
use rex;
use rex_i18n;
use rex_media;
use rex_path;
use rex_sql;
use rex_url;

use function in_array;

/**
 * Dokumente aus dem Medienpool: ein Dokument je Datei, Inhalt als Markdown.
 *
 * Womit eine Datei gelesen wird, entscheidet die {@see DocumentExtractorRegistry}. Dieser
 * Typ kennt weder PDF noch Office; er waehlt die Dateien aus, haelt das Ergebnis im Cache
 * und baut daraus den Indexeintrag.
 */
final class MediaSourceType extends AbstractMediaSourceType
{
    public const DEFAULT_MAX_FILESIZE_MB = 20;

    public function getKey(): string
    {
        return 'media';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_type_media');
    }

    public function getDescription(): string
    {
        return rex_i18n::msg('search_type_media_description');
    }

    public function getType(Source $source): string
    {
        return 'document';
    }

    public function getIndexIdPattern(Source $source): string
    {
        return '{media_id}-' . $this->getType($source);
    }

    protected function getOwnConfigFields(array $config): array
    {
        $extensions = [];
        foreach (DocumentExtractorRegistry::supportedExtensions() as $extension) {
            $extensions[$extension] = '.' . $extension;
        }

        $fields = $this->getCategoryConfigFields($config);

        $fields[] = new ConfigField(
            'extensions',
            rex_i18n::msg('search_media_extensions'),
            ConfigField::MULTISELECT,
            $extensions,
            rex_i18n::msg('search_media_extensions_notice'),
        );
        $fields[] = new ConfigField(
            'max_filesize',
            rex_i18n::msg('search_media_max_filesize'),
            ConfigField::TEXT,
            notice: rex_i18n::msg('search_media_max_filesize_notice'),
            default: (string) self::DEFAULT_MAX_FILESIZE_MB,
        );
        $fields[] = new ConfigField(
            'include_meta',
            rex_i18n::msg('search_media_include_meta'),
            ConfigField::CHECKBOX,
            notice: rex_i18n::msg('search_media_include_meta_notice'),
            default: true,
        );
        $fields[] = new ConfigField(
            'prefer_file_title',
            rex_i18n::msg('search_media_prefer_file_title'),
            ConfigField::CHECKBOX,
            notice: rex_i18n::msg('search_media_prefer_file_title_notice'),
        );

        return $fields;
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        $size = trim((string) ($config['max_filesize'] ?? ''));
        if ('' !== $size && (!ctype_digit($size) || (int) $size < 1)) {
            $errors['max_filesize'] = rex_i18n::msg('search_media_max_filesize_invalid');
        }

        return $errors;
    }

    public function describe(Source $source): string
    {
        $parts = [$this->describeCategories($source)];

        $extensions = $this->getExtensions($source);
        $parts[] = [] === $extensions
            ? rex_i18n::msg('search_media_extensions_all')
            : implode(', ', array_map(static fn (string $e): string => '.' . $e, $extensions));

        return implode(' – ', $parts);
    }

    public function countItems(Source $source): ?int
    {
        [$where, $params] = $this->buildWhere($source);
        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . rex::getTable('media') . $where, $params);

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function getItemIds(Source $source, int $offset, int $limit): array
    {
        [$where, $params] = $this->buildWhere($source);
        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('media') . $where . ' ORDER BY id LIMIT ' . max(0, $offset) . ', ' . max(1, $limit),
            $params,
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    public function createDocuments(Source $source, string $itemId): iterable
    {
        $media = $this->resolveMedia($source, $itemId);
        if (null === $media) {
            return [];
        }

        $extracted = $this->read($source, $media);
        if (null === $extracted || $extracted->isEmpty()) {
            return [];
        }

        $markdown = $this->withMeta($source, $media, $extracted->markdown);
        $title = $this->buildTitle($source, $media, $extracted);

        $meta = $extracted->meta + [
            'media_id' => $media->getId(),
            'category_id' => $media->getCategoryId(),
            'filename' => $media->getFileName(),
            'filesize' => (int) $media->getSize(),
        ];
        if ($extracted->truncated) {
            $meta['truncated'] = true;
        }

        return [
            new IndexDocument(
                $itemId,
                $itemId . '-' . $this->getType($source),
                Text::truncate($title, 255),
                Markdown::toPlainText($markdown),
                rex_url::media($media->getFileName()),
                $this->readUpdatedAt($media),
                null,
                $meta,
                $markdown,
                IndexDocument::FORMAT_MARKDOWN,
            ),
        ];
    }

    /**
     * Liest die Datei, bevorzugt aus dem Cache. Der Schluessel haengt an Name, Groesse und
     * Aenderungszeitpunkt; solange sich davon nichts aendert, wird die Datei nicht angefasst.
     */
    private function read(Source $source, rex_media $media): ?ExtractedDocument
    {
        $extension = mb_strtolower($media->getExtension());
        $extractor = DocumentExtractorRegistry::forFile($extension, $media->getType());
        if (null === $extractor) {
            return null;
        }

        $key = ExtractionCache::key(
            $media->getFileName(),
            (int) $media->getSize(),
            (string) $media->getValue('updatedate'),
            $extractor->getKey(),
            ExtractionContext::DEFAULT_MAX_CHARACTERS,
            $extractor->getVersion(),
        );

        $cached = ExtractionCache::get($key);
        if (null !== $cached) {
            return $cached;
        }

        $path = rex_path::media($media->getFileName());
        if (!is_file($path)) {
            return null;
        }

        $extracted = $extractor->extract($path, new ExtractionContext(
            $media->getFileName(),
            $media->getType(),
            ExtractionContext::DEFAULT_MAX_CHARACTERS,
        ));

        if (null === $extracted) {
            // Auch das Scheitern wird gemerkt, sonst kostet eine kaputte Datei bei jedem Lauf
            // erneut die volle Verarbeitungszeit.
            $extracted = new ExtractedDocument('', null, ['extractor' => $extractor->getKey(), 'failed' => true]);
        }

        ExtractionCache::put($key, $extracted);

        return $extracted;
    }

    /**
     * Titel, Beschreibung und Copyright gehoeren zum Dokument und sollen mitgefunden werden.
     */
    private function withMeta(Source $source, rex_media $media, string $markdown): string
    {
        if (!$source->getConfig('include_meta', true)) {
            return $markdown;
        }

        $parts = [];
        foreach (['title', 'med_alt', 'med_copyright', 'tags'] as $field) {
            $value = trim((string) $media->getValue($field));
            if ('' !== $value) {
                $parts[] = $value;
            }
        }

        if ([] === $parts) {
            return $markdown;
        }

        return implode("\n\n", $parts) . "\n\n" . $markdown;
    }

    private function buildTitle(Source $source, rex_media $media, ExtractedDocument $extracted): string
    {
        if ($source->getConfig('prefer_file_title', false) && null !== $extracted->title && '' !== trim($extracted->title)) {
            return trim($extracted->title);
        }

        $title = trim((string) $media->getTitle());
        if ('' !== $title) {
            return $title;
        }

        if (null !== $extracted->title && '' !== trim($extracted->title)) {
            return trim($extracted->title);
        }

        return $media->getFileName();
    }

    private function resolveMedia(Source $source, string $itemId): ?rex_media
    {
        $media = $this->findMedia($itemId);

        return null !== $media && $this->matches($source, $media) ? $media : null;
    }

    private function matches(Source $source, rex_media $media): bool
    {
        $extensions = $this->getExtensions($source);
        $extension = mb_strtolower($media->getExtension());

        if ([] === $extensions) {
            if (null === DocumentExtractorRegistry::forFile($extension, $media->getType())) {
                return false;
            }
        } elseif (!in_array($extension, $extensions, true)) {
            return false;
        }

        if ((int) $media->getSize() > $this->getMaxFilesize($source)) {
            return false;
        }

        return $this->isInSelectedCategories($source, $media);
    }

    /**
     * @return array{string, array<string, int|string>}
     */
    private function buildWhere(Source $source): array
    {
        $conditions = [];
        $params = [];

        $extensions = $this->getExtensions($source);
        if ([] === $extensions) {
            $extensions = DocumentExtractorRegistry::supportedExtensions();
        }
        if ([] === $extensions) {
            // Ohne Extraktor gibt es nichts zu indizieren.
            return [' WHERE 1 = 0', []];
        }
        $placeholders = [];
        foreach (array_values($extensions) as $i => $extension) {
            $placeholders[] = ':ext' . $i;
            $params['ext' . $i] = '%.' . $extension;
        }
        $conditions[] = '(' . implode(' OR ', array_map(static fn (string $p): string => 'LOWER(filename) LIKE ' . $p, $placeholders)) . ')';

        $conditions[] = 'CAST(filesize AS UNSIGNED) <= :maxsize';
        $params['maxsize'] = $this->getMaxFilesize($source);

        $category = $this->buildCategoryCondition($source, $params);
        if (null !== $category) {
            $conditions[] = $category;
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function getMaxFilesize(Source $source): int
    {
        $megabytes = (int) $source->getConfig('max_filesize', self::DEFAULT_MAX_FILESIZE_MB);

        return max(1, $megabytes) * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    private function getExtensions(Source $source): array
    {
        $values = (array) $source->getConfig('extensions', []);

        $extensions = [];
        foreach ($values as $value) {
            $value = mb_strtolower(ltrim(trim((string) $value), '.'));
            if ('' !== $value) {
                $extensions[] = $value;
            }
        }

        return array_values(array_unique($extensions));
    }
}
