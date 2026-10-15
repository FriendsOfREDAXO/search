<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract\Extractor;

use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use FriendsOfRedaxo\Search\Markdown;
use rex_i18n;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * HTML-Dateien aus dem Medienpool.
 */
final class HtmlExtractor extends DocumentExtractor
{
    public function getKey(): string
    {
        return 'html';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('search_extractor_html');
    }

    public function getExtensions(): array
    {
        return ['html', 'htm', 'xhtml'];
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        $html = @file_get_contents($path);
        if (false === $html) {
            return null;
        }

        $title = $this->readTitle($html);
        [$markdown, $truncated] = $context->truncate(Markdown::fromHtml($html));

        return new ExtractedDocument($markdown, $title, ['extractor' => $this->getKey()], $truncated);
    }

    private function readTitle(string $html): ?string
    {
        if (preg_match('~<title[^>]*>([^<]{1,300})</title>~i', $html, $match)) {
            $title = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return '' === $title ? null : $title;
        }

        return null;
    }
}
