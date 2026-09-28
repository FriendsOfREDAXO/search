<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Backend;

use FriendsOfRedaxo\Search\Source\ConfigField;
use rex_fragment;
use rex_i18n;
use rex_select;

use function array_key_exists;
use function count;

/**
 * Rendert {@see ConfigField}-Listen als Backend-Formular.
 *
 * Wird sowohl fuer die Konfiguration einer Quelle gebraucht als auch fuer die eines
 * Indexfilters, deshalb steht das hier statt in der Seite.
 */
final class ConfigFormRenderer
{
    /**
     * @param list<ConfigField> $fields
     * @param array<string, mixed> $values
     * @param string $namePrefix Praefix fuer name und id, etwa "config" oder "filter_config[ycom]"
     * @param array<string, string> $errors Fehlermeldungen je Feldname
     */
    public static function render(array $fields, array $values, string $namePrefix, array $errors = []): string
    {
        $elements = [];
        foreach ($fields as $field) {
            $elements[] = self::renderField($field, $values[$field->name] ?? $field->default, $namePrefix, $errors[$field->name] ?? '');
        }

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $elements, false);

        return $fragment->parse('core/form/form.php');
    }

    /**
     * @return array<string, mixed>
     */
    private static function renderField(ConfigField $field, mixed $value, string $namePrefix, string $error): array
    {
        $fieldId = 'search-' . preg_replace('/[^a-z0-9]+/i', '-', $namePrefix . '-' . $field->name);
        $inputName = $namePrefix . '[' . $field->name . ']';
        $reload = $field->reloadOnChange ? ' data-search-reload="1"' : '';

        $element = [
            'label' => '<label for="' . $fieldId . '">' . rex_escape($field->label) . '</label>',
            'note' => $field->notice,
            'required' => $field->required,
            'error' => $error,
        ];

        switch ($field->type) {
            case ConfigField::SELECT:
            case ConfigField::MULTISELECT:
                $select = new rex_select();
                $select->setName($inputName . (ConfigField::MULTISELECT === $field->type ? '[]' : ''));
                $select->setId($fieldId);
                $select->setAttribute('class', 'form-control');
                if ($field->reloadOnChange) {
                    $select->setAttribute('data-search-reload', '1');
                }
                if (ConfigField::MULTISELECT === $field->type) {
                    $select->setMultiple(true);
                    $select->setSize(min(10, max(3, count($field->options))));
                } elseif (!array_key_exists('', $field->options) && (!$field->required || '' === (string) $value)) {
                    $select->addOption($field->required ? rex_i18n::msg('search_source_please_choose') : '–', '');
                }
                foreach ($field->options as $optionValue => $optionLabel) {
                    $select->addOption($optionLabel, (string) $optionValue);
                }
                $select->setSelected(ConfigField::MULTISELECT === $field->type ? (array) $value : (string) $value);
                $element['field'] = $select->get();
                break;
            case ConfigField::TEXTAREA:
                $element['field'] = '<textarea class="form-control" id="' . $fieldId . '" name="' . $inputName . '" rows="3"' . $reload . '>' . rex_escape((string) $value) . '</textarea>';
                break;
            case ConfigField::CHECKBOX:
                $checkbox = new rex_fragment();
                $checkbox->setVar('elements', [[
                    'label' => '<label for="' . $fieldId . '">' . rex_escape($field->label) . '</label>',
                    'field' => '<input type="checkbox" id="' . $fieldId . '" name="' . $inputName . '" value="1"' . ($value ? ' checked' : '') . $reload . '>',
                    'note' => $field->notice,
                ]], false);
                $element = ['field' => $checkbox->parse('core/form/checkbox.php'), 'error' => $error];
                break;
            default:
                $element['field'] = '<input class="form-control" type="text" id="' . $fieldId . '" name="' . $inputName . '" value="' . rex_escape((string) $value) . '"' . $reload . '>';
        }

        return $element;
    }
}
