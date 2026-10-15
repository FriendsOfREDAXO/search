# search – Volltextindex mit erweiterbaren Quellen für REDAXO 5

Das AddOn baut einen Volltextindex aus beliebigen Datenbeständen auf. Woher die Inhalte kommen,
bestimmen **Quellentypen**; welche Bestände konkret indiziert werden, bestimmen **Quellen**, die
im Backend unter *Suche → Quellen* angelegt werden.

## Begriffe

| Begriff | Bedeutung | Beispiel |
|---|---|---|
| Quellentyp | PHP-Klasse, erbt von `FriendsOfRedaxo\Search\Source\SourceType` | `yform`, `article` |
| Quelle | konfigurierte Instanz eines Typs, Zeile in `rex_search_source` | YForm-Tabelle `rex_person` |
| Dokument | Eintrag in `rex_search_index` | `17-content` |

Im Index landen je Dokument `namespace` (Key des Typs), `type` (Unterart der Quelle, z. B. der
Tabellenname), `item_id` (Datensatz), `index_id` (Datensatz plus Variante), `title`, `content`,
`content_raw`, `content_raw_format`, `url`, `clang_id`, `meta`, `updated_at` und `indexed_at`. Ein Datensatz kann mehrere Dokumente
liefern, Artikel etwa `{id}-{clang}-fullcontent` und `{id}-{clang}-meta`.

## Mitgelieferte Quellentypen

Eine Quelle erzeugt genau **ein** Index-ID-Muster. Wer mehrere Sichten auf denselben Datensatz
braucht, legt mehrere Quellen an; die Quellenliste zeigt Namespace, Typ und Muster je Quelle.

- **YForm-Tabelle** (`yform` / Tabellenname, `{id}-content`): Tabelle, Inhaltsfelder, Titelfeld
  und URL-Muster konfigurierbar; alle Datensätze der Tabelle werden indiziert. Reagiert auf `YFORM_DATA_ADDED`, `_UPDATED`, `_DELETED`.
- **Artikel-Inhalt** (`article` / `fullcontent`, `{article_id}-{clang_id}-fullcontent`): Sprachen,
  alle oder ausgewählte Kategorien samt Unterkategorien, Offline-Artikel und Inhaltsquelle (Slice-Werte oder gerenderter Artikel).
- **Artikel-Metadaten** (`article` / `meta`, `{article_id}-{clang_id}-meta`): wie oben, dazu die
  Auswahl der Felder aus Artikelname und den aktuell definierten Metainfo-Feldern `art_*`.
- **Medienpool-Dokumente** (`media` / `document`, `{media_id}-document`): alle oder ausgewählte
  Medienkategorien samt Unterkategorien, Dateitypen, Größenobergrenze und ob Titel, Beschreibung
  und Copyright aus dem Medienpool mit in den Inhalt kommen. Was gelesen werden kann, entscheiden
  die Extraktoren, siehe unten.

Beide Artikel-Typen teilen sich den Namespace `article`. Ein Artikel unter einer offline
geschalteten Kategorie gilt als nicht erreichbar und fällt beim nächsten Aufbau aus dem Index. Ein Quellentyp kann `getNamespace()` überschreiben, um sich einen
Namespace mit anderen Typen zu teilen; unterschieden wird dann über `getType()`.

## Inhalt und Originalinhalt

Jedes Dokument wird zweifach abgelegt.

`content` ist Klartext: Skript- und Style-Blöcke entfernt, Block-Tags zu Zeilenumbrüchen,
restliche Tags weg, Entities aufgelöst, Leerraum normalisiert. Nur diese Spalte liegt
zusammen mit `title` im FULLTEXT-Index, die Suche arbeitet ausschließlich darauf.

