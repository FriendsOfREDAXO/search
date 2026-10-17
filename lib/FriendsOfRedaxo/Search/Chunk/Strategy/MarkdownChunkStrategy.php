<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk\Strategy;

use FriendsOfRedaxo\Search\Chunk\Chunk;
use FriendsOfRedaxo\Search\Chunk\ChunkInput;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\TextSplitter;
use rex_i18n;

use function count;
use function strlen;

/**
 * Zerlegt entlang der Gliederung und merkt sich, wo ein Abschnitt steht.
 *
 * Ein Haushaltsplan hat Kapitel, ein Artikel hat Zwischenueberschriften. An diesen
 * Grenzen zu schneiden ist nicht nur ordentlicher, es ist der Unterschied zwischen einem
 * Abschnitt, der eine Frage beantwortet, und einem, der mitten im vorigen Thema anfaengt
 * und mitten im naechsten aufhoert.
 *
 * Jeder Abschnitt traegt seinen Ueberschriftenpfad. Der wird beim Einbetten vorangestellt
 * und spaeter mit ausgeliefert -- eine Antwortmaschine kann damit sagen, woher ein Satz
 * stammt, und ein Abschnitt ohne eigene Ueberschrift bleibt trotzdem einordenbar.
 *
 * Voraussetzung ist, dass der Originalinhalt Markdown ist. Ist er es nicht, faellt das
 * Verfahren auf feste Stuecke zurueck -- stillschweigend waere das falsch, deshalb sagt
 * es die Beschreibung des Verfahrens.
 */
final class MarkdownChunkStrategy extends ChunkStrategy
{
    /**
     * Ein Abschnitt unter dieser Groesse wird mit dem naechsten zusammengelegt. Eine
     * Ueberschrift mit einem Satz darunter ist kein eigener Abschnitt.
     */
    private const MERGE_BELOW = 300;

    public function getKey(): string
    {
        return 'markdown';
    }

    public function getLabel(): string
    {
        return rex_i18n::rawMsg('search_chunk_markdown');
    }

    public function getDescription(): string
    {
        return rex_i18n::rawMsg('search_chunk_markdown_description');
    }

    public function getConfigFields(array $config): array
    {
        return FixedChunkStrategy::sizeFields();
    }

    public function split(ChunkInput $input, array $config): array
    {
        if (!$input->hasStructure()) {
            return (new FixedChunkStrategy())->split($input, $config);
        }

        $size = FixedChunkStrategy::size($config);
        $overlap = FixedChunkStrategy::overlap($config);

        $chunks = [];
        foreach (self::sections($input->getText(), $input->title, $size) as $section) {
            foreach (TextSplitter::split($section['text'], $size, $overlap) as $piece) {
                $chunks[] = new Chunk($piece, $section['path']);
            }
        }

        return $chunks;
    }

    /**
     * Die Abschnitte eines Markdown-Textes mit ihrem Ueberschriftenpfad.
     *
     * @return list<array{path: string, text: string}>
     */
    private static function sections(string $markdown, string $title, int $size): array
    {
        $sections = [];
        /** @var array<int, string> $stack Ebene auf Ueberschrift */
        $stack = [];
        $body = [];
        $path = '' === trim($title) ? '' : trim($title);
        $root = $path;
        $inFence = false;

        $flush = static function () use (&$sections, &$body, &$path): void {
            $text = trim(implode("\n", $body));
            $body = [];
            if ('' !== $text) {
                $sections[] = ['path' => $path, 'text' => $text];
            }
        };

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            // In einem Codeblock ist eine Raute keine Ueberschrift.
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = !$inFence;
                $body[] = $line;
                continue;
            }
            if (!$inFence && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $match)) {
                $flush();
                $level = strlen($match[1]);
                foreach (array_keys($stack) as $open) {
                    if ($open >= $level) {
                        unset($stack[$open]);
                    }
                }
                $stack[$level] = trim($match[2]);
                ksort($stack);
                $path = self::path($root, array_values($stack));
                // Die Ueberschrift selbst bleibt im Text: sie ist oft der Satz, der die
                // Frage beantwortet ("Oeffnungszeiten").
                $body[] = $match[2];
                continue;
            }
            $body[] = $line;
        }
        $flush();

        return self::mergeSmall($sections, $size);
    }

    /**
     * Titel und Ueberschriften zu einem Pfad, ohne Wiederholungen.
     *
     * Ein Artikel heisst oft genauso wie seine erste Ueberschrift; "Fortnite › Fortnite"
     * waere ein Pfad, der nichts sagt und beim Einbetten Platz kostet, weil er dem Text
     * vorangestellt wird.
     *
     * @param list<string> $headings
     */
    private static function path(string $root, array $headings): string
    {
        $parts = [];
        foreach ([$root, ...$headings] as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if ([] !== $parts && 0 === strcasecmp(end($parts), $part)) {
                continue;
            }
            $parts[] = $part;
        }

        return implode(' › ', $parts);
    }

    /**
     * Legt zu kleine Abschnitte mit dem folgenden zusammen.
     *
     * Der Pfad des ersten bleibt stehen: er ist der allgemeinere, und unter ihm steht
     * danach beides.
     *
     * @param list<array{path: string, text: string}> $sections
     * @return list<array{path: string, text: string}>
     */
    private static function mergeSmall(array $sections, int $size): array
    {
        $merged = [];
        foreach ($sections as $section) {
            $last = count($merged) - 1;
            if ($last >= 0
                && mb_strlen($merged[$last]['text']) < self::MERGE_BELOW
                && mb_strlen($merged[$last]['text']) + mb_strlen($section['text']) <= $size
            ) {
                $merged[$last]['text'] .= "\n\n" . $section['text'];
                continue;
            }
            $merged[] = $section;
        }

        return $merged;
    }
}
