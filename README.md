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
`content_raw`, `content_raw_format`, `url`, `clang_id`, `language`, `meta`, `updated_at` und `indexed_at`. Ein Datensatz kann mehrere Dokumente
liefern, Artikel etwa `{id}-{clang}-fullcontent` und `{id}-{clang}-meta`.

## Mitgelieferte Quellentypen

Eine Quelle erzeugt genau **ein** Index-ID-Muster. Wer mehrere Sichten auf denselben Datensatz
braucht, legt mehrere Quellen an; die Quellenliste zeigt Namespace, Typ und Muster je Quelle.

- **YForm-Tabelle** (`yform` / Tabellenname, `{id}-content`): Tabelle, Inhaltsfelder, Titelfeld
  und URL-Muster konfigurierbar; alle Datensätze der Tabelle werden indiziert. Reagiert auf `YFORM_DATA_ADDED`, `_UPDATED`, `_DELETED`.
- **Artikel-Inhalt** (`article` / `fullcontent`, `{article_id}-{clang_id}-fullcontent`): Sprachen,
  alle oder ausgewählte Kategorien samt Unterkategorien, Offline-Artikel und Inhaltsquelle (Slice-Werte, gerenderter Artikel oder Seite aus dem Frontend, siehe unten).
- **Artikel-Metadaten** (`article` / `meta`, `{article_id}-{clang_id}-meta`): wie oben, dazu die
  Auswahl der Felder aus Artikelname und den aktuell definierten Metainfo-Feldern `art_*`.
- **Medienpool-Dokumente** (`media` / `document`, `{media_id}-document`): alle oder ausgewählte
  Medienkategorien samt Unterkategorien, Dateitypen, Größenobergrenze und ob Titel, Beschreibung
  und Copyright aus dem Medienpool mit in den Inhalt kommen. Was gelesen werden kann, entscheiden
  die Extraktoren, siehe unten.

Beide Artikel-Typen teilen sich den Namespace `article`. Ein Artikel unter einer offline
geschalteten Kategorie gilt als nicht erreichbar und fällt beim nächsten Aufbau aus dem Index. Ein Quellentyp kann `getNamespace()` überschreiben, um sich einen
Namespace mit anderen Typen zu teilen; unterschieden wird dann über `getType()`.

### Woher der Artikel-Inhalt kommt

Die Inhaltsquelle des Typs *Artikel-Inhalt* hat drei Stufen:

- **Slice-Werte**: die gespeicherten Werte der Slices, ohne Modul-Ausgabe. Schnell, aber was
  ein Modul erst bei der Ausgabe zusammenstellt – Datensätze, Listen, Texte aus Sprog –, fehlt.
- **Gerenderter Artikel**: `rex_article_content::getArticle()` im laufenden Prozess, also im
  Backend oder auf der Konsole. Wirft ein Modul dabei einen Fehler, fällt der Artikel auf die
  Slice-Werte zurück.
- **Seite aus dem Frontend**: der Artikel wird per HTTP abgerufen, so wie ein Besucher ihn
  bekommt. Das ist der Weg für Module, die nur im Frontend funktionieren – die
  `rex::isFrontend()` abfragen, auf yrewrite-Domains bauen oder Daten erst im Template
  bereitstellen.

Beim Abruf aus dem Frontend hängt das AddOn `search_index=1` an die URL. Dann setzt das
Frontend Markierungen um jede Ausgabe von `getArticle()` für den aufgerufenen Artikel, und nur
was dazwischen steht, kommt in den Index. Navigation, Kopf und Fuß bleiben draußen, ebenso
Inhalte anderer Artikel, die ein Template einbindet. Die Markierung nennt Artikel und Sprache:
liefert das Frontend statt des Artikels eine andere Seite aus, etwa die Fehlerseite, wird sie
nicht unter dem falschen Artikel abgelegt.

Die Adresse kommt von yrewrite, ohne yrewrite aus der Server-URL in den Systemeinstellungen
und `index.php?article_id=…&clang=…`.

Was dabei schiefgehen kann:

- Ein einzelner Artikel, der weiterleitet, fehlt, einen Fehler liefert oder keine Markierung
  enthält, fällt auf die Slice-Werte zurück. Das betrifft auch Offline-Artikel, sofern die
  Quelle sie einschließt: das Frontend zeigt sie Besuchern nicht.
- Ist das Frontend gar nicht erreichbar oder verlangt eine Anmeldung (401, 403, 407), bricht
  der Lauf mit einer Meldung ab, und der Index bleibt, wie er war. Sonst fiele jeder Artikel
  stillschweigend auf die Slice-Werte zurück.
- Ein Frontend hinter Basic Auth, etwa auf einem Testserver, braucht Benutzer und Passwort in
  der Quelle. Beides wird unverschlüsselt in der Konfiguration der Quelle gespeichert.
- Für eine Entwicklungsumgebung mit selbst signiertem Zertifikat lässt sich die Prüfung des
  SSL-Zertifikats abschalten.

Ein HTTP-Abruf je Artikel und Sprache dauert spürbar länger als das Rendern im Prozess. Wird
die Konfiguration einer Quelle geändert, baut das Backend ihren Index sofort neu auf, und das
läuft in der Browseranfrage und deren Zeitlimit. Bei vielen Artikeln den Index deshalb über
`bin/console search:index --source=<id>` oder den Cronjob aufbauen.

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

