<?php

use FriendsOfRedaxo\Search\Backend\Diagnosis;
use FriendsOfRedaxo\Search\Extract\ExtractionCache;

$addon = rex_addon::get('search');
$csrf = rex_csrf_token::factory('search_settings');

// Es gibt hier nichts einzustellen, aber zwei Dinge zu tun, die sonst nur ueber die
// Konsole gehen -- und die braucht nicht jeder Betrieb.
if ('' !== rex_post('action', 'string')) {
    if (!$csrf->isValid()) {
        echo rex_view::error($addon->i18n('csrf_token_invalid'));
    } elseif ('clear_cache' === rex_post('action', 'string')) {
        [$files] = Diagnosis::cacheSize();
        ExtractionCache::clear();
        echo rex_view::success($addon->i18n('search_settings_cache_cleared', $files));
    }
}

/**
 * Eine Zeile Befund als Tabellenzeile.
 *
 * @param array{label: string, state: string, detail: string} $check
 */
$row = static function (array $check): string {
    [$icon, $class] = match ($check['state']) {
        Diagnosis::OK => ['rex-icon fa-check', 'text-success'],
        Diagnosis::WARNING => ['rex-icon fa-exclamation-triangle', 'text-warning'],
        default => ['rex-icon fa-times', 'text-danger'],
    };

    return '<tr>'
        . '<td class="' . $class . '" style="width:2em"><i class="' . $icon . '"></i></td>'
        . '<td><strong>' . rex_escape($check['label']) . '</strong></td>'
        . '<td>' . rex_escape($check['detail']) . '</td>'
        . '</tr>';
};

/**
 * Ein Abschnitt: Tabelle randlos, Fussnote und Schaltflaechen im Fuss.
 *
 * Die Trennung ist nicht Geschmack, sondern die Bauart des Fragments: "content" wird
 * ohne Innenabstand ausgegeben, damit eine Tabelle bis an den Rand reicht. Alles andere
 * gehoert in "footer", sonst klebt es ohne Abstand an der Panelkante.
 *
 * @param list<array{label: string, state: string, detail: string}> $checks
 */
$section = static function (string $title, array $checks, string $footer = '') use ($row): string {
    $rows = '';
    foreach ($checks as $check) {
        $rows .= $row($check);
    }

    $fragment = new rex_fragment();
    $fragment->setVar('title', $title);
    $fragment->setVar('content', '<table class="table table-hover">' . $rows . '</table>', false);
    if ('' !== $footer) {
        $fragment->setVar('footer', $footer, false);
    }

    return $fragment->parse('core/page/section.php');
};

echo rex_view::info($addon->i18n('search_settings_intro'));

echo $section($addon->i18n('search_settings_tools'), Diagnosis::tools());
echo $section($addon->i18n('search_settings_php'), Diagnosis::php());

// Extraktoren in der Reihenfolge, in der die Registry sie fragt.
$rows = '';
foreach (Diagnosis::extractors() as $extractor) {
    [$icon, $class] = $extractor['available']
        ? ['rex-icon fa-check', 'text-success']
        : ['rex-icon fa-times', 'text-muted'];
    $rows .= '<tr class="' . ($extractor['available'] ? '' : 'text-muted') . '">'
        . '<td class="' . $class . '" style="width:2em"><i class="' . $icon . '"></i></td>'
        . '<td><strong>' . rex_escape($extractor['label']) . '</strong><br><small class="text-muted">' . rex_escape($extractor['key']) . '</small></td>'
        . '<td>' . rex_escape($extractor['extensions']) . '</td>'
        . '<td style="width:6em">' . $extractor['priority'] . '</td>'
        . '</tr>';
}
$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('search_settings_extractors'));
$fragment->setVar('content',
    '<table class="table table-hover">'
    . '<thead><tr><th></th><th>' . $addon->i18n('search_settings_extractor') . '</th>'
    . '<th>' . $addon->i18n('search_settings_extensions') . '</th>'
    . '<th>' . $addon->i18n('search_settings_priority') . '</th></tr></thead>'
    . $rows . '</table>',
    false);
$fragment->setVar('footer', '<p class="help-block">' . $addon->i18n('search_settings_extractors_notice') . '</p>', false);
echo $fragment->parse('core/page/section.php');

echo $section($addon->i18n('search_settings_database'), Diagnosis::database());

[$files] = Diagnosis::cacheSize();
$action = '';
if ($files > 0) {
    $action = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . $csrf->getHiddenField()
        . '<input type="hidden" name="action" value="clear_cache">'
        . '<button class="btn btn-default" type="submit"><i class="rex-icon fa-trash"></i> '
        . $addon->i18n('search_settings_clear_cache') . '</button>'
        . '<p class="help-block" style="margin-bottom:0">' . $addon->i18n('search_settings_clear_cache_notice') . '</p>'
        . '</form>';
}
echo $section($addon->i18n('search_settings_storage'), Diagnosis::storage(), $action);
