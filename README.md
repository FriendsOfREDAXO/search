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

```php
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Query\Searcher;

$result = (new Searcher())->search(
    SearchQuery::create($_GET['q'] ?? '')
        ->withNamespaces('article')
        ->withClangIds(rex_clang::getCurrentId())
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
2. Die **exakte Wortform** bekommt einen Zuschlag. Gesucht wird mit `+burg*`, damit
   Zusammensetzungen gefunden werden – ohne diesen Zuschlag läge eine Seite über das
   *Burgfest* gleichauf mit einer über die *Burg*. Faktor über `withExactBoost()`,
   Vorgabe `0.5`, `0.0` schaltet ab.
3. Ein Treffer im **Titel** zählt zusätzlich, dafür gibt es einen zweiten Volltextindex über
   `title` allein. Faktor über `withTitleBoost()`, Vorgabe `1.5`, `0.0` schaltet ab.
4. Die **Länge** dämpft, aber erst oberhalb einer Schwelle und dann logarithmisch. Schwelle
   über `withLengthScale()`, Vorgabe `5000` Zeichen, `0` schaltet ab.
5. Optional ein Faktor je Quelle: `withSourceWeights([19 => 1.0, 20 => 0.5])`.

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

> **Zu den Sprachen.** Jedes Wort trägt seine `clang_id`, die Erweiterung bevorzugt die
> gesuchte Sprache. Wörter ohne Sprache – Dokumente aus dem Medienpool und
> YForm-Datensätze haben keine – zählen immer mit, sonst fielen sie aus jeder
> sprachbezogenen Suche. Gespeichert wird das Wort so, wie es im Text steht; `word` ist
> deshalb `varbinary`, denn unter `utf8mb4_general_ci` gelten „gross" und „groß" als
> dasselbe und ein eindeutiger Schlüssel würde eines davon verschlucken.

### Suchbegriffe von außen umschreiben

```php
rex_extension::register('SEARCH_QUERY_TERMS', function (rex_extension_point $ep) {
    /** @var FriendsOfRedaxo\Search\Query\SearchTerms $terms */
    $terms = $ep->getSubject();
    // ... eigenen Ausdruck bauen, etwa mit Wortstämmen oder Synonymen
    return $terms;
});
```

Hier hängen später Stemming, Synonyme und die unscharfe Suche ein.

### Backend

Die Seite *Suche → Suche* nutzt dieselbe API mit Quellenauswahl, Hervorhebung und Blättern.
