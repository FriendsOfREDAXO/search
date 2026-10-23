<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Suggest;

use FriendsOfRedaxo\Search\Word\WordFolder;

use function array_filter;
use function array_unshift;
use function array_values;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;

/**
 * Ein Vorschlag fuer das Suchfeld: der ganze Wert und seine Teile.
 *
 * Die Teile sagen, was davon schon im Feld steht. Die Oberflaeche hebt dann das Ergaenzte
 * hervor und nicht das Getippte -- wer "Wärmep" getippt hat, sucht in der Liste nach dem,
 * was danach kommt.
 */
final class Suggestion
{
    /**
     * @param list<array{text: string, typed: bool}> $parts
     */
    public function __construct(
        public readonly string $value,
        public readonly array $parts,
    ) {}

    /**
     * Ein Wort aus der Wortliste, das an der Stelle $start (gefaltet gezaehlt) das Getippte
     * enthaelt; davor steht, was im Feld schon vor dem letzten Wort stand.
     *
     * Die Stelle wird Zeichen fuer Zeichen zurueckgerechnet, denn Falten aendert die Laenge:
     * aus "ß" wird "ss", "Straße" ist gefaltet sieben Zeichen lang.
     */
    public static function fromWord(string $head, string $word, int $start, string $typedFolded): self
    {
        $end = $start + mb_strlen($typedFolded);
        $from = $to = null;
        $position = 0;
        foreach (mb_str_split($word) as $i => $char) {
            if (null === $from && $position >= $start) {
                $from = $i;
            }
            $position += mb_strlen(WordFolder::fold($char));
            if ($position >= $end) {
                $to = $i + 1;
                break;
            }
        }
        $from ??= 0;
        $to ??= mb_strlen($word);

        return self::create($head, $word, [
            ['text' => mb_substr($word, 0, $from), 'typed' => false],
            ['text' => mb_substr($word, $from, $to - $from), 'typed' => true],
            ['text' => mb_substr($word, $to), 'typed' => false],
        ]);
    }

    /**
     * Ein Wort, in dem das Getippte nicht vorkommt -- eine Korrektur oder eine Langform.
     */
    public static function replacing(string $head, string $word): self
    {
        return self::create($head, $word, [['text' => $word, 'typed' => false]]);
    }

    /**
     * @return array{value: string, parts: list<array{text: string, typed: bool}>}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'parts' => $this->parts];
    }

    /**
     * @param list<array{text: string, typed: bool}> $parts
     */
    private static function create(string $head, string $word, array $parts): self
    {
        if ('' !== $head) {
            array_unshift($parts, ['text' => $head, 'typed' => true]);
        }

        return new self($head . $word, array_values(array_filter($parts, static fn (array $part): bool => '' !== $part['text'])));
    }
}
