<?php

rex_sql_table::get(rex::getTable('search_word_form'))->drop();
rex_sql_table::get(rex::getTable('search_word'))->drop();
rex_sql_table::get(rex::getTable('search_index'))->drop();
rex_sql_table::get(rex::getTable('search_source'))->drop();

// Das vorbereitete Woerterbuch der Wortzerlegung und die Zwischenspeicher der Extraktion.
rex_dir::delete(rex_path::addonData('search'));