`content_raw` ist derselbe Inhalt mit seiner Struktur, in aller Regel **Markdown**:
Überschriften, Listen und Tabellen bleiben erhalten. Die Spalte ist bewusst **nicht** Teil des
Volltextindex, sonst wären Tabellenstriche und Link-Ziele durchsuchbar. Sie ist für die
Weiterverarbeitung da, etwa zum Zerlegen an Überschriften für eine semantische Suche.

`content_raw_format` sagt, worum es sich handelt:

| Wert | wann | Beispiel |
|---|---|---|
| `markdown` | der Regelfall für alles mit Struktur | Artikel-Inhalt, Medienpool-Dokumente |
| `text` | Feldwerte ohne Markup | YForm-Felder, Artikel-Metadaten |
| `html` | nur als Rückfall, wenn das `vendor`-Verzeichnis fehlt | Artikel-Inhalt ohne Abhängigkeiten |

Die Regel dafür: Markdown überall, wo die Struktur etwas bedeutet. Feldwerte aus YForm oder
aus Metainfos sind Klartext; sie als Markup zu behandeln wäre teurer, denn aus `5 < 10` würde
der halbe Satz verschwinden.

Ein Artikel wird dabei nur **einmal** gerendert, eine Datei nur **einmal** gelesen; der
Klartext entsteht jeweils aus demselben Ergebnis. Der Inhalts-Hash deckt beide Fassungen ab,
eine Änderung nur an der Struktur wird also erkannt.

Nebenbei landet damit auch der Alt-Text von Bildern im Index, der beim blossen Entfernen der
Tags verloren ginge.

## Index aufbauen

Der Index wird **nicht** laufend fortgeschrieben. Änderungen an Artikeln oder YForm-Datensätzen
lösen keinen Neuaufbau aus; das hielte bei großen Beständen den Request auf. Stattdessen:

- Backend: *Suche → Quellen*, Aktion *Neu indizieren* je Quelle oder *Alle aktiven Quellen neu indizieren*
- Konsole: `bin/console search:index`, `--source=<id>` für eine Quelle, `--all` auch für inaktive
- Cronjob: Typ *Suche: Index neu aufbauen*, mit Auswahl der Quellen. Ohne Auswahl laufen alle
  aktiven Quellen, inaktive werden übersprungen. Setzt das AddOn `cronjob` voraus.

Ein Komplettaufbau entfernt am Ende alle Dokumente der Quelle, die der Lauf nicht angefasst hat.
So verschwinden gelöschte Datensätze ohne eigene Buchführung. Unveränderte Dokumente mit
gleichem Inhalts-Hash werden nicht neu geschrieben.

Wird die Konfiguration einer Quelle geändert, wird ihr Index gelöscht und sofort neu aufgebaut.

## Filter: was in den Index kommt

Ein Filter entscheidet beim Aufbau, ob ein Datensatz in den Index einer Quelle aufgenommen
wird. Damit lassen sich aus demselben Bestand **mehrere Indizes** bauen, etwa einer für
Gäste und einer für den Mitgliederbereich. Welche Quellen eine Suche später befragt,
entscheidet die Suche.

Gefiltert wird, bevor die Dokumente erzeugt werden. Ein abgelehnter Artikel wird also nicht
gerendert und fällt beim nächsten Aufbau aus dem Index, weil ihn der Lauf nicht anfasst.

Im Bearbeiten-Formular einer Quelle stehen die passenden Filter zur Auswahl. Ohne Auswahl
wird alles aufgenommen, bei mehreren müssen alle zustimmen. Ein Filter kann eigene
Einstellungen mitbringen; sie erscheinen als eigener Abschnitt, sobald er gewählt und das
Formular aktualisiert ist. Jede Änderung an Auswahl oder Einstellungen löscht den Index der
Quelle und baut ihn neu auf, wie jede andere Konfigurationsänderung.

Das AddOn bringt selbst keine Filter mit. Bereitgestellt werden sie über den Extension Point
`SEARCH_INDEX_FILTERS`:

