# Changelog

## 0.2.1 – 2026-10-18

Behebt die Installation über Composer auf PHP 8.1 und 8.2.

- `composer.json` nennt jetzt selbst `php >=8.1` und legt die Plattform darauf fest. Vorher stand die Untergrenze nur in der `package.yml`, und das Lockfile hatte sich `joomla/string 4.0.0` herangezogen – eine Fassung, die PHP 8.3 verlangt. Auf PHP 8.1 und 8.2 brach `composer install` damit ab. Ersetzt durch `joomla/string 3.0.4`; betroffen sind nur UTF-8-Hilfsfunktionen des Stemmers, alle 14 Sprachen liefern unveränderte Stammformen.
- `composer.json` hat Name, Beschreibung, Typ und Lizenz bekommen und besteht `composer validate --strict`.
- Die CI prüft das jetzt: ein Job installiert die Abhängigkeiten auf PHP 8.1, der kleinsten unterstützten Fassung.

## 0.2.0 – 2026-10-17

Erste veröffentlichte Fassung.

### Suche

- Volltextsuche über den Volltextindex von MySQL und MariaDB, mit Facetten, Blättern und einer Relevanz, die sich am besten Treffer der Suche misst.
- Wortliste je Quelle und Sprache als Grundlage für Teiltreffer und Tippfehlerkorrektur.
- Stammformen für 14 Sprachen und Zerlegung zusammengesetzter Wörter für Deutsch und Englisch – „Häuser" findet „Haus", „Ruine" findet „Burgruine".
- Die Sprache wird je Quelle festgelegt. Sie lässt sich nirgends herleiten: der Sprachcode einer REDAXO-Sprache ist freier Text, Medien und YForm-Datensätze haben gar keine.

### Semantische Suche

Neu in dieser Fassung, und optional – ohne sie läuft alles Übrige unverändert.

- Dokumente lassen sich je Quelle in Abschnitte zerlegen: nach Gliederung, in feste Stücke oder ungeteilt.
- Vektoren entstehen über das AddOn `ai_platform` und liegen in MariaDB ab 11.7 im Spaltentyp `VECTOR` mit Vektorindex. Fehlt die Version, bleibt es beim Volltext.
- **Hybride Suche**: Volltext und Bedeutung werden über Reciprocal Rank Fusion zu einer Rangliste gemischt.
- **Passagen-API** für AddOns, die mit einem Sprachmodell antworten – sie liefert Textbausteine samt Herkunft statt Treffer.
- Eine Mindestähnlichkeit von 0.40 trennt beantwortbare von unbeantwortbaren Fragen. Der Wert ist gemessen und lässt sich auf der Suchseite für den einzelnen Versuch überschreiben.
- Vektoren entstehen getrennt vom Index: über die Quellenliste, die Konsole oder einen Cronjob.

### Quellen

YForm-Tabellen, Artikel-Inhalt, Artikel-Metadaten, Medienpool-Dokumente und Medienpool-Angaben. Je Quelle lassen sich Filter setzen, die entscheiden, welche Datensätze überhaupt in den Index kommen. Eigene Quellentypen, Filter, Extraktoren und Zerlegungsverfahren melden sich über Extension Points an.

### Backend

Quellenübersicht, Testsuche mit allen drei Suchvarianten, eine Einstellungsseite, die zeigt, was auf dem Server vorhanden ist und welche Wege die Suche deshalb gehen kann, und eine Anleitung. Oberfläche auf Deutsch und Englisch.

### Konsole

`search:index`, `search:words`, `search:chunks`, `search:embed` und `search:ask`. Dazu zwei Cronjobs für den Indexaufbau und das Erzeugen der Vektoren.

### Voraussetzungen

PHP 8.1, REDAXO 5.17. Für die semantische Suche zusätzlich MariaDB 11.7 und das AddOn `ai_platform`.
