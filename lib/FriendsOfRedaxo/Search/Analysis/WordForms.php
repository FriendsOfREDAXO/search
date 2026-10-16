<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Analysis;

/**
 * Macht aus einer Wortliste die Zeilen der Formtabelle.
 *
 * Eigene Klasse, weil hier die Entscheidung faellt, welche Form zu welchem Wort gehoert --
 * das laesst sich so ohne Datenbank pruefen. Das Schreiben steht in
 * FriendsOfRedaxo\Search\Word\WordFormRepository.
 */
final class WordForms
{
    /**
     * Zu jedem Wort seine Nachschlageformen.
     *
     * Eine Form, die mit dem Wort selbst uebereinstimmt, bleibt drin: die Suche schlaegt
     * ausschliesslich ueber die Formtabelle nach, ein Wort ohne eigene Zeile waere darueber
     * also nicht erreichbar. "Burg" braucht seine Zeile "burg", sonst findet "Burgen" sie
     * nicht.
     *
     * @param iterable<array{word: string, docs: int}> $words
     * @return list<array{form: string, word: string, docs: int}>
     */
    public static function build(iterable $words, ?Analyzer $analyzer): array
    {
        if (null === $analyzer) {
            return [];
        }

        $rows = [];
        foreach ($words as $entry) {
            foreach ($analyzer->forms($entry['word']) as $form) {
                $rows[] = ['form' => $form, 'word' => $entry['word'], 'docs' => $entry['docs']];
            }
        }

        return $rows;
    }
}
