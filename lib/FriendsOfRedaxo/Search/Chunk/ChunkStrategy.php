<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk;

use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\NormalizesConfig;

/**
 * Vertrag fuer ein Zerlegungsverfahren.
 *
 * Wie ein Dokument in Abschnitte faellt, haengt davon ab, was fuer ein Dokument es ist:
 * ein YForm-Datensatz mit vierzig Zeichen ist ein Abschnitt, ein Haushaltsplan mit einer
 * halben Million Zeichen sind vierhundert. Das kann keine Vorgabe entscheiden, also
 * entscheidet es die Quelle -- hier steht nur, woran sich ein Verfahren halten muss.
 *
 * Fremde AddOns liefern eigene Verfahren ueber den Extension Point
 * SEARCH_CHUNK_STRATEGIES, siehe {@see ChunkStrategyRegistry}.
 */
abstract class ChunkStrategy
{
    use NormalizesConfig;

    /*
     * Eine Mindestlaenge gibt es bewusst nicht. Sie lag einmal bei vierzig Zeichen, und
     * die Messung hat sie widerlegt: die Frage "Kaninchen" findet den Artikel "Hasen"
     * (fuenf Zeichen) als besten Treffer, "Speisekarte" den Eintrag "Die Speisekarte"
     * (fuenfzehn Zeichen) mit einer Aehnlichkeit von 0,820. Eine Untergrenze haette
     * genau diese Antworten weggeworfen.
     */

    /**
     * Obergrenze, die kein Verfahren ueberschreiten darf.
     *
     * Ein Einbettungsmodell nimmt nur eine begrenzte Zahl Token an -- bei
     * text-embedding-3-small sind es 8.191, und deutscher Text braucht grob drei bis
     * vier Zeichen je Token. Ein laengerer Abschnitt wird nicht etwa gekuerzt
     * eingebettet, sondern abgewiesen: der Aufruf scheitert, der Abschnitt bleibt ohne
     * Vektor, und nach drei Versuchen gibt das AddOn ihn auf.
     *
     * Das betrifft nicht die eingestellte Abschnittsgroesse -- die ist ohnehin bei 8.000
     * gedeckelt --, sondern zwei andere Faelle: "Ganzes Dokument" auf einer Quelle, in
     * der ein langes Dokument steckt (am Bestand gemessen 536.782 Zeichen in einem
     * einzigen PDF), und ein Verfahren, das ein fremdes AddOn ueber den Extension Point
     * mitbringt. Durchgesetzt wird sie deshalb beim Aufbau und nicht im Verfahren, siehe
     * {@see ChunkBuilder}.
     */
    public const MAX_CHUNK_CHARACTERS = 16000;

    /**
     * Stabiler Schluessel, z. B. "markdown".
     */
    abstract public function getKey(): string;

    /**
     * Beschriftung und Beschreibung kommen unmaskiert heraus -- maskiert wird an der
     * Ausgabestelle, und zwar einmal. rex_i18n::msg() maskiert selbst; mit ihm stand in
     * der Anleitung &quot;Feste Groesse&quot; statt der Anfuehrungszeichen.
     */
    abstract public function getLabel(): string;

    public function getDescription(): string
    {
        return '';
    }

    /**
     * Einstellungen des Verfahrens, erscheinen im Quellenformular unter der Auswahl.
     *
     * @param array<string, mixed> $config
     * @return list<ConfigField>
     */
    public function getConfigFields(array $config): array
    {
        return [];
    }

    /**
     * Zerlegt ein Dokument. Eine leere Liste heisst: hier gibt es nichts einzubetten.
     *
     * @param array<string, mixed> $config
     * @return list<Chunk>
     */
    abstract public function split(ChunkInput $input, array $config): array;
}