- Backend: *Suche → Quellen*, Aktion *Neu indizieren* – **je Quelle**. Einen Knopf für alle
  Quellen gibt es bewusst nicht: er lief in einer Browseranfrage und damit in deren Zeitlimit,
  bei Dokumentquellen also jedes PDF noch einmal durch `pdftotext`. Was dabei abbricht,
  hinterlässt einen halb aufgebauten Index ohne Meldung.
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

```php
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Query\Searcher;

$result = (new Searcher())->search(
    SearchQuery::create($_GET['q'] ?? '')
        ->withNamespaces('article')
        ->withLanguages('de')
        ->withPage(1, 10),
);

foreach ($result as $hit) {
    echo '<h3><a href="' . rex_escape($hit->url) . '">' . $hit->getHighlightedTitle() . '</a></h3>';
    echo '<p>' . $hit->getHighlightedSnippet() . '</p>';
}
```

Für den einfachsten Fall genügt `(new Searcher())->searchFor('burg')`.

### Was die Suche festlegt

Es gibt **keine Abfragesprache**. Der Eingabetext ist eine Folge von Wörtern, kein UND, kein ODER,
keine Klammern, keine Phrasen. Operatorzeichen werden entfernt, statt gedeutet zu werden – ein
versehentliches Minus zerstört also nicht die eigene Suche. Die Regeln im Einzelnen:

| Regel | Wirkung |
|---|---|
| Jedes Wort muss vorkommen | mehr Wörter, weniger Treffer |
| Exakt oder als Wortanfang | `Burg` findet auch `Burgmauer`, die exakte Form steht oben |
| Zu kurze Wörter fallen weg | unterhalb von `innodb_ft_min_token_size` steht nichts im Index |
| Stoppwörter fallen weg | siehe unten |
| Höchstens 12 Wörter | mehr bringt keine besseren Treffer |

Weggelassene Wörter verschwinden nicht stillschweigend: `$result->getIgnoredWords()` gibt sie
samt Grund zurück, damit die Oberfläche sie benennen kann.

> **Zu den Stoppwörtern.** InnoDB bringt eine englische Liste mit, auf der ausgerechnet `und`
> steht – neben `de`, `en`, `la`, `com` und `www`. Würde jedes Wort gefordert, lieferte die
> Eingabe „Espresso und Boule" auf einer deutschen Seite null Treffer, weil `und` im Index nicht
> existiert. Welche Wörter betroffen sind, fragt das AddOn den Server
> (`INNODB_FT_DEFAULT_STOPWORD`), statt eine eigene Liste zu raten.

### Reihenfolge der Treffer

Die Bewertung von InnoDB ist roh die Trefferzahl mal einem Wortgewicht, **ohne jede Begrenzung
und ohne Rücksicht auf die Länge**. Ein 386-seitiges PDF mit 453 Nennungen bekommt dadurch das
Hundertfache eines Artikels, der dasselbe Thema in drei Sätzen auf den Punkt bringt. Die Suche
rechnet deshalb um:

1. `LN(1 + score)` sättigt die Trefferhäufigkeit.
2. Wörter, die **nahe beieinander** stehen, bekommen einen Zuschlag. „Burg Eppstein" in
   einem Satz ist stärker als dieselben Wörter irgendwo im selben Dokument – InnoDB kann
   das selbst, über eine Wortgruppe mit Abstandsangabe (`"burg eppstein" @20`). Faktor und
   Abstand über `withProximityBoost()`, Vorgabe `0.8` und 20 Wörter, `0.0` schaltet ab.
3. Die **exakte Wortform** bekommt einen Zuschlag. Gesucht wird mit `+burg*`, damit
   Zusammensetzungen gefunden werden – ohne diesen Zuschlag läge eine Seite über das
   *Burgfest* gleichauf mit einer über die *Burg*. Faktor über `withExactBoost()`,
   Vorgabe `0.5`, `0.0` schaltet ab.
4. Ein Treffer im **Titel** zählt zusätzlich, dafür gibt es einen zweiten Volltextindex über
   `title` allein. Faktor über `withTitleBoost()`, Vorgabe `1.5`, `0.0` schaltet ab.
5. Die **Länge** dämpft, aber erst oberhalb einer Schwelle und dann logarithmisch. Schwelle
   über `withLengthScale()`, Vorgabe `5000` Zeichen, `0` schaltet ab.
6. Optional ein Faktor je Quelle: `withSourceWeights([19 => 1.0, 20 => 0.5])`.

> **Warum ein Zuschlag und kein Abschlag.** Die naheliegende Fassung wäre `+(>burg <burg*)`:
> exakt höher, Wortanfang niedriger. Die Modifikatoren ändern aber den Beitrag zur Bewertung,
> und `<` senkt ihn – ein Artikel, der neben „Burg" auch „Burgmauer" und „Burgfest" enthält,
> wird für genau diesen Reichtum bestraft. Am echten Bestand gemessen kam dabei ein
> **negativer** Wert heraus, und ein Artikel mit fünf Vorkommen landete unter einem
> Meta-Eintrag von siebzehn Zeichen mit einem.

