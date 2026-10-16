<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

use function in_array;

/**
 * Vertrag fuer die Extraktion einer Datei nach Markdown.
 *
 * Fremde AddOns melden eigene Extraktoren ueber den Extension Point
 * SEARCH_DOCUMENT_EXTRACTORS an, siehe {@see DocumentExtractorRegistry}. Ueber
 * {@see getPriority()} laesst sich ein mitgelieferter Extraktor ersetzen, ohne dass hier
 * etwas geaendert werden muss: wer eine hoehere Prioritaet meldet und dieselbe Endung
 * bedient, kommt zuerst zum Zug. So kann ein Projekt fuer PDFs eine OCR-Anbindung oder
 * einen eigenen Dienst einhaengen.
 */
abstract class DocumentExtractor
{
    /**
     * Stabiler Schluessel, z. B. "office". Landet in den Metadaten des Dokuments, damit
     * nachvollziehbar bleibt, womit ein Inhalt entstanden ist.
     */
    abstract public function getKey(): string;

    abstract public function getLabel(): string;

    /**
     * Dateiendungen in Kleinschreibung, ohne Punkt.
     *
     * @return list<string>
     */
    abstract public function getExtensions(): array;

    /**
     * Ob dieser Extraktor die Datei uebernehmen kann. Der Standard entscheidet ueber die
     * Endung; wer den Inhaltstyp auswerten will, ueberschreibt die Methode.
     */
    public function supports(string $extension, string $mimeType = ''): bool
    {
        return in_array(mb_strtolower($extension), $this->getExtensions(), true);
    }

    /**
     * Hoehere Werte gewinnen. Mitgelieferte Extraktoren liegen bei 0, der bevorzugte
     * PDF-Weg bei 10.
     */
    public function getPriority(): int
    {
        return 0;
    }

    /**
     * Der Stand dieses Extraktors. Wird er erhoeht, gelten alle zwischengespeicherten
     * Ergebnisse als veraltet und die Dateien werden neu gelesen.
     *
     * Noetig, weil der Zwischenspeicher sonst eine Verbesserung aussperrt: nach dem
     * Einbau der Silbentrennung lagen die alten, schlechteren Markdown-Fassungen
     * weiterhin vor und wurden unveraendert weitergereicht.
     */
    public function getVersion(): int
    {
        return 1;
    }

    /**
     * Ob die Voraussetzungen erfuellt sind, etwa eine Bibliothek oder ein Systembefehl.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Liefert den Inhalt als Markdown oder null, wenn die Datei nicht gelesen werden konnte.
     * Eine unlesbare Datei ist kein Fehlerfall, der den Indexlauf abbricht.
     */
    abstract public function extract(string $path, ExtractionContext $context): ?ExtractedDocument;
}
