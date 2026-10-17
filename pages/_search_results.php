<?php

/**
 * Der Ergebnisteil der Suchseite.
 *
 * Eigene Datei, weil die Seite sonst zwei Dinge auf einmal taete: ein Formular bauen und
 * drei verschiedene Ergebnisdarstellungen. Erwartet aus pages/search.php: $addon,
 * $query, $mode, $page, $perPage, $selectedSourceIds, $selectedLanguage, $sourceNames,
 * $retriever, $url.
 *
 * Die Treffer stehen in einer Tabelle im Markup des Kerns -- dieselben Klassen wie die
 * Quellenuebersicht, dieselbe Blaetterleiste. Vorher war es eine Folge von
 * article-Bloecken mit eigenem Stylesheet; neben der Quellenliste sah das nach einer
 * anderen Anwendung aus, obwohl beides Listen sind.
 *
 * @var rex_addon $addon
 * @var string $query
 * @var string $mode
 * @var int $page
 * @var int $perPage
 * @var list<int> $selectedSourceIds
 * @var string $selectedLanguage
 * @var array<int, string> $sourceNames
 * @var Retriever $retriever
 * @var float $minSimilarity
 * @var callable(array<string, mixed>): string $url
 */

use FriendsOfRedaxo\Search\Language;
use FriendsOfRedaxo\Search\Passage\PassageQuery;
use FriendsOfRedaxo\Search\Passage\Retriever;
use FriendsOfRedaxo\Search\Query\Searcher;
use FriendsOfRedaxo\Search\Query\SearchQuery;
use FriendsOfRedaxo\Search\Query\SearchTerms;

/**
 * Blaetterleiste im Markup des Kerns (core/navigations/pagination.php).
 *
 * Nachgebaut statt uebernommen, weil das Fragment einen rex_pager erwartet und die Suche
 * keinen hat -- ihre Seitenzahl steckt in der URL, nicht in einer SQL-Abfrage. Mit einem
 * Fenster statt aller Seiten: bei achtzig Seiten waere die Leiste laenger als das Ergebnis.
 */
$pagination = static function (int $current, int $pages, string $count = '') use ($addon, $url): string {
    if (2 > $pages) {
        return '';
    }

    $window = 7;
    $from = max(1, $current - intdiv($window, 2));
    $to = min($pages, $from + $window - 1);
    $from = max(1, $to - $window + 1);

    $step = static function (string $class, string $icon, string $title, int $target, bool $disabled) use ($url): string {
        if ($disabled) {
            return '<li class="' . $class . ' disabled"><span><i class="rex-icon ' . $icon . '"></i></span></li>';
        }

        return '<li class="' . $class . '"><a href="' . $url(['p' => $target]) . '" title="' . rex_escape($title) . '">'
            . '<i class="rex-icon ' . $icon . '"></i><span class="sr-only">' . rex_escape($title) . '</span></a></li>';
    };
    $number = static function (int $target, int $current) use ($url): string {
        return '<li class="rex-page' . ($target === $current ? ' active' : '') . '">'
            . '<a href="' . $url(['p' => $target]) . '">' . $target . '</a></li>';
    };

    $items = $step('rex-prev', 'rex-icon-previous', $addon->i18n('search_page_previous'), max(1, $current - 1), 1 === $current);
    if (1 < $from) {
        $items .= $number(1, $current) . '<li class="disabled"><span>…</span></li>';
    }
    for ($p = $from; $p <= $to; ++$p) {
        $items .= $number($p, $current);
    }
    if ($to < $pages) {
        $items .= '<li class="disabled"><span>…</span></li>' . $number($pages, $current);
    }
    $items .= $step('rex-next', 'rex-icon-next', $addon->i18n('search_page_next'), min($pages, $current + 1), $current === $pages);

    return '<nav class="rex-nav-pagination"><ul class="pagination">' . $items . '</ul>'
        . ('' === $count ? '' : '<div class="rex-pagination-count">' . $count . '</div>')
        . '</nav>';
};

/** Das Symbol vor der Zeile, wie in der Quellenliste -- nur hier nach Bereich. */
$icon = static function (string $namespace): string {
    $class = match ($namespace) {
        'article' => 'fa-file-text-o',
        'media' => 'fa-file-o',
        default => 'fa-database',
    };

    return '<i class="rex-icon ' . $class . '"></i>';
};