> **Warum die Schwelle bei der Längendämpfung.** Eine Dämpfung, die schon zwischen 24 und
> 984 Zeichen unterscheidet, stellt den Meta-Eintrag eines Artikels vor dessen Volltext:
> beide haben dieselbe Bewertung, der kürzere wird weniger geteilt. Der Textausschnitt im
> Treffer wäre dann nur der Titel. Bei gleicher Bewertung gewinnt deshalb zusätzlich das
> inhaltsreichere Dokument.

### Relevanz und Schwelle

`$hit->score` ist die rohe Bewertung: unbegrenzt, und abhängig vom Wortschatz des
Bestands – `4.50` bedeutet auf jeder Installation etwas anderes. Daneben steht
`$hit->relevance`, der **Anteil am besten Treffer dieser Suche**, zwischen 0 und 1. Erst
der lässt sich vergleichen und als Schwelle verwenden:

```php
$result = $searcher->search(SearchQuery::create($begriff)->withMinRelevance(0.3));
```

Alles unter 30 Prozent des besten Treffers fällt dann heraus – nützlich, sobald
Erweiterung und Korrektur mitlaufen und die Liste nach unten ausfranst. Gefiltert wird vor
dem Blättern, `total` stimmt also.

### Facetten

```php
$result = $searcher->search(
    SearchQuery::create($begriff)->withFacets('namespace', 'type', 'clang_id'),
);

$result->getFacet('namespace');   // ['article' => 11, 'media' => 3]
```

Gezählt wird über **alle** Treffer der Suche, nicht über die angezeigte Seite – eine
Verteilung, die sich beim Blättern ändert, wäre unbrauchbar. Es kostet nichts extra: die
Kandidatenliste liegt für die Dublettenprüfung ohnehin vollständig vor. Erlaubt sind
`namespace`, `type`, `source_id` und `clang_id`; andere Werte werden verworfen.

### Dubletten

Ein Artikel liegt im Index einmal je Quelle, die ihn erfasst – Volltext, Meta-Name,
Meta-Beschreibung. In einer Trefferliste wäre das dreimal dasselbe. Die Suche fasst Einträge mit
gleichem `namespace` und `item_id` zusammen und behält den bestbewerteten. Die Sprache steckt
bereits in der `item_id`, zwei Sprachfassungen bleiben also getrennte Treffer. Abschaltbar über
`withGrouping(false)`.

### Teiltreffer und Tippfehler

Das AddOn führt neben dem Index eine **Wortliste** (`rex_search_word`): jedes Wort des
Index einmal je Quelle und Sprache, dazu seine Dokumentzahl. Gemessen an einem Index aus
600 Dokumenten sind das 13.000 Einträge und 2,4 MB, also rund 190 Byte je Eintrag. Sie
beantwortet zwei Fragen, die der Volltextindex nicht beantworten kann.

**Was in der Wortmitte steht.** `+burg*` findet „Burgmauer", aber nicht „Ganerbenburg".
Die Wortliste schon: ein `LIKE '%burg%'` über 55.000 Wörter kostet gemessen 6 ms. Die
gefundenen Formen werden als ODER-Gruppe mitgesucht, `+(ruine* burgruine)`.

**Vertipptes.** `levenshtein()` über die ganze Wortliste kostet gemessen 8 ms. Verglichen
wird die gefaltete Form – `levenshtein()` zählt Bytes, ein Tippfehler auf einem Umlaut
würde sonst doppelt zählen und durchfallen.

Beides läuft **gestaffelt**, und das ist die wichtigste Regel:

| Anlauf | Wann |
|---|---|
| wie eingegeben | immer |
| erweitert | wenn die erste Seite nicht voll wurde |
| korrigiert | wenn gar nichts kam |

Ohne diese Staffelung wird die Suche schlechter statt besser. Am echten Bestand gemessen:
`burg` findet 22 Artikel; eine Erweiterung hängt „Einbürgerung", „Hamburg" und „Schomburg"
daran, woraufhin ein Artikel über Ganerbenburgen vor den über die Burg Eppstein rutscht.
Und `burg` mit Abstand 2 „korrigiert" zu `für`, `nur`, `zur` – bei vier Zeichen trifft
Abstand 2 fast alles. Deshalb hängt der erlaubte Abstand an der Wortlänge: bis 4 Zeichen
gar keine Korrektur, bis 7 Abstand 1, darüber 2.

Was passiert ist, steht im Ergebnis: `$result->getExpansions()` und
`$result->corrections`. Abschalten über `withExpansion(false)` und `withCorrection(false)`.

### Die Wortliste bauen

Sie entsteht **beim Indexlauf**, aus dem Text, der ohnehin durch die Hand geht – gemessen
rund 2 % Aufschlag. Geschrieben wird am Ende des Laufs, in einem Zug: nur ein vollständig
durchgelaufener Lauf kennt den Wortschatz einer Quelle ganz und darf den alten ersetzen.
Verliert eine Quelle ein Wort, verschwindet es damit; kennt eine andere Quelle es auch,
bleibt es stehen, denn jede Quelle hat ihre eigenen Zeilen.

Dazu gibt es `bin/console search:words`. Der Befehl liest nur `title` und `content` aus
dem Index und braucht dafür gemessen rund 2,5 Sekunden. Gedacht für den Fall nach einem
Update – Index voll, Wortliste leer –, wo ein vollständiger Neuaufbau jedes PDF neu
extrahieren und jeden Artikel neu rendern müsste.

