<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Index;

use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\NormalizesConfig;
use FriendsOfRedaxo\Search\Source\Source;

/**
 * Ein Filter entscheidet beim Aufbau, ob ein Datensatz in den Index einer Quelle kommt.
 *
 * Damit lassen sich aus demselben Bestand mehrere Indizes bauen: eine Quelle mit dem Filter
 * fuer oeffentliche Artikel, eine zweite mit dem Filter fuer geschuetzte. Welche Quellen
 * eine Suche spaeter befragt, entscheidet die Suche.
 *
 * Gefiltert wird vor dem Erzeugen der Dokumente. Ein abgelehnter Datensatz wird nicht
 * gerendert und faellt beim naechsten Aufbau aus dem Index, weil ihn der Lauf nicht anfasst.
 *
 * Angemeldet werden Filter ueber den Extension Point SEARCH_INDEX_FILTERS,
 * siehe {@see IndexFilterRegistry}.
 */
abstract class IndexFilter
{
    use NormalizesConfig;

    /**
     * Stabiler Schluessel. Steht so in der Konfiguration der Quelle.
     */
    abstract public function getKey(): string;

    /**
     * Beschriftung fuer die Auswahl im Backend.
     */
    abstract public function getLabel(): string;

    public function getDescription(): string
    {
        return '';
    }

    /**
     * Fuer welche Quellen steht der Filter zur Auswahl? Beim Aufbau des Formulars ist die
     * Quelle noch nicht gespeichert, ihre ID darf also nicht abgefragt werden.
     */
    abstract public function appliesTo(Source $source): bool;

    /**
     * Eigene Einstellungen des Filters, gespeichert je Quelle. Standard: keine.
     *
     * @param array<string, mixed> $config
     * @return list<ConfigField>
     */
    public function getConfigFields(array $config): array
    {
        return [];
    }

    /**
     * Soll dieser Datensatz indiziert werden?
     *
     * $itemId ist die Datensatzkennung des Quellentyps, bei YForm die ID, bei Artikeln
     * "artikel-sprache". Mehrere Filter an einer Quelle muessen alle zustimmen.
     *
     * @param array<string, mixed> $config Einstellungen dieses Filters an dieser Quelle
     */
    abstract public function accepts(Source $source, string $itemId, array $config): bool;
}
