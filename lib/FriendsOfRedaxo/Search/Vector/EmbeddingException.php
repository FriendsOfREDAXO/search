<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use rex_exception;

/**
 * Der KI-Dienst hat nicht geliefert.
 *
 * Eigene Klasse, weil der Aufrufer sie anders behandelt als einen Programmfehler: ein
 * Zeitueberschreitung oder ein erschoepftes Kontingent ist kein Grund, einen Indexlauf
 * abzubrechen -- die betroffenen Abschnitte bleiben offen und kommen beim naechsten Lauf
 * wieder dran.
 */
final class EmbeddingException extends rex_exception {}