`Indexer::reindexItem()` – der Einzelaufruf für fremden Code – rührt die Wortliste **nicht**
an: ein einzelner Datensatz verrät nicht, ob ein Wort aus der ganzen Quelle verschwunden
ist. Schaden kann das nicht, denn ein veraltetes Wort wird nie angezeigt: eine Erweiterung
zählt nur, wenn sie mehr Treffer bringt, und eine Korrektur nur, wenn sie überhaupt welche
bringt. Der nächste vollständige Lauf räumt auf.

Je Quelle lässt sich das abschalten: **Wörter für Vorschläge sammeln**. Bei Dokumentquellen
ist das sinnvoll, denn gescannte PDFs bringen Zeichenfolgen mit, die keine Wörter sind –
im Testbestand steuerten acht PDFs 5.393 Wörter bei, darunter `brrg` und `bzrg`, und eine
Tippfehlerkorrektur hätte `burg` bereitwillig dorthin verbessert.

> **Zu den Sprachen.** Jedes Wort trägt die Sprache seiner Quelle, nicht deren `clang_id`.
> Mehrere REDAXO-Sprachen derselben Sprache teilen sich damit einen Wortschatz, statt ihn
> grundlos zu zerlegen, und Medien- und YForm-Quellen bekommen überhaupt eine. Wörter aus
> Quellen ohne Sprachangabe zählen immer mit, sonst fielen sie aus jeder sprachbezogenen
> Suche. Gespeichert wird das Wort so, wie es im Text steht; `word` ist deshalb
> `varbinary`, denn unter `utf8mb4_general_ci` gelten „gross" und „groß" als dasselbe und
> ein eindeutiger Schlüssel würde eines davon verschlucken.

### Suchbegriffe von außen umschreiben

```php
rex_extension::register('SEARCH_QUERY_TERMS', function (rex_extension_point $ep) {
    /** @var FriendsOfRedaxo\Search\Query\SearchTerms $terms */
    $terms = $ep->getSubject();
    // ... eigenen Ausdruck bauen, etwa mit Wortstämmen oder Synonymen
    return $terms;
});
```

Hier hängen Synonyme und eigene Umschreibungen ein; Stammformen und Wortzerlegung bringt
das AddOn selbst mit, siehe unten.

### Backend

Die Seite *Suche → Suche* nutzt dieselbe API. Sie kennt drei Sucharten – Volltext, Volltext
und Bedeutung, nur Bedeutung –, dazu Quellen- und Sprachauswahl, Hervorhebung, Facetten und
Blättern. Jeder Treffer lässt sich aufklappen und zeigt dann alles, was die API zu ihm
liefert.

## Sprache

Jede Quelle gibt an, in welcher Sprache ihre Inhalte stehen. Das Feld ist ein **Pflichtfeld**;
wer keine angeben will oder kann, wählt ausdrücklich *keine Angabe*, übersehen lässt es sich
nicht.

**Hergeleitet wird sie nicht**, und das ist eine bewusste Entscheidung. Der Sprachcode einer
REDAXO-Sprache ist ein freies Textfeld (`maxlength="255"`, keine Prüfung) – dass dort `de`
steht, ist Konvention und keine Zusage. Medien- und YForm-Quellen haben überhaupt keinen clang:
`clang_id = 0` heißt „unbekannt", nicht „deutsch". Und raten wäre teuer: am Beispielbestand
gemessen landet rund die **Hälfte aller Wörter** je nach angenommener Sprache auf einem anderen
Stamm – `Hasen` wird deutsch zu `has`, englisch zu `hasen`. Mit der falschen Sprache ist die
Analyse nicht schwächer, sondern falsch.

Die Sprache steht danach in jeder Zeile von `rex_search_index`, `rex_search_word` und
`rex_search_word_form` und lässt sich in der Suche filtern:

```php
SearchQuery::create($q)->withLanguages('de');
```

*Eine Sprache je Quelle* heißt **nicht** *ein clang je Quelle*: mehrere clangs können dieselbe
Sprache sein – `de` und `de-CH`, oder ein eigener clang für Leichte Sprache. Die
Mehrfachauswahl der clangs bleibt deshalb; eingeschränkt wird die Sprache, nicht die Struktur.
Wer Deutsch und Englisch hat, legt zwei Quellen an.

Was sich dagegen **nicht** auflösen lässt, ist ein Dokument mit mehreren Sprachen darin. Die
YForm-Quelle weist eine Feldauswahl deshalb ab, die nach zwei Sprachfassungen desselben Feldes
aussieht – `description` und `description_en` nebeneinander. Erkannt wird an der Endung, gegen
die Sprachen des AddOns und die Sprachcodes der Installation.

### Stammformen und zusammengesetzte Wörter

Neben der Wortliste steht eine zweite Tabelle, `rex_search_word_form`: zu jedem Wort sein
**Stamm** und der Stamm jedes **Teils**, in den es sich zerlegen lässt.

| Wort | Formen |
|---|---|
| `Burgruine` | `burgruin`, `burg`, `ruin` |
| `Ruinen` | `ruin` |
| `Abfallsatzung` | `abfallsatz`, `abfall`, `satzung` |
| `Hamburg` | `hamburg` |

Dass Beugung und Zusammensetzung auf **derselben** Form landen, ist der Kern: sonst fände
„Ruinen" die „Burgruine" nicht, weil die Zerlegung `ruine` liefert und nicht `ruinen`. Die Suche
schickt das eingegebene Wort durch dieselbe Funktion und schlägt mit dem Ergebnis nach.

