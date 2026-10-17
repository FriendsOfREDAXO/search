<?php

use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Passage\Retriever;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Vector\Similarity;
use FriendsOfRedaxo\Search\Vector\VectorSupport;

$addon = rex_addon::get('search');
$sources = (new SourceRepository())->findAll();
$retriever = new Retriever();
$semanticReady = $retriever->isReady();

/** Volltext allein, Volltext und Bedeutung gemischt, oder nur die Passagen-API. */
const SEARCH_MODE_FULLTEXT = 'fulltext';
const SEARCH_MODE_HYBRID = 'hybrid';
const SEARCH_MODE_PASSAGES = 'passages';

$query = trim(rex_request('q', 'string'));
$page = max(1, rex_request('p', 'int', 1));
$perPage = 20;
$selectedSourceIds = array_values(array_filter(array_map('intval', rex_request('sources', 'array', [])), static fn (int $id): bool => $id > 0));
$selectedLanguage = Language::normalize(rex_request('lang', 'string'));

// Die Schwelle steht sonst nur im Quelltext. Hier ist sie ein Feld, damit sich
// ausprobieren laesst, was ein anderer Wert am Ergebnis aendert -- genau dafuer ist
// diese Seite da. Eine leere oder unsinnige Eingabe faellt auf den Standard zurueck.
$requestedMinSimilarity = str_replace(',', '.', trim(rex_request('min', 'string', '')));
$minSimilarity = is_numeric($requestedMinSimilarity)
    ? Similarity::clamp((float) $requestedMinSimilarity)
    : Similarity::DEFAULT_MINIMUM;

$mode = rex_request('mode', 'string', SEARCH_MODE_FULLTEXT);
if (!in_array($mode, [SEARCH_MODE_FULLTEXT, SEARCH_MODE_HYBRID, SEARCH_MODE_PASSAGES], true)) {
    $mode = SEARCH_MODE_FULLTEXT;
}
// Ohne Vektoren gibt es nur den Volltext. Die Auswahl steht trotzdem da, nur abgeblendet
// und mit dem Grund daneben -- sonst sucht man die Einstellung, die es gar nicht gibt.
if (!$semanticReady) {
    $mode = SEARCH_MODE_FULLTEXT;
}

/**
 * Die URL dieser Suche, mit geaenderten Parametern.
 *
 * currentBackendPage() maskiert das Trennzeichen bereits zu &amp;. Noch einmal durch
 * rex_escape() wuerde daraus &amp;amp;, der Browser machte daraus den Parameter "amp;q"
 * -- die Seite bekaeme dann weder Suchbegriff noch Seitenzahl.
 *
 * @param array<string, mixed> $overrides
 */
$url = static function (array $overrides = []) use ($query, $selectedSourceIds, $selectedLanguage, $mode, $page, $minSimilarity): string {
    return rex_url::currentBackendPage(array_merge([
        'q' => $query,
        'sources' => $selectedSourceIds,
        'lang' => $selectedLanguage,
        'mode' => $mode,
        'min' => $minSimilarity,
        'p' => $page,
    ], $overrides));
};

// ---------- Formular ----------
$sourceSelect = new rex_select();
$sourceSelect->setName('sources[]');
$sourceSelect->setId('search-sources');
$sourceSelect->setAttribute('class', 'form-control');
$sourceSelect->setMultiple(true);
$sourceSelect->setSize(min(8, max(3, count($sources))));
$sourceNames = [];
foreach ($sources as $source) {
    $type = $source->getType();
    $label = $source->name . ' [' . (null === $type ? $source->typeKey : $type->getNamespace() . ' / ' . $type->getType($source)) . ']';
    $sourceNames[$source->requireId()] = $source->name;
    $sourceSelect->addOption($label . ($source->status ? '' : ' – ' . $addon->i18n('inactive')), (string) $source->requireId());
}
$sourceSelect->setSelected(array_map('strval', $selectedSourceIds));

// Nur die Sprachen zur Auswahl stellen, die auch vorkommen -- eine Liste aus vierzehn
// Eintraegen, von denen dreizehn nichts finden, waere keine Hilfe.
$usedLanguages = [];
foreach ($sources as $source) {
    $language = $source->getLanguage();
    if (Language::UNKNOWN !== $language) {
        $usedLanguages[$language] = Language::label($language);
    }
}
ksort($usedLanguages);

