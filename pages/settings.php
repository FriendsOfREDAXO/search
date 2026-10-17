<?php

use FriendsOfRedaxo\Search\Backend\Diagnosis;
use FriendsOfRedaxo\Search\Extract\ExtractionCache;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\QueryVectorCache;
use FriendsOfRedaxo\Search\Vector\VectorStore;
use FriendsOfRedaxo\Search\Vector\VectorSupport;

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
    } elseif ('set_profile' === rex_post('action', 'string')) {
        $store = new VectorStore();
        $before = (int) rex_config::get('search', 'embedding_profile', 0);
        $after = rex_post('embedding_profile', 'int', 0);

        if ($before === $after) {
            echo rex_view::info($addon->i18n('search_settings_embedding_unchanged'));
        } else {
            // Vektoren verschiedener Modelle sind nicht vergleichbar -- selbst bei
            // gleicher Laenge legt ein anderes Modell denselben Text woanders hin. Ein
            // Wechsel verwirft sie deshalb, und zwar sofort und sichtbar. Neu erzeugt
            // wird nichts von allein: das kostet Geld, also loest es jemand aus.
            $discarded = $store->countAll();
            $store->drop();
            // Die gemerkten Fragevektoren gehoeren zum alten Modell. Der Schluessel
            // traegt zwar die Profil-Id, sie waeren also nur totes Gewicht -- aber
            // totes Gewicht, das niemand mehr aufraeumt.
            (new QueryVectorCache())->clear();
            rex_config::set('search', 'embedding_profile', $after);
            echo rex_view::success($addon->i18n('search_settings_embedding_saved', $discarded));
        }
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

echo $section($addon->i18n('search_settings_languages'), Diagnosis::languages());
// Semantische Suche: Befunde plus die einzige Einstellung, die es hier gibt.
$vectorFooter = '';
if (VectorSupport::isAvailable()) {
    $profiles = EmbeddingClient::getProfiles();
    if ([] === $profiles) {
        $vectorFooter = '<p class="help-block">' . $addon->i18n('search_settings_embedding_no_profiles') . '</p>';
    } else {
        $select = new rex_select();
        $select->setName('embedding_profile');
        $select->setId('search-embedding-profile');
        $select->setAttribute('class', 'form-control');
        $select->addOption($addon->i18n('search_settings_embedding_none'), '0');
        foreach ($profiles as $profileId => $label) {
            $select->addOption($label, (string) $profileId);
        }
        $select->setSelected((string) (EmbeddingClient::getProfileId() ?? 0));

        $fragment = new rex_fragment();
        $fragment->setVar('elements', [[
            'label' => '<label for="search-embedding-profile">' . $addon->i18n('search_settings_embedding_profile') . '</label>',
            'field' => $select->get(),
            'note' => $addon->i18n('search_settings_embedding_profile_notice'),
        ]], false);

        $vectorFooter = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
            . $csrf->getHiddenField()
            . '<input type="hidden" name="action" value="set_profile">'
            . $fragment->parse('core/form/form.php')
            . '<button class="btn btn-save" type="submit">' . $addon->i18n('save') . '</button>'
            . '</form>';
    }
}
echo $section($addon->i18n('search_settings_vectors'), Diagnosis::vectors(), $vectorFooter);
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