Gemessen am Beispielbestand, gegenüber der reinen Teilstring-Suche von vorher:

| Suche | vorher | jetzt |
|---|---|---|
| `Satzungen` | nur `gebuhrensatzungen` | `satzung`, `abfallsatzung`, `friedhofssatzung`, `hauptsatzung`, … (8) |
| `Häuser` | nur `burgerhauser`, `rathauser`, … | `haus`, `hause`, `backhaus`, `haushalt` |
| `wirtschaftliche` | nur die längeren Adjektive | `wirtschaft`, `landwirtschaft`, `abfallwirtschaft`, … |
| `Burg` | `hamburg`, `einburgerung`, `schomburg` vorn | `altenburg`, `zwergenburg`, `wurzburg` vorn |

Was die Zerlegung nicht kennt, füllt die bisherige Suche nach enthaltenen Teilen auf – gemessen
fällt etwa `Ganerbenburg` durch, weil das Wörterbuch den ersten Teil nicht kennt. Die
analysierten Formen stehen dabei **vorn**, der Beifang landet bei der Begrenzung als Erstes
unter dem Strich.

Zwei Eigenheiten, die man kennen sollte:

> **Der deutsche Stammbildner faltet Umlaute**, bevor er Endungen abschneidet. `Bürger` und
> `Burg` bekommen dadurch denselben Stamm `burg`. Das ist eine echte Fehlverschmelzung und
> nicht zu reparieren, ohne den Stammbildner zu ersetzen. Getragen wird sie davon, dass die
> Erweiterung erst anläuft, wenn die Suche zu wenig gefunden hat, und dass direkte Treffer vor
> erweiterten stehen.

> **Die Zerlegung arbeitet in ihrer eigenen Umlautschreibweise** (`ö` → `oe`), der Rest des
> AddOns in einer anderen (`ö` → `o`). Das stört nicht, weil die Form nirgends mit einer
> gefalteten Form verglichen wird: beim Schreiben und beim Suchen läuft dasselbe Wort durch
> dieselbe Funktion.

Eine Sprache, für die kein Wörterbuch beiliegt, bekommt trotzdem Stammformen – zerlegt wird nur
Deutsch und Englisch. Eine Quelle ohne Sprachangabe bekommt gar keine Formen und fällt auf das
Verhalten von vorher zurück.

**Abhängigkeiten:** `wamania/php-stemmer` (14 Sprachen) und `loupe/matcher` (Zerlegung, braucht
`ext-intl`), zusammen 2,3 MB. Fehlen sie, läuft alles andere unverändert weiter.

## Semantische Suche

Die dritte Art zu suchen: nicht nach Wörtern, sondern nach Bedeutung. Dafür bekommt jeder
Textabschnitt einen **Vektor** – eine Zahlenreihe, die seine Bedeutung beschreibt – und eine
Suche findet die Abschnitte, deren Vektoren dem der Frage am nächsten liegen.

Zwei Voraussetzungen, beide sichtbar unter *Suche → Einstellungen*:

| | |
|---|---|
| **MariaDB ab 11.7** | für den Spaltentyp `VECTOR` und einen Index darauf. MySQL 8 kennt beides nicht, MySQL 9 den Typ ohne Index. Fehlt es, bleibt der ganze Teil aus – Volltext, Wortliste und Analyse laufen unverändert. |
| **Ein Embedding-Profil in ai_platform** | das AddOn erzeugt die Vektoren nicht selbst, es fragt `ai_platform`. Welches Profil, wird in den Einstellungen gewählt. |

### Abschnitte

Ein Vektor beschreibt einen Text von überschaubarer Länge. Ein Haushaltsplan mit einer halben
Million Zeichen als ein einziger Vektor wäre ein Mittelwert über alles und damit über nichts.
Dokumente werden deshalb in **Abschnitte** geteilt (`rex_search_chunk`), und zwar je Quelle
verschieden – ein YForm-Datensatz mit vierzig Zeichen ist ein Abschnitt, ein Haushaltsplan sind
vierhundert.

Eingeschaltet wird das **je Quelle**, zusammen mit Verfahren, Abschnittsgröße und Überlappung.
Vorgabe ist *aus*: Abschnitte einzubetten kostet Geld und Platz, und welche Quelle das wert ist,
weiß nur der Betrieb.

| Verfahren | Wie | Wofür |
|---|---|---|
| **Nach Gliederung** | entlang der Überschriften des Originalinhalts, jeder Abschnitt merkt sich seinen Überschriftenpfad | Artikel und Dokumente, also alles mit Struktur |
| **Feste Größe** | Stücke einer festen Länge mit Überlappung, getrennt an Absatz- und Satzgrenzen | Klartext, CSV, PDFs ohne Überschriften |
| **Ganzes Dokument** | ein Abschnitt je Datensatz, ungeteilt | kurze Inhalte wie YForm-Datensätze und Metadaten |

Am größten Dokument des Beispielbestands (537.000 Zeichen) gemessen:

| Verfahren | Größe | Überlappung | Abschnitte | ⌀ Zeichen |
|---|---|---|---|---|
| Nach Gliederung | 1500 | 200 | 693 | 1475 |
| Nach Gliederung | 3000 | 400 | 353 | 2939 |
| Feste Größe | 800 | 100 | 1268 | 783 |

