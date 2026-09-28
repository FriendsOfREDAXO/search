<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceType;
use FriendsOfRedaxo\Search\Text;
use rex;
use rex_addon;
use rex_i18n;
use rex_sql;
use rex_yform_manager_table;
use Throwable;

use function is_scalar;
use function is_string;
use function strlen;

/**
 * Quellentyp fuer YForm-Tabellen: ein Datensatz ergibt ein Dokument "{id}-content".
 * Alle Datensaetze der Tabelle werden indiziert; eine Filtermoeglichkeit ist bewusst noch offen.
 */
final class YFormTableSourceType extends SourceType
{
    public function getKey(): string
    {
        return 'yform';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_type_yform');
    }

    public function getDescription(): string
    {
        return rex_i18n::msg('search_type_yform_description');
    }

    public function isAvailable(): bool
    {
        return rex_addon::exists('yform') && rex_addon::get('yform')->isAvailable() && class_exists(rex_yform_manager_table::class);
    }

    public function getConfigFields(array $config): array
    {
        $tables = [];
        foreach (rex_yform_manager_table::getAll() as $table) {
            $tables[$table->getTableName()] = rex_i18n::translate($table->getName(), false) . ' (' . $table->getTableName() . ')';
        }

        $fields = [
            new ConfigField('table', rex_i18n::msg('search_yform_table'), ConfigField::SELECT, $tables, required: true, reloadOnChange: true),
        ];

        $table = $this->getTable((string) ($config['table'] ?? ''));
        if (null !== $table) {
            $valueFields = [];
            foreach ($table->getValueFields() as $name => $field) {
                $label = trim(rex_i18n::translate((string) $field->getElement('label'), false));
                $valueFields[$name] = ('' !== $label ? $label . ' ' : '') . '(' . $name . ', ' . $field->getTypeName() . ')';
            }
            $fields[] = new ConfigField('fields', rex_i18n::msg('search_yform_fields'), ConfigField::MULTISELECT, $valueFields, rex_i18n::msg('search_yform_fields_notice'));
            $fields[] = new ConfigField('title_field', rex_i18n::msg('search_yform_title_field'), ConfigField::SELECT, ['' => '–'] + $valueFields, rex_i18n::msg('search_yform_title_field_notice'));
        }

        $fields[] = new ConfigField('url', rex_i18n::msg('search_yform_url'), ConfigField::TEXT, notice: rex_i18n::rawMsg('search_yform_url_notice'));

        return $fields;
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        $tableName = (string) ($config['table'] ?? '');
        if (null === $this->getTable($tableName)) {
            $errors['table'] = rex_i18n::msg('search_yform_table_missing', $tableName);
        }

        return $errors;
    }

    public function getType(Source $source): string
    {
        $tableName = (string) $source->getConfig('table', '');
        $prefix = rex::getTablePrefix();
        if ('' !== $prefix && str_starts_with($tableName, $prefix)) {
            return substr($tableName, strlen($prefix));
        }

        return $tableName;
    }

    public function describe(Source $source): string
    {
        $table = $this->getTable((string) $source->getConfig('table', ''));
        if (null === $table) {
            return (string) $source->getConfig('table', '');
        }

        return rex_i18n::translate($table->getName(), false) . ' (' . $table->getTableName() . ')';
    }

    public function getIndexIdPattern(Source $source): string
    {
        return '{id}-content';
    }

    public function countItems(Source $source): ?int
    {
        $table = $this->getTable((string) $source->getConfig('table', ''));
        if (null === $table) {
            return 0;
        }

        $rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . $table->getTableName());

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function getItemIds(Source $source, int $offset, int $limit): array
    {
        $table = $this->getTable((string) $source->getConfig('table', ''));
        if (null === $table) {
            return [];
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . $table->getTableName() . ' ORDER BY id LIMIT ' . max(0, $offset) . ', ' . max(1, $limit),
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    public function createDocuments(Source $source, string $itemId): iterable
    {
        $table = $this->getTable((string) $source->getConfig('table', ''));
        if (null === $table) {
            return [];
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $table->getTableName() . ' WHERE id = :id',
            ['id' => (int) $itemId],
        );
        if ([] === $rows) {
            return [];
        }
        $row = $rows[0];

        $parts = [];
        $rawParts = [];
        foreach ($this->getContentFieldNames($source, $table) as $fieldName) {
            $parts[$fieldName] = Text::fromHtml((string) ($row[$fieldName] ?? ''));
            $rawParts[$fieldName] = (string) ($row[$fieldName] ?? '');
        }

        $titleField = (string) $source->getConfig('title_field', '');
        $title = '' !== $titleField && isset($row[$titleField])
            ? Text::fromHtml((string) $row[$titleField])
            : (string) (array_values(array_filter($parts))[0] ?? '');
        if ('' === $title) {
            $title = '#' . $itemId;
        }

        return [
            new IndexDocument(
                $itemId,
                $itemId . '-content',
                Text::truncate($title, 255),
                Text::join($parts),
                $this->buildUrl((string) $source->getConfig('url', ''), $row),
                $this->readUpdatedAt($row),
                null,
                ['table' => $table->getTableName()],
                Text::join($rawParts),
                IndexDocument::FORMAT_TEXT,
            ),
        ];
    }

    private function getTable(string $tableName): ?rex_yform_manager_table
    {
        if ('' === $tableName) {
            return null;
        }

        try {
            return rex_yform_manager_table::get($tableName);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function getContentFieldNames(Source $source, rex_yform_manager_table $table): array
    {
        $valueFields = $table->getValueFields();

        $configured = array_values(array_filter(
            (array) $source->getConfig('fields', []),
            static fn ($name) => is_string($name) && isset($valueFields[$name]),
        ));
        if ([] !== $configured) {
            return $configured;
        }

        $names = [];
        foreach ($valueFields as $name => $field) {
            $dbType = (string) $field->getDatabaseFieldType();
            if (preg_match('/text|varchar|char/i', $dbType)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildUrl(string $pattern, array $row): string
    {
        if ('' === $pattern) {
            return '';
        }

        $replacements = [];
        foreach ($row as $column => $value) {
            if (is_scalar($value) || null === $value) {
                $replacements['{' . $column . '}'] = rawurlencode((string) $value);
            }
        }

        return strtr($pattern, $replacements);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readUpdatedAt(array $row): ?DateTimeImmutable
    {
        foreach (['updatedate', 'updated_at', 'createdate', 'created_at'] as $column) {
            $value = $row[$column] ?? null;
            if (is_string($value) && '' !== $value && !str_starts_with($value, '0000')) {
                try {
                    return new DateTimeImmutable($value);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
