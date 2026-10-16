<?php

rex_sql_table::get(rex::getTable('search_source'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('type_key', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('config', 'json', true))
    ->ensureColumn(new rex_sql_column('filters', 'json', true))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('last_indexed_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('item_count', 'int(11)', false, '0'))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('type_key', ['type_key']))
    ->removeColumn('priority')
    ->ensure();

rex_sql_table::get(rex::getTable('search_index'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('source_id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('namespace', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('item_id', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('index_id', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('title', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('content', 'mediumtext', true))
    ->ensureColumn(new rex_sql_column('content_raw', 'mediumtext', true))
    ->ensureColumn(new rex_sql_column('content_raw_format', 'varchar(16)', true))
    ->ensureColumn(new rex_sql_column('url', 'varchar(1024)', false, ''))
    ->ensureColumn(new rex_sql_column('clang_id', 'int(11)', true))
    // Die Sprache des Dokuments, aus der Konfiguration seiner Quelle uebernommen.
    // Leer heisst "nicht angegeben" und nicht "keine": dann wird nicht analysiert.
    ->ensureColumn(new rex_sql_column('language', 'varchar(5)', false, ''))
    ->ensureColumn(new rex_sql_column('meta', 'json', true))
    ->ensureColumn(new rex_sql_column('content_hash', 'char(40)', false, ''))
    ->ensureColumn(new rex_sql_column('updated_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('indexed_at', 'datetime'))
    ->ensureIndex(new rex_sql_index('source_index', ['source_id', 'index_id'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('source_item', ['source_id', 'item_id']))
    ->ensureIndex(new rex_sql_index('namespace_type', ['namespace', 'type']))
    ->ensureIndex(new rex_sql_index('fulltext_content', ['title', 'content'], rex_sql_index::FULLTEXT))
    ->ensureIndex(new rex_sql_index('fulltext_title', ['title'], rex_sql_index::FULLTEXT))
    ->ensure();

// Der Wortschatz des Index, Grundlage fuer Teiltreffer und Tippfehlerkorrektur.
//
// word ist varbinary, nicht varchar: unter utf8mb4_general_ci gelten "gross" und "groß" als
// dasselbe, ein eindeutiger Schluessel darauf wuerde eines der beiden verschlucken. Verglichen
// wird ohnehin ueber word_folded, angezeigt wird word.
//
// Getrennt wird nach Quelle und Sprache, nicht nach clang: mehrere clangs koennen dieselbe
// Sprache sein (de und de-CH), und Medien- und YForm-Quellen haben gar keinen clang. Die
// Sprache steht denormalisiert in der Zeile, damit die Suche sie ohne Verbund filtern kann.
$wordTable = rex_sql_table::get(rex::getTable('search_word'));

// Umstieg von clang auf Sprache: der bisherige Schluessel trennte nach clang, der neue
// nicht mehr. Dieselbe Quelle kann dasselbe Wort also doppelt enthalten -- einmal je
// Sprachfassung --, und der neue eindeutige Schluessel liesse sich gar nicht anlegen.
// Die Wortliste ist aus dem Index abgeleitet und in Sekunden wieder da, deshalb wird sie
// geleert statt zusammengefasst: ohne Sprache waere sie ohnehin unvollstaendig, und der
// Neuaufbau laeuft ueber "bin/console search:words" oder beim naechsten Indexlauf.
if ($wordTable->hasColumn('clang_id')) {
    rex_sql::factory()->setQuery('TRUNCATE TABLE ' . rex::getTable('search_word'));
}

$wordTable
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('word', 'varbinary(96)'))
    ->ensureColumn(new rex_sql_column('word_folded', 'varchar(96)'))
    ->ensureColumn(new rex_sql_column('word_length', 'tinyint(3) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('source_id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('language', 'varchar(5)', false, ''))
    ->ensureColumn(new rex_sql_column('docs', 'int(11)', false, '0'))
    ->removeIndex('source_clang_word')
    ->removeColumn('clang_id')
    ->removeColumn('word_rev')
    ->ensureIndex(new rex_sql_index('source_word', ['source_id', 'word'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('folded', ['word_folded']))
    ->ensureIndex(new rex_sql_index('length_source', ['word_length', 'source_id']))
    ->ensure();

// Nachschlageformen zu den Woertern: je Wort sein Stamm und der Stamm jedes Teils, in den
// es sich zerlegen laesst, siehe FriendsOfRedaxo\Search\Analysis\Analyzer.
//
// Eigene Tabelle und nicht eine Spalte an rex_search_word, weil es je Wort mehrere Formen
// gibt: "Burgruine" steht unter "burgruin", "burg" und "ruin". Die Suche schlaegt mit der
// Form des eingegebenen Wortes nach und bekommt so Beugung und Zusammensetzung in einer
// Abfrage. word und docs stehen denormalisiert daneben, damit dafuer kein Verbund noetig ist.
rex_sql_table::get(rex::getTable('search_word_form'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('form', 'varchar(96)'))
    ->ensureColumn(new rex_sql_column('word', 'varbinary(96)'))
    ->ensureColumn(new rex_sql_column('source_id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('language', 'varchar(5)', false, ''))
    ->ensureColumn(new rex_sql_column('docs', 'int(11)', false, '0'))
    ->ensureIndex(new rex_sql_index('source_form_word', ['source_id', 'form', 'word'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('form', ['form']))
    ->ensure();