```php
rex_extension::register('SEARCH_INDEX_FILTERS', static function (rex_extension_point $ep) {
    $filters = $ep->getSubject();
    $filters[] = new MyFilter();

    return $filters;
});
```

Die Klasse erbt von `IndexFilter` und implementiert `getKey()`, `getLabel()`, `appliesTo()`
und `accepts()`. `appliesTo()` bestimmt, für welche Quellen der Filter zur Auswahl steht.
`accepts()` bekommt die Quelle, die Datensatzkennung des Quellentyps und die Einstellungen
des Filters an dieser Quelle. Eigene Einstellungen liefert `getConfigFields()`, dieselbe
Feldbeschreibung wie bei einem Quellentyp.

Ein vollständiges Beispiel liegt im Projekt-AddOn unter `lib/Project/Search/`. Es schränkt
Artikelquellen auf die Inhalte ein, die für bestimmte YCom-Gruppen zugänglich sind:
angemeldet oder nicht, und wenn angemeldet, in welchen Gruppen. Geprüft wird über YCom
selbst, Vererbung über Kategorien und Gruppenrechte gelten damit ohne Zutun.

## Dokumente aus dem Medienpool

Der Quellentyp **Medienpool-Dokumente** liest Dateien und legt ihren Inhalt als Markdown ab.
Womit eine Datei gelesen wird, entscheidet ein Extraktor; der Quellentyp selbst kennt weder
PDF noch Office.

| Extraktor | Formate | Umsetzung |
|---|---|---|
| `text` | `txt` `md` `csv` `tsv` `json` `xml` | eigener Code; aus CSV wird eine Markdown-Tabelle |
| `html` | `html` `htm` `xhtml` | über `league/html-to-markdown` |
| `office` | `docx` `odt` `xlsx` `ods` `pptx` `odp` | eigener Code, nur `ZipArchive` und `DOMXPath` |
| `pdftotext` | `pdf` | Systemwerkzeug aus den Poppler-Utilities, Priorität 10 |
| `pdfparser` | `pdf` | `smalot/pdfparser`, Rückfall ohne das Systemwerkzeug |

**Zu PDF:** `pdftotext -layout` ist der bessere Weg. Gemessen an einem Haushaltsplan mit 386
Seiten braucht es 0,8 Sekunden gegenüber gut fünfzehn mit der PHP-Bibliothek, und nur dort
bleiben Spaltenpositionen erhalten — daraus lassen sich Tabellen zurückgewinnen. Fehlt das
Werkzeug oder ist `exec()` gesperrt, übernimmt die Bibliothek; dann gibt es Text, aber keine
Tabellen. Beides läuft ohne Zutun, der Extraktor mit der höheren Priorität gewinnt.

**Zu Tabellen aus PDF:** Die Spaltenerkennung ist eine Heuristik. Rechtsbündige Zahlenspalten
stehen unter einer Überschrift, die an anderer Stelle beginnt; eine Zuordnung lässt sich dann
nicht behaupten. In solchen Fällen bleibt die Kopfzeile leer und die Überschriftzeile wird als
gewöhnliche Datenzeile ausgegeben. Beschriftung und Werte einer Zeile bleiben immer beieinander.

**Zwischenspeicher:** Extrahiertes Markdown liegt unter `data/addons/search/documents` — bewusst
nicht unter `cache/`, denn ein Klick auf „Cache leeren" räumt den gesamten Cache-Baum ab und würde
Stunden an Extraktionsarbeit vernichten. Der
Schlüssel entsteht aus Dateiname, Größe und Änderungszeitpunkt des Medienpool-Eintrags, ohne
die Datei zu lesen. Ein Neuaufbau ohne geänderte Dateien fasst deshalb keine Datei mehr an —
gemessen 6,2 Sekunden beim ersten Lauf gegenüber 0,7 beim zweiten. Auch das Scheitern wird
gemerkt, sonst kostet eine kaputte Datei bei jedem Lauf erneut die volle Zeit. Das Verzeichnis
ist jederzeit löschbar.