> **Nach Gliederung wirkt nur, wo es Überschriften gibt.** Fehlen sie, fällt das Verfahren
> stillschweigend auf feste Stücke zurück – die Einstellung sieht richtig aus und tut nichts.
> Am Beispielbestand gemessen ist das der häufigere Fall: von acht PDFs trägt eines eine
> Überschrift, und ausgerechnet das größte mit 904.000 Zeichen keine einzige. Bei den Artikeln
> dagegen 165 von 188. Die Einstellungsseite nennt unter *Gliederung der Dokumente* die Quellen,
> bei denen weniger als die Hälfte der Dokumente eine Überschrift hat.
>
> Für solche Quellen ist nicht das Verfahren der Hebel, sondern die **Größe**. Am Haushaltsplan
> gemessen liefern *Nach Gliederung* und *Feste Größe* bei gleicher Größe Zeichen für Zeichen
> dasselbe Ergebnis; was sich ändert, ist der Anteil reiner Tabellenfragmente:
>
> | Größe / Überlappung | Abschnitte | ⌀ Zeichen | Tabellenfragmente |
> |---|---|---|---|
> | 1500 / 200 | 693 | 1475 | 42 % |
> | 3000 / 400 | 353 | 2939 | 32 % |
>
> Als Tabellenfragment zählt dabei ein Abschnitt, in dem mehr als ein Viertel der Zeichen
> Ziffern sind. Ganz verschwinden sie nicht – sie stehen so im PDF.

**Nach Gliederung** hat ein viertes Feld: *Kleine Abschnitte zusammenlegen*. Ein Abschnitt
unter dieser Zeichenzahl wandert in den folgenden – eine Überschrift mit einem Satz darunter ist
kein eigener Abschnitt. Vorgabe sind 300 Zeichen, `0` schaltet es ab. Die richtige Zahl hängt am
Inhalt: eine Seite aus kurzen Merksätzen will sie klein, ein Dokument mit Kapiteln von drei
Zeilen will sie groß. Zusammengelegt wird nur, solange das Ergebnis noch in die Abschnittsgröße
passt – sonst entstünde genau der überlange Abschnitt, den die Zerlegung vermeiden soll.

Drei Dinge, die dabei fest eingebaut sind:

**Abschnitte werden aus dem Index gelesen, nicht aus der Quelle.** Eine geänderte Zerlegung
braucht deshalb keinen neuen Indexlauf – kein PDF wird ein zweites Mal durch `pdftotext`
geschickt. Dafür gibt es `bin/console search:chunks`.

**Ein Abschnitt, dessen Text sich nicht ändert, behält seine Zeile** – und damit seinen Vektor,
der Geld gekostet hat. Wiedererkannt wird über einen Hash über Text und Überschriftenpfad, nicht
über die Position: ein Abschnitt bleibt derselbe, auch wenn er durch eine Einfügung weiter nach
hinten rutscht.

**Kein Abschnitt überschreitet 16.000 Zeichen.** Ein Einbettungsmodell nimmt längeren Text nicht
an – `text-embedding-3-small` hört bei 8.191 Token auf –, der Aufruf scheitert, und der
Abschnitt bliebe stillschweigend ohne Vektor. Durchgesetzt wird die Grenze beim Aufbau, nicht im
Verfahren: „Ganzes Dokument" darf sie nicht kennen, sonst wäre es nicht mehr das ganze Dokument,
und ein Verfahren aus einem fremden AddOn kann sie nicht kennen.

**Ein Dokument ohne Fließtext wird über seinen Titel eingebettet.** Das ist nicht Verlegenheit,
sondern gemessen die bessere Antwort: die Frage „Kaninchen" findet den Artikel „Hasen" (fünf
Zeichen) als besten Treffer, „Speisekarte" den Eintrag „Die Speisekarte" mit einer Ähnlichkeit
von 0,820. Eine Mindestlänge gab es einmal und die Messung hat sie widerlegt – sie hätte genau
diese Antworten weggeworfen.

### Vektoren erzeugen

Offen heißt: es gibt keine Zeile in `rex_search_vector`. Das ist mit Absicht **keine
Warteschlange** – ein abgebrochener Lauf hinterlässt dann keinen halben Zustand, den jemand
aufräumen müsste, und der nächste Lauf macht einfach da weiter, wo Vektoren fehlen.

Drei Wege, alle auf derselben Klasse:

- **Quellenliste** – je Quelle *Vektoren erzeugen*, mit Zeitbudget. Das richtet sich nach
  `max_execution_time` des Servers (drei Viertel davon, höchstens 90 Sekunden): wer darüber
  hinausläuft, bekommt keine Meldung, sondern eine weiße Seite.
- **Cronjob** *Suche: Vektoren erzeugen*, mit eigenem Zeitbudget und Quellenauswahl.
- **`bin/console search:embed`**, mit `--source`, `--limit` und `--seconds`.

Gemessen: Stapel zu hundert, **4 ms je Abschnitt**. Einzeln wären es 172 ms – fast alles davon
ist Wartezeit auf die Verbindung, nicht Rechnen. Für 2.000 Abschnitte sind das rund 20 Sekunden
und zwei Cent; 50.000 Abschnitte wären gut drei Minuten und 300 MB in der Datenbank. **Der Platz
ist das Thema, nicht die Zeit** – ein Vektor mit 1.536 Dimensionen belegt gemessen 8,5 KB
einschließlich Verwaltung und Nachbarschaftsgraph.

