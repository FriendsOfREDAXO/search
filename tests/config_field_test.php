<?php

use FriendsOfRedaxo\Search\Source\ConfigField;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_config_field_test extends TestCase
{
    public function testNormalizeText(): void
    {
        $field = new ConfigField('filter', 'Filter', ConfigField::TEXTAREA);

        self::assertSame('status = 1', $field->normalize('  status = 1 '));
        self::assertSame('', $field->normalize(null));
        self::assertSame('', $field->normalize(['unexpected']));
    }

    public function testNormalizeMultiselectDropsEmptyAndDuplicateValues(): void
    {
        $field = new ConfigField('fields', 'Felder', ConfigField::MULTISELECT);

        self::assertSame(['name', 'text'], $field->normalize(['name', '', 'text', 'name', null]));
        self::assertSame([], $field->normalize(null));
    }

    public function testNormalizeCheckbox(): void
    {
        $field = new ConfigField('offline', 'Offline', ConfigField::CHECKBOX);

        self::assertTrue($field->normalize('1'));
        self::assertFalse($field->normalize(null));
    }

    public function testIsEmpty(): void
    {
        $field = new ConfigField('x', 'x');

        self::assertTrue($field->isEmpty(''));
        self::assertTrue($field->isEmpty([]));
        self::assertFalse($field->isEmpty(false));
        self::assertFalse($field->isEmpty('a'));
    }
}
