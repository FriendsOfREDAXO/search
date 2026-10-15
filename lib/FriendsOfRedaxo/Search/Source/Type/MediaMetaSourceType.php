<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Text;
use rex;
use rex_i18n;
use rex_media;
use rex_sql;
use Throwable;

use function is_float;
use function is_int;
use function is_string;

/**
 * Die Angaben am Medienpool-Eintrag: ein Dokument "{media_id}-meta" je Datei.
 *
 * Das Gegenstueck zu {@see MediaSourceType}, so wie {@see ArticleMetaSourceType} eines zu
 * {@see ArticleContentSourceType} ist. Dort wird die Datei geoeffnet und ihr Inhalt
 * gelesen, hier steht, was am Eintrag gepflegt ist.
 *
 * Der Unterschied ist groesser, als er klingt: Ein Extraktor gibt es nur fuer Dokumente,
 * also fuer einen kleinen Teil eines gewoehnlichen Medienpools. In der Testinstallation
 * waren das acht von 153 Dateien -- die uebrigen 145 sind Bilder und ein Video, und alle
 * 153 haben einen gepflegten Titel. Ohne diesen Typ bleibt also der groesste Teil des
 * Medienpools unauffindbar, auch wenn jemand die Muehe hatte, jedes Bild zu beschriften.
 */
final class MediaMetaSourceType extends AbstractMediaSourceType
{
    /** Ohne Auswahl wird das indiziert. */
    private const DEFAULT_FIELDS = ['title'];

    public function getKey(): string
    {
        return 'media_meta';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_type_media_meta');
    }

    public function getDescription(): string
    {
        return rex_i18n::msg('search_type_media_meta_description');
    }

    public function getType(Source $source): string
    {
        return 'meta';
    }

    public function getIndexIdPattern(Source $source): string
    {
        return '{media_id}-' . $this->getType($source);
    }

    protected function getOwnConfigFields(array $config): array
    {
        $fields = $this->getCategoryConfigFields($config);

        $fields[] = new ConfigField(
            'fields',
            rex_i18n::msg('search_media_meta_fields'),
            ConfigField::MULTISELECT,
            $this->getAvailableFields(),
            rex_i18n::msg('search_media_meta_fields_notice'),
            required: true,
            default: self::DEFAULT_FIELDS,
        );

        $fields[] = new ConfigField(
            'only_filled',
            rex_i18n::msg('search_media_meta_only_filled'),
            ConfigField::CHECKBOX,
            notice: rex_i18n::msg('search_media_meta_only_filled_notice'),
            default: true,
        );

        return $fields;
    }

    public function describe(Source $source): string
    {
        return $this->describeCategories($source) . ' – ' . implode(', ', $this->getSelectedFields($source));
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
        $media = $this->findMedia($itemId);
        if (null === $media || !$this->isInSelectedCategories($source, $media)) {
            return [];
        }

        $parts = [];
        foreach ($this->getSelectedFields($source) as $field) {
            $value = $this->readField($media, $field);
            if ('' !== $value) {
                $parts[$field] = $value;
            }
        }

        if ([] === $parts && $this->onlyFilled($source)) {
            return [];
        }

        $content = Text::join($parts);

        return [
            new IndexDocument(
                $itemId,
                $itemId . '-' . $this->getType($source),
                Text::truncate($this->buildTitle($media, $parts), 255),
                $content,
                $this->buildUrl($media),
                $this->readUpdatedAt($media),
                null,
                [
                    'filename' => $media->getFileName(),
                    'extension' => mb_strtolower($media->getExtension()),
                    'filesize' => (int) $media->getSize(),
                    'category_id' => $media->getCategoryId(),
                    'fields' => array_keys($parts),
                ],
                $content,
                IndexDocument::FORMAT_TEXT,
            ),
        ];
    }

    /**
     * Der Titel des Eintrags, sonst der Dateiname -- ein Treffer ohne Beschriftung ist
     * immer noch besser als ein Treffer ohne Ueberschrift.
     *
     * @param array<string, string> $parts
     */
    private function buildTitle(rex_media $media, array $parts): string
    {
        $title = trim((string) $media->getTitle());
        if ('' !== $title) {
            return $title;
        }

        return trim($parts['title'] ?? '') ?: $media->getFileName();
    }

    private function readField(rex_media $media, string $field): string
    {
        $value = match ($field) {
            'filename' => $media->getFileName(),
            'originalname' => $media->getOriginalFileName(),
            'title' => (string) $media->getTitle(),
            default => $media->getValue($field),
        };

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        // Metainfo-Felder duerfen Markup enthalten, etwa eine Beschreibung aus einem Editor.
        return trim(Text::fromHtml((string) $value));
    }

    /**
     * Die Grundspalten plus die aktuell definierten Medien-Metainfofelder (med_*).
     *
     * @return array<string, string>
     */
    private function getAvailableFields(): array
    {
        $fields = [
            'title' => rex_i18n::msg('search_media_meta_field_title') . ' (title)',
            'filename' => rex_i18n::msg('search_media_meta_field_filename') . ' (filename)',
            'originalname' => rex_i18n::msg('search_media_meta_field_originalname') . ' (originalname)',
        ];

        try {
            foreach (rex_sql::factory()->getArray('SELECT name, title FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE "med\_%" ORDER BY priority, name') as $row) {
                $name = (string) $row['name'];
                $label = self::translateFieldLabel((string) $row['title']);
                $fields[$name] = ('' !== $label ? $label . ' ' : '') . '(' . $name . ')';
            }
        } catch (Throwable) {
            // metainfo nicht installiert: dann bleibt es bei den Grundspalten.
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
            (array) $source->getConfig('fields', self::DEFAULT_FIELDS),
            static fn ($field) => is_string($field) && isset($available[$field]),
        ));

        return [] === $selected ? self::DEFAULT_FIELDS : $selected;
    }

    private function onlyFilled(Source $source): bool
    {
        return (bool) $source->getConfig('only_filled', true);
    }

    /**
     * Nur die Kategorienauswahl; Dateityp und Groesse spielen hier keine Rolle, denn
     * beschriftet ist ein Bild genauso wie ein PDF.
     *
     * Leere Eintraege werden hier noch nicht ausgeschlossen: ob ein Metainfo-Feld gefuellt
     * ist, steht je nach Feld in einer anderen Spalte, und die Pruefung in SQL nachzubauen
     * waere aufwendiger als der eine Durchlauf in {@see createDocuments()}.
     *
     * @return array{string, array<string, int|string>}
     */
    private function buildWhere(Source $source): array
    {
        $params = [];
        $category = $this->buildCategoryCondition($source, $params);

        return [null === $category ? '' : ' WHERE ' . $category, $params];
    }
}
