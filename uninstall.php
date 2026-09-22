<?php

rex_sql_table::get(rex::getTable('search_index'))->drop();
rex_sql_table::get(rex::getTable('search_source'))->drop();
