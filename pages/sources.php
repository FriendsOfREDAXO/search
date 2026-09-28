<?php

use FriendsOfRedaxo\Search\Backend\ConfigFormRenderer;
use FriendsOfRedaxo\Search\Index\Indexer;
use FriendsOfRedaxo\Search\Index\IndexFilterRegistry;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Source\SourceTypeRegistry;

$addon = rex_addon::get('search');
$csrf = rex_csrf_token::factory('search_sources');
$repository = new SourceRepository();

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');

// ---------- Meldungen aus Redirects ----------
$msg = rex_request('msg', 'string');
if ('saved' === $msg) {
    echo rex_view::success($addon->i18n('source_saved'));
} elseif ('deleted' === $msg) {
    echo rex_view::success($addon->i18n('source_deleted'));
} elseif ('saved_reindexed' === $msg) {
    echo rex_view::success($addon->i18n('source_saved_reindexed', rex_request('items', 'int'), rex_request('written', 'int'), rex_request('skipped', 'int')));
} elseif ('saved_cleared' === $msg) {
    echo rex_view::success($addon->i18n('source_saved_cleared'));
} elseif ('reindexed' === $msg) {
    echo rex_view::success($addon->i18n('source_reindexed', rex_request('items', 'int'), rex_request('written', 'int'), rex_request('deleted', 'int')));
}

// ---------- Aktionen ----------
if ($id > 0 && in_array($func, ['delete', 'reindex', 'status'], true)) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $source = $repository->find($id);
        if (null === $source) {
            echo rex_view::error('Quelle nicht gefunden.');
        } elseif ('delete' === $func) {
            $repository->delete($id);
            rex_response::sendRedirect(rex_url::currentBackendPage(['msg' => 'deleted'], false));
        } elseif ('status' === $func) {
            $source->status = !$source->status;
            $repository->save($source);
            rex_response::sendRedirect(rex_url::currentBackendPage([], false));
        } else {
            try {
                $result = (new Indexer())->rebuild($source);
                rex_response::sendRedirect(rex_url::currentBackendPage([
                    'msg' => 'reindexed',
                    'items' => $result->items,
                    'written' => $result->documentsWritten,
                    'deleted' => $result->documentsDeleted,
                ], false));
            } catch (Throwable $exception) {
                echo rex_view::error(rex_escape($exception->getMessage()));
            }
        }
    }
    $func = '';
}

// ---------- Formular ----------
if ('add' === $func || 'edit' === $func) {
    $source = 'edit' === $func ? $repository->find($id) : null;
    if ('edit' === $func && null === $source) {
        echo rex_view::error('Quelle nicht gefunden.');
        $func = '';
    }
}

