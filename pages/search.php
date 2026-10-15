<?php

use FriendsOfRedaxo\Search\Query\Searcher;
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Query\SearchTerms;
use FriendsOfRedaxo\Search\Source\SourceRepository;

$addon = rex_addon::get('search');
$sources = (new SourceRepository())->findAll();

$query = trim(rex_request('q', 'string'));
$page = max(1, rex_request('p', 'int', 1));
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
    $result = (new Searcher())->search(
        SearchQuery::create($query)
            ->withSourceIds(...$selectedSourceIds)
            ->withPage($page, 20),
    );

    // Weggelassene Woerter benennen: "keine Treffer" waere hier die falsche Auskunft.
    $ignored = [];
    foreach ($result->getIgnoredWords() as $word => $reason) {
        // Kein Markup in die Argumente: i18n() maskiert sie, das Markup waere sonst sichtbar.
        $ignored[] = $word . ' (' . $addon->i18n(SearchTerms::REASON_STOPWORD === $reason ? 'search_word_stopword' : 'search_word_too_short') . ')';
    }
    if ([] !== $ignored) {
        $body .= rex_view::warning($addon->i18n('search_words_ignored', implode(', ', $ignored)));
    }

    if ($result->isCorrected()) {
        $body .= rex_view::info($addon->i18n(
            'search_words_corrected',
            implode(', ', array_keys($result->corrections)),
            implode(', ', array_values($result->corrections)),
        ));
    }

    $expanded = [];
    foreach ($result->getExpansions() as $forms) {
        foreach ($forms as $form) {
            $expanded[$form] = true;
        }
    }
    if ([] !== $expanded) {
        $body .= rex_view::info($addon->i18n('search_words_expanded', implode(', ', array_keys($expanded))));
    }

    if ($result->isEmpty()) {
        $body .= '<p>' . $addon->i18n('status_no_results') . '</p>';
    } else {
        $body .= '<p><strong>' . $addon->i18n('search_results', $result->total) . '</strong>';
        if (1 < $result->getPageCount()) {
            $body .= ' <span class="text-muted">' . $addon->i18n('search_page_of', $result->getPage(), $result->getPageCount()) . '</span>';
        }
        $body .= '</p><div class="list-group">';

        foreach ($result as $hit) {
            $title = '' !== $hit->title ? $hit->getHighlightedTitle() : rex_escape($hit->itemId);
            $link = '' !== $hit->url
                ? '<a href="' . rex_escape($hit->url) . '" target="_blank" rel="noopener">' . $title . '</a>'
                : $title;
            $sourceName = $sourceNames[$hit->sourceId] ?? ('#' . $hit->sourceId);
            $body .= '<div class="list-group-item">'
                . '<h4 class="list-group-item-heading">' . $link . '</h4>'
                . '<p class="list-group-item-text">' . $hit->getHighlightedSnippet() . '</p>'
                . '<small class="text-muted">' . rex_escape($addon->i18n('status_result_meta', $sourceName, $hit->namespace, $hit->type, $hit->itemId, number_format($hit->score, 2))) . '</small>'
                . '</div>';
        }
        $body .= '</div>';

        if (1 < $result->getPageCount()) {
            $base = rex_url::currentBackendPage(['q' => $query, 'sources' => $selectedSourceIds]);
            $body .= '<ul class="pagination">';
            for ($p = 1; $p <= min(20, $result->getPageCount()); ++$p) {
                $body .= '<li class="' . ($p === $result->getPage() ? 'active' : '') . '">'
                    . '<a href="' . rex_escape($base . '&p=' . $p) . '">' . $p . '</a></li>';
            }
            $body .= '</ul>';
        }
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
