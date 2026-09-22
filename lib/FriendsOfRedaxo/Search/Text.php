<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Wandelt HTML und Rohwerte in indizierbaren Klartext um.
 */
final class Text
{
    public static function fromHtml(?string $html): string
    {
        $text = (string) $html;
        if ('' === trim($text)) {
            return '';
        }

        $text = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text);
        $text = (string) preg_replace('~<br\s*/?>|</(p|div|li|h[1-6]|tr|td|th|blockquote|section|article)>~i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/', "\n", $text);

        return trim($text);
    }

    /**
     * Verkettet Textteile, leere Teile fallen weg.
     *
     * @param iterable<string|null> $parts
     */
    public static function join(iterable $parts, string $separator = "\n"): string
    {
        $filtered = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ('' !== $part) {
                $filtered[] = $part;
            }
        }

        return implode($separator, $filtered);
    }

    public static function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }
}
