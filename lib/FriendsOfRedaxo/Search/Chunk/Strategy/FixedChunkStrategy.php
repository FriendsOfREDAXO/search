<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Chunk\Strategy;

use FriendsOfRedaxo\Search\Chunk\Chunk;
use FriendsOfRedaxo\Search\Chunk\ChunkInput;
use FriendsOfRedaxo\Search\Chunk\ChunkStrategy;
use FriendsOfRedaxo\Search\Chunk\TextSplitter;
use FriendsOfRedaxo\Search\Source\ConfigField;
use rex_i18n;

/**
 * Stuecke einer festen Groesse mit Ueberlappung.
 *
 * Das Verfahren fuer alles ohne Gliederung: Klartext, CSV, ein PDF ohne Ueberschriften.
 * Die Ueberlappung ist nicht Verschwendung, sondern der Grund, warum ein Satz, der genau
 * auf der Grenze liegt, trotzdem in einem Stueck gefunden wird.
 */
final class FixedChunkStrategy extends ChunkStrategy
{
    public const DEFAULT_SIZE = 1500;

    public const DEFAULT_OVERLAP = 200;

    public function getKey(): string
    {
        return 'fixed';
    }

    public function getLabel(): string
    {
        return rex_i18n::rawMsg('search_chunk_fixed');
    }

    public function getDescription(): string
    {
        return rex_i18n::rawMsg('search_chunk_fixed_description');
    }

    public function getConfigFields(array $config): array
    {
        return self::sizeFields();
    }

    public function split(ChunkInput $input, array $config): array
    {
        $text = $input->getText();
        if ('' === $text) {
            return [];
        }

        $chunks = [];
        foreach (TextSplitter::split($text, self::size($config), self::overlap($config)) as $piece) {
            $chunks[] = new Chunk($piece, $input->title);
        }

        return $chunks;
    }

    /**
     * Die beiden Groessenfelder, auch von {@see MarkdownChunkStrategy} genutzt.
     *
     * @return list<ConfigField>
     */
    public static function sizeFields(): array
    {
        return [
            new ConfigField('chunk_size', rex_i18n::msg('search_chunk_size'), ConfigField::TEXT, notice: rex_i18n::msg('search_chunk_size_notice'), default: (string) self::DEFAULT_SIZE),
            new ConfigField('chunk_overlap', rex_i18n::msg('search_chunk_overlap'), ConfigField::TEXT, notice: rex_i18n::msg('search_chunk_overlap_notice'), default: (string) self::DEFAULT_OVERLAP),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function size(array $config): int
    {
        $size = (int) ($config['chunk_size'] ?? self::DEFAULT_SIZE);

        // Unter 200 Zeichen zerfaellt jeder Absatz, ueber 8000 passt ein Stueck nicht
        // mehr in das Kontextfenster einer Antwort. Beides faengt die Eingabe ab, statt
        // es dem Aufrufer zu ueberlassen.
        return min(8000, max(200, $size));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function overlap(array $config): int
    {
        return max(0, (int) ($config['chunk_overlap'] ?? self::DEFAULT_OVERLAP));
    }
}
