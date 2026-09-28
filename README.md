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

Beide Artikel-Typen teilen sich den Namespace `article`. Ein Artikel unter einer offline
geschalteten Kategorie gilt als nicht erreichbar und fällt beim nächsten Aufbau aus dem Index. Ein Quellentyp kann `getNamespace()` überschreiben, um sich einen
Namespace mit anderen Typen zu teilen; unterschieden wird dann über `getType()`.

## Inhalt und Originalinhalt

Jedes Dokument wird zweifach abgelegt.

`content` ist Klartext: Skript- und Style-Blöcke entfernt, Block-Tags zu Zeilenumbrüchen,
restliche Tags weg, Entities aufgelöst, Leerraum normalisiert. Nur diese Spalte liegt
zusammen mit `title` im FULLTEXT-Index, die Suche arbeitet ausschließlich darauf.

`content_raw` ist derselbe Inhalt vor der Umwandlung, also etwa das gerenderte HTML eines
Artikels samt Überschriften, Listen und Tabellen. Die Spalte ist bewusst **nicht** Teil des
Volltextindex, sonst wären Klassennamen und Link-Ziele durchsuchbar. Sie ist für die
Weiterverarbeitung da, etwa zum Zerlegen an Überschriften für eine semantische Suche.

`content_raw_format` sagt, worum es sich handelt: `html` oder `text`. Die Regel dafür: HTML
nur dort, wo Markup zur Bauart gehört, also beim Inhalt eines Artikels. Feldwerte aus YForm
oder aus Metainfos gelten als Klartext. Die umgekehrte Verwechslung wäre teurer, denn wer
Klartext als Markup behandelt, verliert aus `5 < 10` den halben Satz.

Ein Artikel wird dabei nur **einmal** gerendert; der Klartext entsteht aus demselben
Ergebnis. Der Inhalts-Hash deckt beide Fassungen ab, eine Änderung nur an der Struktur wird
also erkannt.

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
