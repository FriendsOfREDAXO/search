<?php

use FriendsOfRedaxo\Search\Cronjob\ReindexCronjob;

$addon = rex_addon::get('search');

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
}
