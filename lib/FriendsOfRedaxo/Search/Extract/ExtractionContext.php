<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

use const PATHINFO_EXTENSION;

/**
 * Rahmenbedingungen einer Extraktion.
 *
 * Die Grenzen sind kein Detail: eine einzelne Datei kann mehrere Megabyte Text enthalten,
 * und ohne Obergrenze zieht ein einziges Dokument den Speicher des ganzen Laufs nach oben.
 * $maxCharacters gilt fuer das erzeugte Markdown, nicht fuer die Datei.
 */
final class ExtractionContext
{
    public const DEFAULT_MAX_CHARACTERS = 1_000_000;

    public function __construct(
        public readonly string $filename = '',
        public readonly string $mimeType = '',
        public readonly int $maxCharacters = self::DEFAULT_MAX_CHARACTERS,
    ) {}

    public function getExtension(): string
    {
        $extension = pathinfo($this->filename, PATHINFO_EXTENSION);

        return mb_strtolower((string) $extension);
    }

    /**
     * Kuerzt an einer Zeilengrenze, damit keine halbe Tabellenzeile stehen bleibt.
     *
     * @return array{string, bool} Text und ob gekuerzt wurde
     */
    public function truncate(string $text): array
    {
        if (mb_strlen($text) <= $this->maxCharacters) {
            return [$text, false];
        }

        $cut = mb_substr($text, 0, $this->maxCharacters);
        $lastBreak = mb_strrpos($cut, "\n");
        if (false !== $lastBreak && $lastBreak > $this->maxCharacters / 2) {
            $cut = mb_substr($cut, 0, $lastBreak);
        }

        return [rtrim($cut), true];
    }
}
