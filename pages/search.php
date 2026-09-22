<?php

use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Source\SourceRepository;

$addon = rex_addon::get('search');
$index = new IndexRepository();
$sources = (new SourceRepository())->findAll();

$query = trim(rex_request('q', 'string'));
$selectedSourceIds = array_values(array_filter(array_map('intval', rex_request('sources', 'array', [])), static fn (int $id): bool => $id > 0));

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

$fragment = new rex_fragment();
$fragment->setVar('elements', [
    [
        'label' => '<label for="search-q">' . $addon->i18n('status_query') . '</label>',
        'field' => '<input class="form-control" type="text" id="search-q" name="q" value="' . rex_escape($query) . '" autofocus>',
    ],
    [
        'label' => '<label for="search-sources">' . $addon->i18n('search_sources') . '</label>',
        'field' => $sourceSelect->get(),
        'note' => $addon->i18n('search_sources_notice'),
    ],
], false);
$body = $fragment->parse('core/form/form.php');

$fragment = new rex_fragment();
$fragment->setVar('elements', [
    ['field' => '<button class="btn btn-primary rex-form-aligned" type="submit"><i class="rex-icon fa-search"></i> ' . $addon->i18n('status_search') . '</button>'],
], false);
$buttons = $fragment->parse('core/form/submit.php');

if ('' !== $query) {
    $results = $index->search($query, [], [], 50, $selectedSourceIds);
    if ([] === $results) {
        $body .= '<p>' . $addon->i18n('status_no_results') . '</p>';
    } else {
        $body .= '<p><strong>' . $addon->i18n('search_results', count($results)) . '</strong></p>';
        $body .= '<div class="list-group">';
        foreach ($results as $row) {
            $title = '' !== (string) $row['title'] ? (string) $row['title'] : (string) $row['index_id'];
            $link = '' !== (string) $row['url']
                ? '<a href="' . rex_escape((string) $row['url']) . '" target="_blank" rel="noopener">' . rex_escape($title) . '</a>'
                : rex_escape($title);
            $sourceName = $sourceNames[(int) $row['source_id']] ?? ('#' . $row['source_id']);
            $body .= '<div class="list-group-item">'
                . '<h4 class="list-group-item-heading">' . $link . '</h4>'
                . '<p class="list-group-item-text">' . rex_escape((string) $row['snippet']) . '</p>'
                . '<small class="text-muted">' . rex_escape($addon->i18n('status_result_meta', $sourceName, $row['namespace'], $row['type'], $row['index_id'], number_format((float) $row['score'], 2))) . '</small>'
                . '</div>';
        }
        $body .= '</div>';
    }
}

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('search'));
$fragment->setVar('body', $body, false);
$fragment->setVar('buttons', $buttons, false);
echo '<form action="' . rex_url::currentBackendPage() . '" method="get">'
    . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
    . $fragment->parse('core/page/section.php')
    . '</form>';
