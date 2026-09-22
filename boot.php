<?php

use FriendsOfRedaxo\Search\Index\IncrementalUpdater;
use FriendsOfRedaxo\Search\Source\SourceTypeRegistry;

$addon = rex_addon::get('search');

if (rex::isBackend() && 'search' === rex_be_controller::getCurrentPagePart(1)) {
    // Cache-Buster ueber die Dateizeit, damit Aenderungen am Skript ohne Versionssprung ankommen.
    foreach (['search.js' => 'js', 'search.css' => 'css'] as $asset => $kind) {
        $file = $addon->getAssetsPath($asset);
        $url = $addon->getAssetsUrl($asset) . '?buster=' . (is_file($file) ? filemtime($file) : $addon->getVersion());
        'js' === $kind ? rex_view::addJsFile($url) : rex_view::addCssFile($url);
    }
}

// Einzelaktualisierung: Jeder Quellentyp nennt die Extension Points, auf die er reagiert.
// Die Registry wird erst nach PACKAGES_INCLUDED befragt, damit fremde AddOns ihre Typen
// unabhaengig von der Boot-Reihenfolge anmelden koennen.
rex_extension::register('PACKAGES_INCLUDED', static function () {
    foreach (SourceTypeRegistry::all() as $type) {
        foreach ($type->getExtensionPoints() as $extensionPoint) {
            rex_extension::register($extensionPoint, static function (rex_extension_point $ep) use ($type): void {
                IncrementalUpdater::handle($ep, $type);
            }, rex_extension::LATE);
        }
    }
});
