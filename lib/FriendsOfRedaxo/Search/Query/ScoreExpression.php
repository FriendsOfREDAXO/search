<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Query;

use function count;

/**
 * Baut den SQL-Ausdruck, der einen Treffer bewertet.
 *
 * Eigene Klasse, weil hier die eigentliche Entscheidung faellt, welcher Treffer oben steht
 * -- und weil zwei Fehler darin erst durch Messung am echten Bestand auffielen. Als
 * Zeichenkette gebaut ist er pruefbar, ohne dass eine Datenbank dafuer noetig waere.
 *
 * Vier Bestandteile, jeder mit einem Grund:
 *
 * 1. LN(1 + score) saettigt die Trefferhaeufigkeit. InnoDB rechnet roh die Anzahl der
 *    Vorkommen mal einem Wortgewicht, ohne jede Begrenzung und ohne Ruecksicht auf die
 *    Laenge: ein Haushaltsplan mit 453 Nennungen bekam gemessen das Hundertfache eines
 *    Artikels, der dasselbe Thema in drei Saetzen auf den Punkt bringt. Der Logarithmus
 *    nimmt dem die Spitze, ohne die Richtung umzudrehen.
 *
 * 2. Der Zuschlag fuer die exakte Wortform unterscheidet "Burg" von "Burgfest". Gesucht
 *    wird mit "+burg*", damit Zusammensetzungen ueberhaupt gefunden werden; ohne diesen
 *    Zuschlag lagen beide gemessen bei exakt demselben Wert. Ein Zuschlag, kein Abschlag:
 *    die naheliegende Fassung ueber die Modifikatoren ">" und "<" bestraft ein Dokument
 *    dafuer, dass es beide Formen enthaelt, bis hin zu negativen Werten.
 *
 * 3. Ein Treffer im Titel zaehlt zusaetzlich. Dafuer gibt es den zweiten Volltextindex
 *    allein ueber title.
 *
 * 4. Die Laengendaempfung greift erst oberhalb einer Schwelle, darunter gar nicht. Eine
 *    Daempfung, die schon zwischen 24 und 984 Zeichen unterscheidet, stellt den
 *    Meta-Eintrag eines Artikels vor dessen Volltext -- beide haben dieselbe Bewertung,
 *    und der kuerzere wird weniger geteilt.
 *
 * Dazu ein Zuschlag, wenn die Woerter nahe beieinander stehen, und optional ein Faktor
 * je Quelle.
 */
final class ScoreExpression
{
    /** @var array<string, mixed> */
    private array $params = [];

    public function __construct(
        private readonly SearchQuery $query,
        private readonly SearchTerms $terms,
    ) {}

    public function build(): string
    {
        $score = 'LN(1 + ' . $this->match('title, content', 'term_content', $this->terms->expression) . ')';
        $score .= $this->exactBonus();
        $score .= $this->proximityBonus();
        $score .= $this->titleBonus();

        return '(' . $score . ')' . $this->lengthDamping() . $this->sourceWeights();
    }

    /**
     * Die Parameter zum gebauten Ausdruck, gueltig nach {@see build()}.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    private function exactBonus(): string
    {
        // Die Woerter ohne Stern und ohne Pflichtzeichen: optionale Begriffe, die nur die
        // Bewertung anheben. Wer sie nicht enthaelt, faellt deshalb nicht aus der Liste.
        $exact = implode(' ', $this->terms->words);
        if (0.0 >= $this->query->getExactBoost() || '' === $exact) {
            return '';
        }

        $this->params['exact_boost'] = $this->query->getExactBoost();

        return ' + :exact_boost * LN(1 + ' . $this->match('title, content', 'term_exact', $exact) . ')';
    }

    /**
     * Zuschlag, wenn die gesuchten Woerter im Text nahe beieinander stehen.
     *
     * "Burg Eppstein" irgendwo im selben Dokument ist schwaecher als "Burg Eppstein" in
     * einem Satz. InnoDB kann das selbst: eine Wortgruppe in Anfuehrungszeichen, gefolgt
     * von @ und einem Abstand in Woertern. Am Bestand gemessen liefern beide Woerter
     * irgendwo 12 Treffer, innerhalb von 50 Woertern 7, innerhalb von 5 noch 5.
     *
     * Nur bei mindestens zwei Woertern sinnvoll -- bei einem einzigen gibt es keinen
     * Abstand, und die Wortgruppe waere dasselbe wie das Wort.
     */
    private function proximityBonus(): string
    {
        if (0.0 >= $this->query->getProximityBoost() || 2 > count($this->terms->words)) {
            return '';
        }

        $phrase = '"' . implode(' ', $this->terms->words) . '" @' . $this->query->getProximityDistance();
        $this->params['proximity_boost'] = $this->query->getProximityBoost();

        return ' + :proximity_boost * LN(1 + ' . $this->match('title, content', 'term_proximity', $phrase) . ')';
    }

    private function titleBonus(): string
    {
        if (0.0 >= $this->query->getTitleBoost()) {
            return '';
        }

        $this->params['title_boost'] = $this->query->getTitleBoost();

        return ' + :title_boost * LN(1 + ' . $this->match('title', 'term_title', $this->terms->expression) . ')';
    }

    private function lengthDamping(): string
    {
        if (0 >= $this->query->getLengthScale()) {
            return '';
        }

        $this->params['length_scale'] = $this->query->getLengthScale();

        return ' / (1 + LN(GREATEST(1, CHAR_LENGTH(content) / :length_scale)))';
    }

    private function sourceWeights(): string
    {
        $weights = $this->query->getSourceWeights();
        if ([] === $weights) {
            return '';
        }

        $cases = '';
        $i = 0;
        foreach ($weights as $sourceId => $factor) {
            $this->params['weight_source_' . $i] = (int) $sourceId;
            $this->params['weight_factor_' . $i] = (float) $factor;
            $cases .= ' WHEN :weight_source_' . $i . ' THEN :weight_factor_' . $i;
            ++$i;
        }

        return ' * CASE source_id' . $cases . ' ELSE 1 END';
    }

    private function match(string $columns, string $parameter, string $expression): string
    {
        $this->params[$parameter] = $expression;

        return 'MATCH (' . $columns . ') AGAINST (:' . $parameter . ' IN BOOLEAN MODE)';
    }
}
