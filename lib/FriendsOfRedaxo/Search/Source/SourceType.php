<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use FriendsOfRedaxo\Search\Document\IndexDocument;

/**
 * Vertrag fuer einen Quellentyp.
 *
 * Ein Quellentyp weiss, wie aus einem Datenbestand Index-Dokumente entstehen. Eine konfigurierte
 * Instanz davon ist eine {@see Source}. Fremde AddOns liefern eigene Typen ueber den Extension
 * Point SEARCH_SOURCE_TYPES, siehe {@see SourceTypeRegistry}.
 *
 * Begriffe im Index:
 *  - namespace: {@see getNamespace()} des Typs, z. B. "yform"
 *  - type:      {@see getType()} der Quelle, z. B. der Tabellenname "person"
 *  - index_id:  vom Typ gebaut, Datensatz plus Variante, z. B. "17-content"
 */
abstract class SourceType
{
    use NormalizesConfig;

    /**
     * Stabiler Schluessel des Typs in der Registry, z. B. "article_meta".
     */
    abstract public function getKey(): string;

    /**
     * Namespace im Index. Standard ist der Key; mehrere Typen duerfen sich einen Namespace
     * teilen und sich dann ueber {@see getType()} unterscheiden, z. B. article/fullcontent
     * und article/meta.
     */
    public function getNamespace(): string
    {
        return $this->getKey();
    }

    abstract public function getLabel(): string;

    public function getDescription(): string
    {
        return '';
    }

    /**
     * Ob die Voraussetzungen erfuellt sind, etwa ein benoetigtes AddOn installiert ist.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Felder fuer das Quellen-Formular. Bekommt die bisherige Config, damit abhaengige Felder
     * (z. B. die Feldliste einer gewaehlten Tabelle) passend aufgebaut werden koennen.
     *
     * @param array<string, mixed> $config
     * @return list<ConfigField>
     */
    abstract public function getConfigFields(array $config): array;

    /**
     * Fachliche Pruefung der Config, Pflichtfelder prueft das Backend selbst.
     *
     * @param array<string, mixed> $config
     * @return array<string, string> Fehlermeldungen je Feldname
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * Unterart innerhalb des Typs, wird als type im Index gespeichert.
     */
    abstract public function getType(Source $source): string;

    /**
     * Kurzbeschreibung der Config fuer die Quellenliste.
     */
    public function describe(Source $source): string
    {
        return $this->getType($source);
    }

    /**
     * Muster der index_id, die diese Quelle erzeugt, fuer die Backend-Uebersicht.
     * Eine Quelle erzeugt genau ein Muster; wer mehrere Sichten auf denselben Datensatz
     * braucht (Inhalt und Metadaten), legt dafuer getrennte Quellentypen an.
     */
    abstract public function getIndexIdPattern(Source $source): string;

    /**
     * Anzahl der Datensaetze, null wenn nicht bekannt.
     */
    abstract public function countItems(Source $source): ?int;

    /**
     * Datensatz-IDs in stabiler Reihenfolge, seitenweise, damit der Aufbau in Schritten laufen kann.
     *
     * @return list<string>
     */
    abstract public function getItemIds(Source $source, int $offset, int $limit): array;

    /**
     * Dokumente zu einem Datensatz. Eine leere Liste bedeutet: Datensatz existiert nicht mehr
     * oder faellt aus dem Filter, seine Dokumente werden aus dem Index entfernt.
     *
     * @return iterable<IndexDocument>
     */
    abstract public function createDocuments(Source $source, string $itemId): iterable;
}
