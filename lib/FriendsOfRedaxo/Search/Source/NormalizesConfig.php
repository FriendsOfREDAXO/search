<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use function array_key_exists;

/**
 * Wandelt rohe Formularwerte in die Werte, die gespeichert werden.
 *
 * Genutzt von {@see SourceType} und von FriendsOfRedaxo\Search\Index\IndexFilter, damit
 * beide dieselben Feldtypen und dieselbe Checkbox-Semantik haben.
 */
trait NormalizesConfig
{
    /**
     * @param array<string, mixed> $config
     * @return list<ConfigField>
     */
    abstract public function getConfigFields(array $config): array;

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $raw): array
    {
        // Eine nicht angehakte Checkbox fehlt im Post; sie ist dann false, nicht ihr Default.
        $valueOf = static fn (ConfigField $field): mixed => ConfigField::CHECKBOX === $field->type
            ? ($raw[$field->name] ?? false)
            : ($raw[$field->name] ?? $field->default);

        $config = [];
        foreach ($this->getConfigFields($raw) as $field) {
            $config[$field->name] = $field->normalize($valueOf($field));
        }
        // Zweiter Durchlauf: Felder, die erst durch normalisierte Werte sichtbar werden.
        foreach ($this->getConfigFields($config) as $field) {
            if (!array_key_exists($field->name, $config)) {
                $config[$field->name] = $field->normalize($valueOf($field));
            }
        }

        return $config;
    }
}
