<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source\Type;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Document\IndexDocument;
use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceType;
use FriendsOfRedaxo\Search\Text;
use rex;
use rex_addon;
use rex_clang;
use rex_i18n;
use rex_sql;
use rex_yform_manager_table;
use Throwable;

use function in_array;
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

    protected function getOwnConfigFields(array $config): array
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

        $mixed = self::findMixedLanguageFields($config);
        if (null !== $mixed) {
            $errors['fields'] = rex_i18n::msg('search_yform_fields_mixed', $mixed[0], $mixed[1]);
        }

        return $errors;
    }

    /**
     * Zwei gewaehlte Felder, die dasselbe in verschiedenen Sprachen enthalten.
     *
     * YForm-Tabellen fuehren Uebersetzungen ueblicherweise als Spalten nebeneinander:
     * "description", "description_en", "description_no". Alle drei in eine Quelle zu
     * nehmen ergibt ein Dokument mit drei Sprachen darin, und das ist der eine Fall, der
     * sich nachtraeglich nicht mehr aufloesen laesst -- welches Wort zu welcher Sprache
     * gehoert, steht danach nirgends mehr. Stammformen und Zerlegung waeren fuer zwei
     * Drittel des Textes falsch.
     *
     * Deshalb hier eine Absage statt eines Hinweises. Der Weg ist eine Quelle je Sprache
     * auf dieselbe Tabelle, jede mit ihren Feldern und ihrer Sprachangabe.
     *
     * Erkannt wird an der Endung, nicht am Inhalt: zwei Felder, die sich nur um ein
     * angehaengtes Sprachkuerzel unterscheiden. Beruecksichtigt werden die Sprachen des
     * AddOns und die Sprachcodes der Installation, damit auch eine Eigenheit wie "_at"
     * auffaellt.
     *
     * @param array<string, mixed> $config
     * @param list<string>|null $clangCodes Nur fuer Tests: sonst die Codes der Installation
     * @return array{string, string}|null
     */
    public static function findMixedLanguageFields(array $config, ?array $clangCodes = null): ?array
    {
        $fields = [];
        foreach ((array) ($config['fields'] ?? []) as $field) {
            if (is_string($field) && '' !== $field) {
                $fields[] = $field;
            }
        }
        $titleField = (string) ($config['title_field'] ?? '');
        if ('' !== $titleField && !in_array($titleField, $fields, true)) {
            $fields[] = $titleField;
        }

        $suffixes = self::languageSuffixes($clangCodes);
        $bases = [];
        foreach ($fields as $field) {
            $base = $field;
            foreach ($suffixes as $suffix) {
                if (str_ends_with($field, $suffix) && strlen($field) > strlen($suffix)) {
                    $base = substr($field, 0, -strlen($suffix));
                    break;
                }
            }
            if (isset($bases[$base])) {
                return [$bases[$base], $field];
            }
            $bases[$base] = $field;
        }

        return null;
    }

    /**
     * Endungen, die ein Feld als Sprachfassung ausweisen, laengste zuerst.
     *
     * Die Reihenfolge ist noetig: bei "_de_de" wuerde sonst "_de" greifen und als Grundname
     * "name_de" uebrigbleiben, womit "name_de_de" und "name_en_en" verschieden waeren.
     *
     * @param list<string>|null $clangCodes
     * @return list<string>
     */
    private static function languageSuffixes(?array $clangCodes = null): array
    {
        $codes = Language::codes();
        foreach ($clangCodes ?? self::clangCodes() as $code) {
            $codes[] = $code;
        }

        $suffixes = [];
        foreach (array_unique($codes) as $code) {
            $suffixes[] = '_' . $code;
        }
        usort($suffixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $suffixes;
    }

    /**
     * Die Sprachcodes der Installation, kleingeschrieben.
     *
     * @return list<string>
     */
    private static function clangCodes(): array
    {
        $codes = [];
        foreach (rex_clang::getAll() as $clang) {
            $code = strtolower(trim($clang->getCode()));
            if ('' !== $code && preg_match('/^[a-z0-9_-]{2,5}$/', $code)) {
                $codes[] = $code;
            }
        }

        return $codes;
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