**Grenzen:** Dateien über der eingestellten Größe werden übersprungen, der erzeugte Text wird
bei einer Million Zeichen an einer Zeilengrenze gekürzt und das Dokument als gekürzt vermerkt.

## Eigenen Extraktor bereitstellen

Über `SEARCH_DOCUMENT_EXTRACTORS` lässt sich ein Format ergänzen oder ein mitgelieferter
Extraktor ersetzen — wer eine höhere Priorität meldet und dieselbe Endung bedient, kommt
zuerst zum Zug. So kann ein Projekt für PDFs eine OCR-Anbindung oder einen eigenen Dienst
einhängen, ohne dass hier etwas geändert werden muss.

```php
rex_extension::register('SEARCH_DOCUMENT_EXTRACTORS', static function (rex_extension_point $ep) {
    $extractors = $ep->getSubject();
    $extractors[] = new MyOcrExtractor();

    return $extractors;
});
```

```php
final class MyOcrExtractor extends FriendsOfRedaxo\Search\Extract\DocumentExtractor
{
    public function getKey(): string { return 'ocr'; }
    public function getLabel(): string { return 'PDF über OCR'; }
    public function getExtensions(): array { return ['pdf']; }
    public function getPriority(): int { return 100; }
    public function isAvailable(): bool { return /* Dienst erreichbar? */ true; }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        $markdown = /* ... */ '';
        [$markdown, $truncated] = $context->truncate($markdown);

        return new ExtractedDocument($markdown, null, ['extractor' => $this->getKey()], $truncated);
    }
}
```

Liefert `extract()` null, gilt die Datei als nicht lesbar; das bricht den Indexlauf nicht ab.

## Abhängigkeiten

Das AddOn liefert zwei Bibliotheken im `vendor`-Verzeichnis mit:

| Paket | Lizenz | Größe | wofür |
|---|---|---|---|
| `league/html-to-markdown` | MIT | 208 KB | HTML nach Markdown, samt Tabellen |
| `smalot/pdfparser` | **LGPL-3.0** | 432 KB | PDF ohne Systemwerkzeug |

`smalot/pdfparser` steht unter LGPL-3.0 und liegt unverändert im Verzeichnis. Fehlt das
`vendor`-Verzeichnis, weil das AddOn ohne `composer install` ausgeliefert wurde, laufen
Volltext und Artikelquellen weiter; nur die Umwandlung nach Markdown und der PDF-Rückfall
stehen dann nicht zur Verfügung.

Office-Formate brauchen bewusst **keine** Bibliothek. Alle sind ZIP-Archive mit XML darin;
das reicht für eine Textextraktion vollständig aus.

## Eigenen Quellentyp bereitstellen

```php
// boot.php des eigenen AddOns
rex_extension::register('SEARCH_SOURCE_TYPES', static function (rex_extension_point $ep) {
    $types = $ep->getSubject();
    $types[] = new MyAddon\Search\EventSourceType();
    return $types;
});
```

Die Klasse erbt von `SourceType` und implementiert mindestens `getKey()`, `getLabel()`,
`getConfigFields()`, `getType()`, `getIndexIdPattern()`, `countItems()`, `getItemIds()` und `createDocuments()`.
`lib/FriendsOfRedaxo/Search/Source/Type/YFormTableSourceType.php` ist die Referenzimplementierung.

## Suchen

`FriendsOfRedaxo\Search\Index\IndexRepository::search($query, $namespaces, $types, $limit)` liefert
Treffer per `MATCH … AGAINST` im BOOLEAN MODE, optional eingeschränkt auf Namespaces, Typen oder
Quellen-IDs. Die Seite *Suche → Suche* bietet dieselbe Abfrage mit Quellenauswahl zum Ausprobieren.
