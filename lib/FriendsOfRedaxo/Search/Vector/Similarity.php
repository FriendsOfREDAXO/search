<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

/**
 * Ab wann zwei Texte als verwandt gelten.
 *
 * Eine eigene Klasse fuer eine einzige Zahl, weil es genau eine sein muss: die hybride
 * Suche und die Passagen-API fragen dasselbe Modell und duerfen sich nicht
 * auseinanderentwickeln. Vorher stand der Wert zweimal da, einmal je Anfrageklasse, und
 * nur ein Test hielt sie zusammen.
 *
 * Gebraucht wird sie, weil eine Nachbarsuche kein "nichts gefunden" kennt: sie liefert
 * immer die naechsten Abschnitte, egal wie fern sie sind. Ohne Schwelle haengte an jeder
 * Frage dieselbe Handvoll Dokumente.
 *
 * Der Wert ist gemessen, und die erste Messung war zu eng. Mit vier Fragen, die alle
 * woertlich im Bestand standen, kam 0,5 heraus -- und daran scheiterte dann "ich suche
 * etwas zum spazieren", obwohl es die Wanderartikel sehr wohl findet, nur eben bei
 * 0,414. Umgangssprachlich gestellte Fragen liegen durchweg tiefer als knapp formulierte
 * Stichworte, und zwar unabhaengig davon, ob die Antwort da ist.
 *
 * Mit zwoelf Fragen gegen denselben Bestand (text-embedding-3-small):
 *
 *   beantwortbar      0,409 bis 0,741  ("spazieren gehen" bis "Was kostet Fortnite?")
 *   nichts im Bestand 0,198 bis 0,393  (Stickstoff, Fluege, Aktienkurs, Fahrradreparatur)
 *
 * Dazwischen liegt 0,40, und die Trennung ist vollstaendig.
 *
 * Der Wert haengt am Modell. Nach einem Wechsel des Profils gehoert er geprueft -- mit
 * "bin/console search:ask" an ein paar Fragen, die sich beantworten lassen, und ein paar,
 * die es nicht tun.
 */
final class Similarity
{
    public const DEFAULT_MINIMUM = 0.40;

    /**
     * Haelt einen Wert im gueltigen Bereich.
     *
     * 0 heisst "alles", 1 heisst "nur Deckungsgleiches". Beides ist erlaubt; was
     * dazwischen liegt, entscheidet der Aufrufer.
     */
    public static function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
