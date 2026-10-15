<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use function count;
use function in_array;

/**
 * Das Ergebnis der Begriffszerlegung.
 *
 * $words sind die verwertbaren Woerter in der Reihenfolge der Eingabe. $ignored sind die
 * weggelassenen, damit die Oberflaeche sagen kann, warum ein Wort nicht gewirkt hat -- das
 * ist der Unterschied zwischen einer Suche, die nichts findet, und einer, die erklaert
 * warum. $expansions sind verwandte Formen aus der Wortliste, die zusaetzlich gesucht
 * werden, etwa "Burgruine" zu "Ruine".
 *
 * Der BOOLEAN-Ausdruck wird hier berechnet und nicht hereingereicht: so gibt es genau eine
 * Stelle, an der aus Woertern eine Abfrage wird.
 */
final class SearchTerms
{
    /** Kuerzer als innodb_ft_min_token_size, steht also gar nicht im Index. */
    public const REASON_TOO_SHORT = 'too_short';

    /** Steht auf der Stoppwortliste von InnoDB. */
    public const REASON_STOPWORD = 'stopword';

    public readonly string $expression;

    /** @var list<string> */
    public readonly array $words;

    /** @var array<string, string> */
    public readonly array $ignored;

    /** @var array<string, list<string>> */
    public readonly array $expansions;

    /**
     * @param list<string> $words
     * @param array<string, string> $ignored Wort auf Grund, siehe die REASON-Konstanten
     * @param array<string, list<string>> $expansions Wort auf zusaetzliche Formen
     * @param string|null $expression Nur fuer Erweiterungen, die den Ausdruck selbst bauen
     */
    public function __construct(array $words, array $ignored = [], array $expansions = [], ?string $expression = null)
    {
        $this->words = array_values($words);
        $this->ignored = $ignored;
        $this->expansions = $expansions;
        $this->expression = $expression ?? self::build($this->words, $expansions);
    }

    /**
     * Dieselben Woerter mit zusaetzlichen Formen; der Ausdruck wird neu gebaut.
     *
     * @param array<string, list<string>> $expansions
     */
    public function withExpansions(array $expansions): self
    {
        return new self($this->words, $this->ignored, $expansions);
    }

    /**
     * Andere Woerter, gleiche Begruendungen -- fuer die Tippfehlerkorrektur.
     *
     * @param list<string> $words
     */
    public function withWords(array $words): self
    {
        return new self($words, $this->ignored);
    }

    public function isEmpty(): bool
    {
        return '' === $this->expression;
    }

    public function countWords(): int
    {
        return count($this->words);
    }

    /**
     * Wurde ueberhaupt etwas eingegeben, das nur nicht verwertbar war?
     *
     * Trifft das zu, ist "keine Treffer" die falsche Meldung; richtig waere ein Hinweis auf
     * die weggelassenen Woerter.
     */
    public function isEmptyButNotBlank(): bool
    {
        return $this->isEmpty() && [] !== $this->ignored;
    }

    /**
     * Alle Formen, die im Text hervorgehoben werden sollen: die eingegebenen Woerter und
     * das, was dazu gefunden wurde.
     *
     * @return list<string>
     */
    public function getHighlightWords(): array
    {
        $all = $this->words;
        foreach ($this->expansions as $forms) {
            foreach ($forms as $form) {
                if (!in_array($form, $all, true)) {
                    $all[] = $form;
                }
            }
        }

        return $all;
    }

    /**
     * "+wort*" heisst: das Wort muss vorkommen, exakt oder als Wortanfang.
     *
     * Mit zusaetzlichen Formen wird daraus "+(wort* form1 form2)" -- eine davon muss
     * vorkommen. Das kann nur mehr Treffer bringen, nie weniger; dass die eingegebene Form
     * trotzdem oben steht, regelt der Zuschlag in {@see ScoreExpression}.
     *
     * Die naheliegende Fassung "+(>wort <wort*)" waere falsch. Die Modifikatoren aendern den
     * Beitrag zur Bewertung, und "<" senkt ihn: ein Artikel, der neben "Burg" auch
     * "Burgmauer" und "Burgfest" enthaelt, wird fuer genau diesen Reichtum bestraft. Am
     * echten Bestand gemessen kam dabei ein negativer Wert heraus.
     *
     * @param list<string> $words
     * @param array<string, list<string>> $expansions
     */
    private static function build(array $words, array $expansions): string
    {
        $parts = [];
        foreach ($words as $word) {
            $forms = $expansions[$word] ?? [];
            if ([] === $forms) {
                $parts[] = '+' . $word . '*';
                continue;
            }
            $parts[] = '+(' . $word . '* ' . implode(' ', $forms) . ')';
        }

        return implode(' ', $parts);
    }
}