Ein **Wechsel des Profils verwirft alle Vektoren**. Das ist unvermeidlich: die Vektorspalte hat
eine feste Länge, und selbst bei gleicher Länge legt ein anderes Modell denselben Text an eine
andere Stelle. Die Einstellungsseite sagt vor dem Speichern, wie viele betroffen sind; neu
erzeugt wird nichts von allein.

### Hybride Suche

```php
SearchQuery::create($frage)->withSemantic();   // Vorgabe: aus
```

Volltext und Bedeutung liefern Zahlen, die sich **nicht verrechnen lassen**: die Bewertung von
InnoDB ist unbegrenzt und hängt am Wortschatz des Bestands, eine Kosinus-Ähnlichkeit liegt
zwischen 0 und 1. Jede gewichtete Summe der beiden braucht eine Normierung, und die ist auf der
nächsten Installation wieder falsch.

Verglichen werden deshalb **Plätze, keine Werte**. Jede Liste steuert zu einem Dokument
`1/(60 + Platz)` bei, die Summe entscheidet – Reciprocal Rank Fusion. Nebenbei löst das ein
zweites Problem: ein Dokument, das nur eine der beiden Listen kennt, braucht keinen Ersatzwert,
es bekommt schlicht nur einen Summanden.

Die **Relevanz** misst sich danach nicht am besten Treffer, sondern am Erreichbaren: Platz eins
in beiden Listen. Sonst stünde sie immer bei fast hundert Prozent – die Punktzahlen liegen eng
beieinander, 1/61 gegen 1/62 sind anderthalb Prozent. So heißt die Zahl etwas: ein Dokument, das
beide Wege oben gefunden haben, steht bei 1; eines, das nur ein Weg kennt, höchstens bei 0,5.

| Stellschraube | Vorgabe |
|---|---|
| `withSemantic(true, $schwelle)` | aus, Schwelle `0.40` |
| `withSemanticWeight()` | `1.0`, also gleichauf mit dem Volltext |
| `withSemanticLimit()` | `50` Dokumente aus der Bedeutung |

Die **Frage wird eingebettet**, und das kostet gemessen 172 ms gegenüber 7 ms für die
Volltextsuche. Deshalb merkt sich `rex_search_query_vector` die Vektoren schon gestellter Fragen
– bei einem Suchfeld auf einer Website wiederholen sich Begriffe stark. Mit warmem
Zwischenspeicher kostet die Bedeutung noch rund **30 ms** Aufschlag.

### Die Schwelle

Eine Nachbarsuche kennt kein „nichts gefunden": sie liefert immer die nächsten Abschnitte, egal
wie fern sie sind. Ohne Schwelle hinge an jeder Frage dieselbe Handvoll Dokumente.

Der Wert steht an **einer** Stelle, `FriendsOfRedaxo\Search\Vector\Similarity::DEFAULT_MINIMUM`,
und gilt für die hybride Suche wie für die Passagen-API – beide fragen dasselbe Modell.

Auf der Seite **Suche** im Backend lässt er sich für den einzelnen Versuch überschreiben: das
Feld *Mindestähnlichkeit* steht dort mit dem Standardwert vorbelegt, und das Ergebnis nennt im
Titel, mit welcher Schwelle es zustande kam. Bei der Suchart *Volltext* ist das Feld
ausgeblendet – dort hat die Schwelle keine Wirkung. Das ändert nichts an der Voreinstellung – es ist
zum Ausprobieren da, etwa um nach einem Profilwechsel zu sehen, wo die Trennung diesmal liegt.

Er ist gemessen, und die erste Messung war zu eng. Mit vier Fragen, die alle wörtlich im Bestand
standen, kam 0,5 heraus – und daran scheiterte dann „ich suche etwas zum spazieren", obwohl es
die Wanderartikel sehr wohl findet, nur eben bei 0,414. **Umgangssprachlich gestellte Fragen
liegen durchweg tiefer als knapp formulierte Stichworte**, und zwar unabhängig davon, ob die
Antwort da ist. Mit zwölf Fragen gegen denselben Bestand:

| | beste Ähnlichkeit |
|---|---|
| beantwortbar (7) | **0,409** – 0,741 |
| nichts im Bestand (4) | 0,198 – **0,393** |

Dazwischen liegt `0.40`, und die Trennung ist vollständig. Der Wert hängt am Modell und gehört
nach einem Profilwechsel geprüft – mit `bin/console search:ask` an ein paar Fragen, die sich
beantworten lassen, und ein paar, die es nicht tun.