$elements = [];
$elements[] = [
    'label' => '<label for="search-q">' . $addon->i18n('status_query') . '</label>',
    'field' => '<input class="form-control" type="text" id="search-q" name="q" value="' . rex_escape($query) . '" autofocus>',
];

$modeSelect = new rex_select();
$modeSelect->setName('mode');
$modeSelect->setId('search-mode');
$modeSelect->setAttribute('class', 'form-control');
if (!$semanticReady) {
    $modeSelect->setAttribute('disabled', 'disabled');
}
foreach ([
    SEARCH_MODE_FULLTEXT => 'search_mode_fulltext',
    SEARCH_MODE_HYBRID => 'search_mode_hybrid',
    SEARCH_MODE_PASSAGES => 'search_mode_passages',
] as $value => $label) {
    $modeSelect->addOption($addon->i18n($label), $value);
}
$modeSelect->setSelected($mode);
$elements[] = [
    'label' => '<label for="search-mode">' . $addon->i18n('search_mode') . '</label>',
    'field' => $modeSelect->get(),
    'note' => $semanticReady
        ? $addon->i18n('search_mode_notice')
        : $addon->i18n('search_semantic_unavailable', VectorSupport::MIN_MARIADB),
];

// Ohne Vektoren gibt es nichts einzustellen; dann waere das Feld nur eine Frage mehr.
//
// Beim reinen Volltext hat die Schwelle keine Wirkung, deshalb ist das Feld dort weg.
// Den Anfangszustand setzt der Server ueber die Klasse -- so steht das Feld gleich
// richtig, auch ohne JavaScript. Das Skript uebernimmt nur den Wechsel der Suchart,
// anhand der beiden data-Angaben am Eingabefeld.
if ($semanticReady) {
    $semanticModes = SEARCH_MODE_HYBRID . ' ' . SEARCH_MODE_PASSAGES;
    $elements[] = [
        'class' => SEARCH_MODE_FULLTEXT === $mode ? 'search-hidden' : '',
        'label' => '<label for="search-min">' . $addon->i18n('search_min_similarity') . '</label>',
        'field' => '<input class="form-control" type="number" id="search-min" name="min" step="0.01" min="0" max="1"'
            . ' data-search-shown-by="search-mode" data-search-shown-for="' . $semanticModes . '"'
            . ' value="' . rex_escape(number_format($minSimilarity, 2, '.', '')) . '">',
        'note' => $addon->i18n('search_min_similarity_notice', number_format(Similarity::DEFAULT_MINIMUM, 2, ',', '')),
    ];
}

$elements[] = [
    'label' => '<label for="search-sources">' . $addon->i18n('search_sources') . '</label>',
    'field' => $sourceSelect->get(),
    'note' => $addon->i18n('search_sources_notice'),
];

if ([] !== $usedLanguages) {
    $languageSelect = new rex_select();
    $languageSelect->setName('lang');
    $languageSelect->setId('search-lang');
    $languageSelect->setAttribute('class', 'form-control');
    $languageSelect->addOption($addon->i18n('search_language_all'), '');
    foreach ($usedLanguages as $code => $label) {
        $languageSelect->addOption($label, $code);
    }
    $languageSelect->setSelected($selectedLanguage);
    $elements[] = [
        'label' => '<label for="search-lang">' . $addon->i18n('search_language_filter') . '</label>',
        'field' => $languageSelect->get(),
        'note' => $addon->i18n('search_language_filter_notice'),
    ];
}

$fragment = new rex_fragment();
$fragment->setVar('elements', $elements, false);
$formBody = $fragment->parse('core/form/form.php');

$fragment = new rex_fragment();
$fragment->setVar('elements', [
    // Eine neue Suche faengt auf Seite eins an.
    ['field' => '<button class="btn btn-primary rex-form-aligned" type="submit" name="p" value="1"><i class="rex-icon fa-search"></i> ' . $addon->i18n('status_search') . '</button>'],
], false);
$formButtons = $fragment->parse('core/form/submit.php');

// Das Formular ist ein eigener Abschnitt. Vorher stand es mit dem Ergebnis in einem,
// und die Schaltflaeche landete dadurch unter der letzten Trefferzeile.
$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('search'));
$fragment->setVar('body', $formBody, false);
$fragment->setVar('buttons', $formButtons, false);
echo '<form action="' . rex_url::currentBackendPage() . '" method="get">'
    . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
    . $fragment->parse('core/page/section.php')
    . '</form>';

if ('' === $query) {
    return;
}

// ---------- Ergebnis ----------
require __DIR__ . '/_search_results.php';