/** Namensraum und Unterart untereinander, in derselben Schreibweise wie in der Quellenliste. */
$origin = static function (int $sourceId, string $namespace, string $type) use ($sourceNames): string {
    return rex_escape($sourceNames[$sourceId] ?? ('#' . $sourceId))
        . '<br><small class="text-muted text-nowrap"><code>' . rex_escape($namespace) . '</code> / <code>' . rex_escape($type) . '</code></small>';
};

/** Der Aufklapper mit allem, was die API zu dieser Zeile liefert. */
$details = static function (array $data) use ($addon): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return '<details class="search-details"><summary>' . $addon->i18n('search_hit_details') . '</summary>'
        . '<pre><code>' . rex_escape((string) $json) . '</code></pre></details>';
};

$body = '';
$content = '';
$footer = '';
$subtitle = '';

if (SEARCH_MODE_PASSAGES === $mode) {
    // Genau das, was ein fremdes AddOn ueber die Passagen-API bekaeme -- ohne Volltext,
    // ohne Verschmelzung. Gedacht, um Schwelle und Zerlegung zu beurteilen.
    $passageQuery = PassageQuery::create($query)->withMaxPassages(20)->withMaxCharacters(100000)->withMinSimilarity($minSimilarity);
    if ([] !== $selectedSourceIds) {
        $passageQuery = $passageQuery->withSourceIds(...$selectedSourceIds);
    }
    if (Language::UNKNOWN !== $selectedLanguage) {
        $passageQuery = $passageQuery->withLanguages($selectedLanguage);
    }

    $passages = $retriever->retrieve($passageQuery);

    $body .= rex_view::info($addon->i18n('search_passages_intro', number_format($passageQuery->getMinSimilarity(), 2, ',', '')));

    if ($passages->isEmpty()) {
        $body .= '<p>' . $addon->i18n('search_passages_none', number_format($passages->bestSimilarity, 3, ',', '')) . '</p>';
    } else {
        $subtitle = $addon->i18n('search_passages_found', $passages->count(), $passages->getCharacters());

        $rows = '';
        $no = 0;
        foreach ($passages as $passage) {
            ++$no;
            $label = rex_escape($passage->getLabel());
            $rows .= '<tr>'
                . '<td class="rex-table-icon">' . $icon($passage->namespace) . '</td>'
                . '<td class="rex-table-id">' . $no . '</td>'
                . '<td class="search-cell-text">'
                . '<div class="search-hit-title">'
                . ('' !== $passage->url ? '<a href="' . rex_escape($passage->url) . '" target="_blank" rel="noopener">' . $label . '</a>' : $label)
                . '</div>'
                . '<div class="search-hit-text">' . nl2br(rex_escape($passage->text)) . '</div>'
                . '<div class="search-hit-meta"><code>' . rex_escape($passage->itemId) . '</code> · '
                . $addon->i18n('search_passage_characters', $passage->getCharacters()) . '</div>'
                . $details($passage->toArray())
                . '</td>'
                . '<td>' . $origin($passage->sourceId, $passage->namespace, $passage->type) . '</td>'
                . '<td class="text-right text-nowrap">' . number_format($passage->similarity, 3, ',', '') . '</td>'
                . '</tr>';
        }

        $content = '<table class="table table-striped table-hover search-table">'
            . '<thead><tr>'
            . '<th class="rex-table-icon"></th>'
            . '<th class="rex-table-id">' . $addon->i18n('search_hit_no') . '</th>'
            . '<th class="search-col-text">' . $addon->i18n('search_passage_section') . '</th>'
            . '<th>' . $addon->i18n('search_hit_source') . '</th>'
            . '<th class="text-right">' . $addon->i18n('search_passage_similarity') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }
} else {
    $search = SearchQuery::create($query)
        ->withSourceIds(...$selectedSourceIds)
        ->withFacets('namespace', 'type', 'language', 'clang_id')
        ->withPage($page, $perPage);
    if (Language::UNKNOWN !== $selectedLanguage) {
        $search = $search->withLanguages($selectedLanguage);
    }
    if (SEARCH_MODE_HYBRID === $mode) {
        $search = $search->withSemantic(true, $minSimilarity);
    }
    $searcher = new Searcher();
    $result = $searcher->search($search);

    // Eine Seitenzahl hinter der letzten -- aus einem alten Lesezeichen oder weil die
    // Trefferzahl zwischenzeitlich gesunken ist -- zeigte sonst "keine Treffer", obwohl
    // es welche gibt. Der zweite Lauf kostet nur in genau diesem Fall etwas.
    if (0 < $result->total && $page > $result->getPageCount()) {
        $page = $result->getPageCount();
        $result = $searcher->search($search->withPage($page, $perPage));
    }

    // Weggelassene Woerter benennen: "keine Treffer" waere hier die falsche Auskunft.
    $ignored = [];
    foreach ($result->getIgnoredWords() as $word => $reason) {
        // Kein Markup in die Argumente: i18n() maskiert sie, das Markup waere sonst sichtbar.
        $ignored[] = $word . ' (' . $addon->i18n(SearchTerms::REASON_STOPWORD === $reason ? 'search_word_stopword' : 'search_word_too_short') . ')';
    }
    if ([] !== $ignored) {
        $body .= rex_view::warning($addon->i18n('search_words_ignored', implode(', ', $ignored)));
    }

    // Was mit der Anfrage geschah, ist eine Randnotiz zum Ergebnis -- kein Hinweiskasten
    // ueber die ganze Breite. Also normaler Text, und fett nur die Woerter, um die es
    // geht. rawMsg() statt i18n(), weil i18n() die Argumente maskiert und das <strong>
    // sonst als Text dastuende.
    $words = static function (array $words): string {
        return implode(', ', array_map(static fn (string $word): string => '<strong>' . rex_escape($word) . '</strong>', $words));
    };

    if ($result->isCorrected()) {
        $body .= '<p class="search-note">' . rex_i18n::rawMsg(
            'search_words_corrected',
            $words(array_keys($result->corrections)),
            $words(array_values($result->corrections)),
        ) . '</p>';
    }

    $expanded = [];
    foreach ($result->getExpansions() as $forms) {
        foreach ($forms as $form) {
            $expanded[$form] = true;
        }
    }
    if ([] !== $expanded) {
        $body .= '<p class="search-note">' . rex_i18n::rawMsg('search_words_expanded', $words(array_keys($expanded))) . '</p>';
    }

    if ($result->isEmpty()) {
        $body .= '<p>' . $addon->i18n('status_no_results') . '</p>';
    } else {
        $subtitle = $addon->i18n('search_results', $result->total);
        if (1 < $result->getPageCount()) {
            $subtitle .= ', ' . $addon->i18n('search_page_of', $result->getPage(), $result->getPageCount());
        }
        if (SEARCH_MODE_HYBRID === $mode) {
            $subtitle .= ', ' . $addon->i18n('search_min_similarity_short', number_format($search->getSemanticMinSimilarity(), 2, ',', ''));
        }

        // Verteilung ueber alle Treffer, nicht ueber die angezeigte Seite.
        $clangName = static function (int $id): string {
            $clang = rex_clang::get($id);

            return null === $clang ? ($id > 0 ? '#' . $id : '–') : $clang->getName();
        };
        $facetName = static function (string $facet, string $value) use ($clangName): string {
            if ('clang_id' === $facet) {
                return $clangName((int) $value);
            }

            return 'language' === $facet ? Language::label($value) : $value;
        };
        $verteilung = '';
        foreach ([
            'namespace' => 'search_facet_namespace',
            'type' => 'search_facet_type',
            'language' => 'search_facet_language',
            'clang_id' => 'search_facet_clang',
        ] as $facet => $label) {
            $chips = '';
            foreach ($result->getFacet($facet) as $wert => $anzahl) {
                $chips .= '<span class="search-chip">' . rex_escape($facetName($facet, (string) $wert))
                    . '<span class="search-chip-count">' . $anzahl . '</span></span>';
            }
            if ('' !== $chips) {
                $verteilung .= '<div class="search-facet-row">'
                    . '<div class="search-facet-label">' . $addon->i18n($label) . '</div>'
                    . '<div class="search-facet-values">' . $chips . '</div>'
                    . '</div>';
            }
        }
        if ('' !== $verteilung) {
            $body .= '<div class="search-facets">' . $verteilung . '</div>';
        }

        // Blaettern ueber der Liste: beim Weiterblaettern steht man oben, nicht unten.
        $body .= $pagination($result->getPage(), $result->getPageCount());
        $footer = $pagination($result->getPage(), $result->getPageCount(), $addon->i18n('search_results', $result->total));

        $rows = '';
        $no = ($result->getPage() - 1) * $perPage;
        foreach ($result as $hit) {
            ++$no;
            $title = '' !== $hit->title ? $hit->getHighlightedTitle() : rex_escape($hit->itemId);
            $link = '' !== $hit->url
                ? '<a href="' . rex_escape($hit->url) . '" target="_blank" rel="noopener">' . $title . '</a>'
                : $title;

            // Ueber wie viele Indexzeilen zusammengefasst wurde, gehoert zur Herkunft:
            // ein Artikel liegt einmal je Quelle im Index, die ihn erfasst.
            $source = $origin($hit->sourceId, $hit->namespace, $hit->type);
            if (1 < $hit->countGrouped()) {
                $source .= '<br><small class="text-muted">' . $addon->i18n('search_hit_grouped', $hit->countGrouped()) . '</small>';
            }

            // Die REDAXO-Sprache steht nur darunter, wenn sie etwas anderes sagt als die
            // Sprache der Quelle. Sonst stuende in der Spalte zweimal "Deutsch".
            $label = '' === $hit->language ? '' : Language::label($hit->language);
            $clang = null === $hit->clangId ? '' : $clangName($hit->clangId);
            $language = '' === $label ? '–' : rex_escape($label);
            if ('' !== $clang && !str_contains($label, $clang)) {
                $language .= '<br><small class="text-muted">' . rex_escape($clang) . '</small>';
            }

            // Die Aehnlichkeit steht unter der Relevanz statt als Marke am Titel: sie ist
            // eine Bewertung, und Bewertungen stehen in dieser Tabelle in einer Spalte.
            $relevance = (int) round(100 * $hit->relevance) . ' %';
            if ($hit->isSemantic()) {
                $relevance .= '<br><small class="search-semantic">' . $addon->i18n('search_hit_semantic', number_format($hit->similarity, 3, ',', '')) . '</small>';
            }

            $rows .= '<tr>'
                . '<td class="rex-table-icon">' . $icon($hit->namespace) . '</td>'
                . '<td class="rex-table-id">' . $no . '</td>'
                . '<td class="search-cell-text">'
                . '<div class="search-hit-title">' . $link . '</div>'
                . '<div class="search-hit-text">' . $hit->getHighlightedSnippet() . '</div>'
                . '<div class="search-hit-meta"><code>' . rex_escape($hit->itemId) . '</code></div>'
                . $details($hit->toArray())
                . '</td>'
                . '<td>' . $source . '</td>'
                . '<td class="text-nowrap">' . $language . '</td>'
                . '<td class="text-right text-nowrap">' . $relevance . '</td>'
                . '</tr>';
        }

        $content = '<table class="table table-striped table-hover search-table">'
            . '<thead><tr>'
            . '<th class="rex-table-icon"></th>'
            . '<th class="rex-table-id">' . $addon->i18n('search_hit_no') . '</th>'
            . '<th class="search-col-text">' . $addon->i18n('search_hit_hit') . '</th>'
            . '<th>' . $addon->i18n('search_hit_source') . '</th>'
            . '<th>' . $addon->i18n('search_facet_language') . '</th>'
            . '<th class="text-right">' . $addon->i18n('search_hit_relevance') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }
}

$title = $addon->i18n('search_results_title');
if ('' !== $subtitle) {
    $title .= ' <small>' . rex_escape($subtitle) . '</small>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', $title, false);
if ('' !== $body) {
    $fragment->setVar('body', $body, false);
}
if ('' !== $content) {
    $fragment->setVar('content', $content, false);
}
if ('' !== $footer) {
    $fragment->setVar('footer', $footer, false);
}
echo $fragment->parse('core/page/section.php');
