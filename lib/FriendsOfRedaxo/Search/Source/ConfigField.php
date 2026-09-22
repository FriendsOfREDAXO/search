<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use function is_array;
use function is_bool;

/**
 * Beschreibt ein Konfigurationsfeld einer Quelle. Das Backend rendert die Felder generisch,
 * der Quellentyp muss nichts vom Formular wissen.
 */
final class ConfigField
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const SELECT = 'select';
    public const MULTISELECT = 'multiselect';
    public const CHECKBOX = 'checkbox';

    /**
     * @param array<string|int, string> $options Wert => Beschriftung, nur fuer SELECT und MULTISELECT
     * @param bool $reloadOnChange Formular nach Aenderung neu laden, weil andere Felder von diesem abhaengen
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly array $options = [],
        public readonly string $notice = '',
        public readonly bool $required = false,
        public readonly bool $reloadOnChange = false,
        public readonly mixed $default = null,
    ) {}

    /**
     * Normalisiert einen Formularwert in den Typ, der in der Config gespeichert wird.
     */
    public function normalize(mixed $raw): mixed
    {
        switch ($this->type) {
            case self::CHECKBOX:
                return (bool) $raw;
            case self::MULTISELECT:
                $values = [];
                foreach ((array) $raw as $value) {
                    if (null === $value || is_array($value) || '' === (string) $value) {
                        continue;
                    }
                    $values[] = (string) $value;
                }
                return array_values(array_unique($values));
            default:
                if (is_array($raw) || null === $raw) {
                    return '';
                }
                return trim((string) $raw);
        }
    }

    public function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return [] === $value;
        }
        if (is_bool($value)) {
            return false;
        }

        return '' === trim((string) $value);
    }
}
