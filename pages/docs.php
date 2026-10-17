<?php

/**
 * Die Anleitung.
 *
 * Gerendert wird die README des AddOns -- dieselbe Datei, die auf GitHub steht. Zwei
 * Fassungen desselben Textes laufen auseinander, und die, die jemand im Backend liest,
 * waere dann die falsche.
 */

declare(strict_types=1);

$addon = rex_addon::get('search');
$content = rex_file::get($addon->getPath('README.md'));

if (null === $content || '' === trim($content)) {
    echo rex_view::warning($addon->i18n('search_docs_missing'));

    return;
}

[$toc, $body] = rex_markdown::factory()->parseWithToc($content, 2, 3, [
    rex_markdown::SOFT_LINE_BREAKS => false,
    rex_markdown::HIGHLIGHT_PHP => true,
]);

$fragment = new rex_fragment();
$fragment->setVar('content', $body, false);
$fragment->setVar('toc', $toc, false);
echo $fragment->parse('core/page/docs.php');