if ('add' === $func || 'edit' === $func) {
    $typeKey = $source?->typeKey ?? rex_request('type_key', 'string');
    $type = SourceTypeRegistry::get($typeKey);
    $types = SourceTypeRegistry::all();

    if (null !== $source && null === $type) {
        echo rex_view::warning($addon->i18n('source_type_missing', $typeKey));
    }

    if (null === $type) {
        // Schritt 1: Quellentyp waehlen
        if ([] === $types) {
            echo rex_view::warning($addon->i18n('source_no_types'));
        } else {
            $select = new rex_select();
            $select->setName('type_key');
            $select->setId('search-type-key');
            $select->setAttribute('class', 'form-control');
            foreach ($types as $key => $candidate) {
                $select->addOption($candidate->getLabel() . ' [' . $key . ']', $key);
            }

            $descriptions = '<ul class="list-unstyled">';
            foreach ($types as $key => $candidate) {
                $descriptions .= '<li><strong>' . rex_escape($candidate->getLabel()) . '</strong>'
                    . ('' !== $candidate->getDescription() ? ' – ' . rex_escape($candidate->getDescription()) : '') . '</li>';
            }
            $descriptions .= '</ul>';

            $fragment = new rex_fragment();
            $fragment->setVar('elements', [
                ['label' => '<label for="search-type-key">' . $addon->i18n('source_type') . '</label>', 'field' => $select->get(), 'note' => $descriptions],
            ], false);
            $body = $fragment->parse('core/form/form.php');

            $fragment = new rex_fragment();
            $fragment->setVar('elements', [
                ['field' => '<button class="btn btn-save rex-form-aligned" type="submit">' . $addon->i18n('next') . '</button>'],
                ['field' => '<a class="btn btn-abort" href="' . rex_url::currentBackendPage() . '">' . $addon->i18n('abort') . '</a>'],
            ], false);
            $buttons = $fragment->parse('core/form/submit.php');

            $fragment = new rex_fragment();
            $fragment->setVar('class', 'edit', false);
            $fragment->setVar('title', $addon->i18n('source_choose_type'));
            $fragment->setVar('body', $body, false);
            $fragment->setVar('buttons', $buttons, false);

            echo '<form action="' . rex_url::currentBackendPage(['func' => 'add']) . '" method="get">'
                . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
                . '<input type="hidden" name="func" value="add">'
                . $fragment->parse('core/page/section.php')
                . '</form>';
        }
    } else {
        // Schritt 2: Quelle konfigurieren
        $data = [
            'name' => $source?->name ?? '',
            'status' => $source?->status ?? true,
            'config' => $source?->config ?? [],
            'filters' => $source?->filters ?? [],
        ];
        if ([] === $data['config']) {
            foreach ($type->getConfigFields([]) as $field) {
                if (null !== $field->default) {
                    $data['config'][$field->name] = $field->default;
                }
            }
        }

        $errors = [];
        $isSave = rex_post('save', 'bool');
        $isReload = rex_post('reload', 'bool');

        if ($isSave || $isReload) {
            if (!$csrf->isValid()) {
                echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
                $isSave = false;
            }
            $data['name'] = trim(rex_post('name', 'string'));
            $data['status'] = rex_post('status', 'bool');
            $data['config'] = $type->normalizeConfig(rex_post('config', 'array', []));
            $rawFilterConfig = rex_post('filter_config', 'array', []);
            $data['filters'] = [];
            foreach (rex_post('filters', 'array', []) as $filterKey) {
                if (!is_string($filterKey)) {
                    continue;
                }
                $filter = IndexFilterRegistry::all()[$filterKey] ?? null;
                if (null === $filter) {
                    continue;
                }
                $raw = $rawFilterConfig[$filterKey] ?? [];
                $data['filters'][$filterKey] = $filter->normalizeConfig(is_array($raw) ? $raw : []);
            }
        }

        if ($isSave) {
            if ('' === $data['name']) {
                $errors['name'] = $addon->i18n('source_required');
            }
            foreach ($type->getConfigFields($data['config']) as $field) {
                if ($field->required && $field->isEmpty($data['config'][$field->name] ?? null)) {
                    $errors['config.' . $field->name] = $addon->i18n('source_required');
                }
            }
            foreach ($type->validateConfig($data['config']) as $fieldName => $error) {
                $errors['config.' . $fieldName] ??= $error;
            }

            if ([] === $errors) {
                $saved = $source ?? new Source(null, '', $typeKey);
                // Geaenderte Konfiguration oder Filter machen den bestehenden Index ungueltig
                $configChanged = null === $source
                    || $source->config !== $data['config']
                    || $source->filters !== $data['filters'];
                $saved->name = $data['name'];
                $saved->status = $data['status'];
                $saved->config = $data['config'];
                $saved->filters = $data['filters'];
                $repository->save($saved);

                if (!$configChanged) {
                    rex_response::sendRedirect(rex_url::currentBackendPage(['msg' => 'saved'], false));
                }

                (new IndexRepository())->deleteBySource($saved->requireId());
                if (!$saved->status) {
                    $repository->updateStats($saved->requireId(), 0, new DateTimeImmutable());
                    rex_response::sendRedirect(rex_url::currentBackendPage(['msg' => 'saved_cleared'], false));
                }

                try {
                    $result = (new Indexer())->rebuild($saved);
                    rex_response::sendRedirect(rex_url::currentBackendPage([
                        'msg' => 'saved_reindexed',
                        'items' => $result->items,
                        'written' => $result->documentsWritten,
                        'skipped' => $result->skipped,
                    ], false));
                } catch (Throwable $exception) {
                    echo rex_view::error(rex_escape($exception->getMessage()));
                }
            }
        }

        $elements = [];
        $elements[] = [
            'label' => '<label for="search-name">' . $addon->i18n('source_name') . '</label>',
            'field' => '<input class="form-control" type="text" id="search-name" name="name" value="' . rex_escape($data['name']) . '">',
            'required' => true,
            'error' => $errors['name'] ?? '',
        ];
        $elements[] = [
            'label' => '<label for="search-type">' . $addon->i18n('source_type') . '</label>',
            'field' => '<p class="form-control-static" id="search-type">' . rex_escape($type->getLabel()) . ' <code>' . rex_escape($typeKey) . '</code></p>',
        ];
        $checkbox = new rex_fragment();
        $checkbox->setVar('elements', [[
            'label' => '<label for="search-status">' . $addon->i18n('source_status') . '</label>',
            'field' => '<input type="checkbox" id="search-status" name="status" value="1"' . ($data['status'] ? ' checked' : '') . '>',
            'note' => $addon->i18n('source_status_notice'),
        ]], false);
        $elements[] = ['field' => $checkbox->parse('core/form/checkbox.php')];

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $elements, false);
        $body = $fragment->parse('core/form/form.php');

        $configErrors = [];
        foreach ($errors as $key => $message) {
            if (str_starts_with($key, 'config.')) {
                $configErrors[substr($key, 7)] = $message;
            }
        }

        $body .= '<fieldset><legend>' . $addon->i18n('source_config') . '</legend>'
            . ConfigFormRenderer::render($type->getConfigFields($data['config']), $data['config'], 'config', $configErrors)
            . '</fieldset>';

        // Indexfilter: welche Datensaetze beim Aufbau ueberhaupt aufgenommen werden
        $probe = new Source($source?->id, $data['name'], $typeKey, $data['config'], $data['filters'], $data['status']);
        $availableFilters = IndexFilterRegistry::available($probe);
        if ([] !== $availableFilters) {
            $filterSelect = new rex_select();
            $filterSelect->setName('filters[]');
            $filterSelect->setId('search-filters');
            $filterSelect->setAttribute('class', 'form-control');
            $filterSelect->setAttribute('data-search-reload', '1');
            $filterSelect->setMultiple(true);
            $filterSelect->setSize(min(8, max(3, count($availableFilters))));
            $descriptions = [];
            foreach ($availableFilters as $key => $filter) {
                $filterSelect->addOption($filter->getLabel(), $key);
                if ('' !== $filter->getDescription()) {
                    $descriptions[] = '<strong>' . rex_escape($filter->getLabel()) . '</strong> – ' . rex_escape($filter->getDescription());
                }
            }
            $filterSelect->setSelected(array_keys($data['filters']));

            $fragment = new rex_fragment();
            $fragment->setVar('elements', [[
                'label' => '<label for="search-filters">' . $addon->i18n('source_filters') . '</label>',
                'field' => $filterSelect->get(),
                'note' => $addon->i18n('source_filters_notice')
                    . ([] === $descriptions ? '' : '<br>' . implode('<br>', $descriptions)),
            ]], false);
            $body .= '<fieldset><legend>' . $addon->i18n('source_filters') . '</legend>' . $fragment->parse('core/form/form.php') . '</fieldset>';

            // Einstellungen je gewaehltem Filter, erscheinen nach dem Aktualisieren des Formulars
            foreach ($data['filters'] as $key => $filterConfig) {
                $filter = $availableFilters[$key] ?? null;
                if (null === $filter) {
                    continue;
                }
                $fields = $filter->getConfigFields($filterConfig);
                if ([] === $fields) {
                    continue;
                }

                $filterErrors = [];
                foreach ($errors as $errorKey => $message) {
                    if (str_starts_with($errorKey, 'filter.' . $key . '.')) {
                        $filterErrors[substr($errorKey, strlen('filter.' . $key . '.'))] = $message;
                    }
                }

                $body .= '<fieldset><legend>' . rex_escape($filter->getLabel()) . '</legend>'
                    . ConfigFormRenderer::render($fields, $filterConfig, 'filter_config[' . $key . ']', $filterErrors)
                    . '</fieldset>';
            }
        }

        $fragment = new rex_fragment();
        $fragment->setVar('elements', [
            ['field' => '<button class="btn btn-save rex-form-aligned" type="submit" name="save" value="1">' . $addon->i18n('save') . '</button>'],
            ['field' => '<button class="btn btn-default" type="submit" name="reload" value="1">' . $addon->i18n('source_reload') . '</button>'],
            ['field' => '<a class="btn btn-abort" href="' . rex_url::currentBackendPage() . '">' . $addon->i18n('abort') . '</a>'],
        ], false);
        $buttons = $fragment->parse('core/form/submit.php');

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'edit', false);
        $fragment->setVar('title', 'edit' === $func ? $addon->i18n('source_edit') : $addon->i18n('source_add'));
        $fragment->setVar('body', $body, false);
        $fragment->setVar('buttons', $buttons, false);

        echo '<form action="' . rex_url::currentBackendPage(['func' => $func, 'id' => $id, 'type_key' => $typeKey]) . '" method="post">'
            . $csrf->getHiddenField()
            . $fragment->parse('core/page/section.php')
            . '</form>';
    }

    return;
}

