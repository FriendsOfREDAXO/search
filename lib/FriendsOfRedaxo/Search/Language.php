<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search;

use rex_i18n;

use function array_key_exists;

/**
 * Die Sprachen, die eine Quelle angeben kann.
 *
 * Warum das eine Angabe und keine Ableitung ist: Stammformen und Wortzerlegung sind
 * sprachgebunden und mit der falschen Sprache nicht schwaecher, sondern falsch -- gemessen
 * landet rund die Haelfte aller Woerter je nach angenommener Sprache auf einem anderen
 * Stamm ("Hasen" deutsch zu "has", englisch zu "hasen"). Abzuleiten gibt es sie aber
 * nirgends: der Sprachcode einer REDAXO-Sprache ist ein freies Textfeld und muss kein
 * ISO-Code sein, Medien und YForm-Datensaetze haben gar keine Sprache, und eine Erkennung
 * am Text raet nur genauer. Also wird sie an der Quelle eingetragen.
 *
 * Die Liste ist die Schnittmenge dessen, was die mitgelieferten Werkzeuge koennen, siehe
 * {@see Analysis\Analyzer}. Eine Sprache, die hier fehlt, ist kein Hindernis: die Quelle
 * bleibt dann auf {@see UNKNOWN}, wird ganz normal indiziert und nur nicht analysiert.
 */
final class Language
{
    /** Keine Angabe. Die Quelle wird indiziert, aber nicht analysiert. */
    public const UNKNOWN = '';

    /**
     * Der Wert, mit dem im Formular ausdruecklich "keine Sprache" gewaehlt wird.
     *
     * Er ist noetig, weil das Feld ein Pflichtfeld ist: waere "keine Angabe" die leere
     * Zeichenkette, liesse sich nicht unterscheiden, ob jemand sich dafuer entschieden
     * oder das Feld schlicht uebersehen hat. {@see normalize()} macht daraus wieder
     * {@see UNKNOWN}; gespeichert bleibt die Entscheidung.
     */
    public const NONE = '-';

    /**
     * Sprachcode auf englische Bezeichnung. Die Anzeige uebersetzt ueber rex_i18n, siehe
     * {@see label()}; die Bezeichnung hier ist der Rueckfall.
     */
    private const LANGUAGES = [
        'ca' => 'Catalan',
        'da' => 'Danish',
        'de' => 'German',
        'en' => 'English',
        'es' => 'Spanish',
        'fi' => 'Finnish',
        'fr' => 'French',
        'it' => 'Italian',
        'nl' => 'Dutch',
        'no' => 'Norwegian',
        'pt' => 'Portuguese',
        'ro' => 'Romanian',
        'ru' => 'Russian',
        'sv' => 'Swedish',
    ];

    /**
     * Macht aus einer Eingabe einen bekannten Sprachcode oder {@see UNKNOWN}.
     *
     * Verstanden wird auch die laengere Schreibweise, wie sie in REDAXO als Sprachcode
     * vorkommt: "de_de" und "de-CH" ergeben beide "de". Das ist keine Ableitung der
     * Sprache aus dem clang -- es nimmt nur dem Benutzer die Schreibarbeit ab, wenn er
     * selbst eine solche Form eintraegt.
     */
    public static function normalize(string $code): string
    {
        $code = strtolower(trim($code));
        if (array_key_exists($code, self::LANGUAGES)) {
            return $code;
        }

        $base = substr($code, 0, 2);

        return array_key_exists($base, self::LANGUAGES) ? $base : self::UNKNOWN;
    }

    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::LANGUAGES);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::LANGUAGES);
    }

    public static function label(string $code): string
    {
        if (self::UNKNOWN === $code) {
            return rex_i18n::msg('search_language_unknown');
        }
        if (!array_key_exists($code, self::LANGUAGES)) {
            return $code;
        }

        return rex_i18n::msg('search_language_' . $code) . ' (' . $code . ')';
    }

    /**
     * Die Auswahl fuer das Quellenformular, "keine Angabe" zuerst.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [self::NONE => self::label(self::UNKNOWN)];
        foreach (self::LANGUAGES as $code => $name) {
            $options[$code] = self::label($code);
        }

        return $options;
    }
}
