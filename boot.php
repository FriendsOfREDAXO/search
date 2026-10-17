<?php

use FriendsOfRedaxo\Search\Analysis\Analyzer;
use FriendsOfRedaxo\Search\Cronjob\EmbedCronjob;
use FriendsOfRedaxo\Search\Cronjob\ReindexCronjob;

$addon = rex_addon::get('search');

// Fremdbibliotheken fuer die Dokumentenextraktion. Fehlt das Verzeichnis, weil das AddOn
// ohne composer install ausgeliefert wurde, laufen Volltext und Artikelquellen weiter;
// nur HTML-nach-Markdown und der PDF-Rueckfall stehen dann nicht zur Verfuegung.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Die Wortzerlegung bereitet ihr Woerterbuch beim ersten Zugriff auf und legt das Ergebnis
// ab. Ohne diese Angabe schriebe sie dafuer nach vendor/ -- auf einem Server mit
// schreibgeschuetztem vendor faellt sie damit aus, und zwar lautlos.
Analyzer::setCacheDirectory(rex_path::addonData('search', 'dictionaries'));

if (rex::isBackend() && 'search' === rex_be_controller::getCurrentPagePart(1)) {
    // Cache-Buster ueber die Dateizeit, damit Aenderungen am Skript ohne Versionssprung ankommen.
    foreach (['search.js' => 'js', 'search.css' => 'css'] as $asset => $kind) {
        $file = $addon->getAssetsPath($asset);
        $url = $addon->getAssetsUrl($asset) . '?buster=' . (is_file($file) ? filemtime($file) : $addon->getVersion());
        'js' === $kind ? rex_view::addJsFile($url) : rex_view::addCssFile($url);
    }
}

// Der Index wird bewusst nicht durch Extension Points fortgeschrieben. Ein Neuaufbau
// laeuft ueber die Quellenliste, den Konsolenbefehl search:index oder diesen Cronjob.
if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(ReindexCronjob::class);
    rex_cronjob_manager::registerType(EmbedCronjob::class);
}