// ---------- Alle aktiven Quellen neu indizieren ----------
if (rex_post('rebuild_all', 'bool')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $indexer = new Indexer();
        $count = 0;
        foreach ($repository->findAll(true) as $source) {
            try {
                $indexer->rebuild($source);
                ++$count;
            } catch (Throwable $exception) {
                echo rex_view::error(rex_escape($source->name . ': ' . $exception->getMessage()));
            }
        }
        echo rex_view::success($addon->i18n('status_rebuilt', $count));
    }
}

// ---------- Liste ----------
$indexRepository = new IndexRepository();
/** @var array<int, Source> $sourcesById */
$sourcesById = [];
foreach ($repository->findAll() as $source) {
    $sourcesById[$source->requireId()] = $source;
}

$list = rex_list::factory('SELECT id, name, type_key, status, item_count, last_indexed_at FROM ' . SourceRepository::table() . ' ORDER BY name', 100, 'search_sources');
$list->addTableAttribute('class', 'table-striped table-hover');
$list->setNoRowsMessage($addon->i18n('source_none'));

$thIcon = '<a class="rex-link-expanded" href="' . $list->getUrl(['func' => 'add']) . '" title="' . $addon->i18n('source_add') . '"><i class="rex-icon rex-icon-add"></i></a>';
$list->addColumn($thIcon, '<i class="rex-icon fa-database"></i>', 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
$list->setColumnParams($thIcon, ['func' => 'edit', 'id' => '###id###']);

$list->setColumnLabel('id', rex_i18n::msg('id'));
$list->setColumnLayout('id', ['<th class="rex-table-id">###VALUE###</th>', '<td class="rex-table-id" data-title="' . rex_i18n::msg('id') . '">###VALUE###</td>']);

$list->setColumnLabel('name', $addon->i18n('source_name'));
$list->setColumnParams('name', ['func' => 'edit', 'id' => '###id###']);

$list->setColumnLabel('type_key', $addon->i18n('source_type'));
$list->setColumnFormat('type_key', 'custom', static function (array $params) use ($sourcesById) {
    /** @var rex_list $list */
    $list = $params['list'];
    $source = $sourcesById[(int) $list->getValue('id')] ?? null;
    $type = $source?->getType();
    if (null === $source || null === $type) {
        return '<span class="text-danger">' . rex_escape((string) $params['value']) . '</span>';
    }

    return rex_escape($type->getLabel())
        . '<br><small><code>' . rex_escape($type->getNamespace()) . '</code> / <code>' . rex_escape($type->getType($source)) . '</code></small>';
});

$list->addColumn('index_id_pattern', '', 4);
$list->setColumnLabel('index_id_pattern', $addon->i18n('status_index_id_pattern'));
$list->setColumnFormat('index_id_pattern', 'custom', static function (array $params) use ($sourcesById) {
    /** @var rex_list $list */
    $list = $params['list'];
    $source = $sourcesById[(int) $list->getValue('id')] ?? null;
    $type = $source?->getType();

    return null === $source || null === $type ? '' : '<code>' . rex_escape($type->getIndexIdPattern($source)) . '</code>';
});

$list->addColumn('description', '', 5);
$list->setColumnLabel('description', $addon->i18n('source_description'));
$list->setColumnFormat('description', 'custom', static function (array $params) use ($sourcesById) {
    /** @var rex_list $list */
    $list = $params['list'];
    $source = $sourcesById[(int) $list->getValue('id')] ?? null;
    $type = $source?->getType();

    return null === $source || null === $type ? '' : rex_escape($type->describe($source));
});

$list->addColumn('filters', '', 6);
$list->setColumnLabel('filters', $addon->i18n('source_filters'));
$list->setColumnFormat('filters', 'custom', static function (array $params) use ($addon, $sourcesById) {
    /** @var rex_list $list */
    $list = $params['list'];
    $source = $sourcesById[(int) $list->getValue('id')] ?? null;
    if (null === $source) {
        return '';
    }

    $labels = [];
    foreach (IndexFilterRegistry::forSource($source) as $filter) {
        $labels[] = rex_escape($filter->getLabel());
    }

    return [] === $labels
        ? '<span class="text-muted">' . $addon->i18n('source_filters_none') . '</span>'
        : implode('<br>', $labels);
});

$list->setColumnLabel('status', $addon->i18n('source_status'));
$list->setColumnFormat('status', 'custom', static function (array $params) use ($addon) {
    return $params['value']
        ? '<span class="rex-online"><i class="rex-icon rex-icon-online"></i> ' . $addon->i18n('active') . '</span>'
        : '<span class="rex-offline"><i class="rex-icon rex-icon-offline"></i> ' . $addon->i18n('inactive') . '</span>';
});
$list->setColumnParams('status', ['func' => 'status', 'id' => '###id###'] + $csrf->getUrlParams());

$list->setColumnLabel('item_count', $addon->i18n('source_documents'));
$list->setColumnLayout('item_count', ['<th class="text-right">###VALUE###</th>', '<td class="text-right">###VALUE###</td>']);
$list->setColumnFormat('item_count', 'custom', static function (array $params) use ($indexRepository) {
    /** @var rex_list $list */
    $list = $params['list'];

    return (int) $params['value'] . ' / ' . $indexRepository->countBySource((int) $list->getValue('id'));
});

$list->setColumnLabel('last_indexed_at', $addon->i18n('source_last_indexed'));
$list->setColumnFormat('last_indexed_at', 'custom', static function (array $params) use ($addon) {
    if (empty($params['value'])) {
        return $addon->i18n('source_never');
    }

    return '<span class="text-nowrap">' . date('d.m.y H:i', strtotime((string) $params['value'])) . '</span>';
});

$list->addColumn('actions', '', -1, ['<th class="rex-table-action">' . $addon->i18n('source_functions') . '</th>', '<td class="rex-table-action">###VALUE###</td>']);
$list->setColumnFormat('actions', 'custom', static function (array $params) use ($list, $addon, $csrf, $sourcesById) {
    $id = (int) $list->getValue('id');
    $source = $sourcesById[$id] ?? null;

    $links = [
        '<a href="' . $list->getUrl(['func' => 'edit', 'id' => $id]) . '"><i class="rex-icon rex-icon-editmode"></i> ' . $addon->i18n('source_edit') . '</a>',
        '<a href="' . $list->getUrl(['func' => 'reindex', 'id' => $id] + $csrf->getUrlParams()) . '"><i class="rex-icon fa-refresh"></i> ' . $addon->i18n('source_reindex') . '</a>',
        '<a href="' . $list->getUrl(['func' => 'status', 'id' => $id] + $csrf->getUrlParams()) . '">'
            . (null !== $source && $source->status
                ? '<i class="rex-icon rex-icon-offline"></i> ' . $addon->i18n('source_deactivate')
                : '<i class="rex-icon rex-icon-online"></i> ' . $addon->i18n('source_activate'))
            . '</a>',
        '<a href="' . $list->getUrl(['func' => 'delete', 'id' => $id] + $csrf->getUrlParams()) . '" data-confirm="' . rex_escape($addon->i18n('source_delete_confirm')) . '"><i class="rex-icon rex-icon-delete"></i> ' . $addon->i18n('source_delete') . '</a>',
    ];

    // Gleicher Aufbau wie die Aktionsschaltflaeche der YForm-Datenansicht
    return '<div class="dropdown search-dropdown">'
        . '<button class="btn btn-xs btn-default dropdown-toggle" type="button" data-toggle="dropdown">' . $addon->i18n('source_action_button') . ' <span class="caret"></span></button>'
        . '<ul class="dropdown-menu dropdown-menu-right"><li>' . implode('</li><li>', $links) . '</li></ul>'
        . '</div>';
});

$fragment = new rex_fragment();
$fragment->setVar('elements', [
    ['field' => '<button class="btn btn-primary" type="submit" name="rebuild_all" value="1"><i class="rex-icon fa-refresh"></i> ' . $addon->i18n('status_rebuild_all') . '</button>'],
], false);
$buttons = $fragment->parse('core/form/submit.php');

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('sources') . ' <small>' . $addon->i18n('status_total') . ': ' . $indexRepository->countAll() . ' ' . $addon->i18n('source_documents') . '</small>', false);
$fragment->setVar('content', $list->get(), false);
$fragment->setVar('buttons', $buttons, false);
echo '<form action="' . rex_url::currentBackendPage() . '" method="post">' . $csrf->getHiddenField() . $fragment->parse('core/page/section.php') . '</form>';