**Das Band ist allerdings schmal**, und das sollte man wissen. „Wie repariere ich ein
Rennrad-Schaltwerk?" bleibt mit 0,374 draußen; dieselbe Frage ohne das letzte Wort – „Wie
repariere ich ein Rennrad?" – kommt auf 0,414 und findet einen Artikel über eine reparierte
Espressomaschine. Zwischen diesem Fehltreffer und dem schwächsten richtigen Treffer
(„spazieren gehen", 0,409) liegen fünf Tausendstel. Eine höhere Schwelle würde den richtigen
mit wegwerfen. Mehr ist aus der Ähnlichkeit allein nicht herauszuholen – dafür gibt es die
gemischte Suchart, in der der Volltext mitentscheidet.

### Passagen-API

Für AddOns, die mit einem Sprachmodell antworten wollen. Sie liefert nicht Treffer, sondern
**Textbausteine samt Herkunft** – damit ein Modell zitieren und verlinken kann, statt zu
behaupten.

```php
use FriendsOfRedaxo\Search\Passage\PassageQuery;
use FriendsOfRedaxo\Search\Passage\Retriever;

$passagen = (new Retriever())->retrieve(
    PassageQuery::create($frage)
        ->withSourceIds(19, 20)
        ->withLanguages('de')
        ->withMaxPassages(8)
        ->withMaxCharacters(12000),
);

if ($passagen->isEmpty()) {
    // Dazu steht hier nichts. $passagen->bestSimilarity sagt, wie knapp es war.
}

$kontext = $passagen->toPromptContext();   // "[1] Burg › Öffnungszeiten\n…"

foreach ($passagen as $p) {
    $p->text;          // der Abschnitt selbst
    $p->getLabel();    // "Burg › Öffnungszeiten (2/5)"
    $p->url;           // zum Verlinken
    $p->similarity;    // 0 bis 1
}
```

Drei Entscheidungen, die die API trifft und nicht dem Aufrufer überlässt:

**Die Obergrenze in Zeichen gehört hierher.** Ein Kontextfenster ist endlich, und wer Passagen
weiterreicht, soll nicht erst selbst nachzählen müssen. Eine Passage, die nicht mehr ganz
hineinpasst, fällt weg statt gekürzt zu werden – ein abgeschnittener Satz ist schlimmer als ein
fehlender Absatz, weil ein Modell ihn trotzdem zu Ende denkt. Die beste kommt immer mit, auch
wenn sie allein die Grenze sprengt.

**Doppelte Texte fallen raus.** Erfassen zwei Quellen dieselbe Datei, stünde derselbe Absatz
zweimal im Kontext – und sähe aus wie die Bestätigung durch eine zweite Quelle.

**Eine leere Antwort ist eine Antwort.** Findet sich nichts über der Schwelle, kommt nichts
zurück. Das ist die richtige Grundlage für „dazu steht hier nichts" – und besser, als ein Modell
aus den nächstbesten Abschnitten etwas bauen zu lassen.

Über den Extension Point `SEARCH_PASSAGES` lassen sich Passagen aussortieren oder ergänzen,
bevor die Grenzen greifen.

## Was die Suche nicht kann

**Bedeutungsähnlichkeit ist keine Taxonomie.** Gemessen liegen „Hunde" und „Hasen" bei 0,569 –
praktisch gleichauf mit „Hunde" und „Katze" (0,589). Das Modell bildet ab, in welchen Sätzen
Wörter vorkommen, nicht welche Begriffe einander unterstehen. Besonders deutlich: „Tier" liegt
mit 0,375 **weiter** weg von „Hunde" als „Hasen". Keine Schwelle trennt das; dafür gibt es die
Volltextsuche, und genau deshalb ist *Volltext und Bedeutung* die gemischte Suchart.

**Es gibt keine Abfragesprache.** Kein UND, kein ODER, keine Klammern, keine Phrasen. Für
Websites dieser Größenordnung ist das Ballast, den ohnehin niemand tippt.

**Der Index wird nicht laufend fortgeschrieben.** Ein Neuaufbau läuft je Quelle über die
Quellenliste, für alle über den Konsolenbefehl oder den Cronjob.

## Auf der Konsole

| Befehl | Was er tut |
|---|---|
| `search:index` | baut den Index neu auf – liest Quellen, extrahiert Dateien |
| `search:words` | baut nur die Wortliste und die Formen neu, ohne zu indizieren |
| `search:chunks` | zerlegt neu, ohne zu indizieren – nach einer Änderung an der Zerlegung |
| `search:embed` | erzeugt die fehlenden Vektoren, `--limit` und `--seconds` als Budget |
| `search:ask "…"` | zeigt, welche Abschnitte eine Frage beantworten würden |

`search:ask` ist das Werkzeug, um Schwelle und Zerlegung zu beurteilen: zu wenig Passagen heißt
Schwelle zu hoch oder Quelle nicht eingebettet, Passagen voller Tabellenfragmente heißen, dass
die Zerlegung dieser Quelle nicht passt.

## Tabellen

| Tabelle | Inhalt | Wird angelegt |
|---|---|---|
| `rex_search_source` | die konfigurierten Quellen | bei der Installation |
| `rex_search_index` | die Dokumente, mit Volltextindex | bei der Installation |
| `rex_search_word` | der Wortschatz je Quelle und Sprache | bei der Installation |
| `rex_search_word_form` | Stammformen und zerlegte Wortteile | bei der Installation |
| `rex_search_chunk` | die Abschnitte der Dokumente | bei der Installation |
| `rex_search_query_vector` | gemerkte Vektoren gestellter Fragen | bei der Installation |
| `rex_search_vector` | die Vektoren der Abschnitte | **beim ersten Vektor** |

Die letzte entsteht erst zur Laufzeit, weil der Spaltentyp `VECTOR` eine feste Länge verlangt
und die erst das Modell hinter dem gewählten Profil bestimmt.
