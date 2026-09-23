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
`url`, `clang_id`, `meta`, `updated_at` und `indexed_at`. Ein Datensatz kann mehrere Dokumente
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
