<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Extract;

use function function_exists;
use function in_array;
use function ini_get;
use function is_resource;

use const PATH_SEPARATOR;

/**
 * Findet und startet Systemwerkzeuge, die bei der Extraktion helfen.
 *
 * Zwei Gruende fuer proc_open statt exec:
 *
 * Das Kommando wird als Argumentliste uebergeben, nicht als Zeichenkette. Damit startet
 * PHP das Programm direkt, ohne Shell -- ein Dateiname mit Anfuehrungszeichen, Semikolon
 * oder $(…) kann nichts ausloesen, und escapeshellarg braucht es gar nicht erst.
 *
 * Und nur so laesst sich ein Aufruf nach Zeit abbrechen. exec() blockiert, bis das
 * Programm fertig ist; in der Konsole und im Cronjob steht max_execution_time auf 0, ein
 * haengendes pdftotext wuerde den Indexlauf also unbegrenzt anhalten.
 */
final class Process
{
    /** Verzeichnisse, die bei PHP-FPM oft nicht im PATH stehen. */
    private const EXTRA_DIRECTORIES = ['/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin'];

    /** @var array<string, string> */
    private static array $binaries = [];

    private static ?bool $available = null;

    /**
     * Ob ueberhaupt ein Programm gestartet werden kann.
     */
    public static function isAvailable(): bool
    {
        if (null !== self::$available) {
            return self::$available;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return self::$available = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    /**
     * Der vollstaendige Pfad zu einem Programm, oder eine leere Zeichenkette.
     *
     * Gesucht wird im PATH und zusaetzlich an den ueblichen Orten: in einem
     * PHP-FPM-Prozess ist der PATH haeufig kuerzer als in der Anmeldeschale, und
     * /usr/local/bin oder /opt/homebrew/bin fehlen dann.
     */
    public static function find(string $name): string
    {
        if (isset(self::$binaries[$name])) {
            return self::$binaries[$name];
        }

        if (!self::isAvailable() || !preg_match('/^[a-z0-9_-]+$/i', $name)) {
            return self::$binaries[$name] = '';
        }

        $directories = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $found = '';
        foreach (array_unique([...self::EXTRA_DIRECTORIES, ...$directories]) as $directory) {
            $path = rtrim($directory, '/') . '/' . $name;
            if (is_executable($path)) {
                $found = $path;
                break;
            }
        }

        return self::$binaries[$name] = $found;
    }

    /**
     * Fuehrt ein Programm aus und gibt seine Ausgabe zurueck, null bei Abbruch.
     *
     * @param list<string> $command Programm und seine Argumente
     * @param bool $includeErrors Fehlerausgabe mitliefern -- manche Programme schreiben
     *                            ihre Versionsangabe dorthin statt auf die Standardausgabe
     */
    public static function run(array $command, int $timeout, bool $includeErrors = false): ?string
    {
        if (!self::isAvailable() || [] === $command) {
            return null;
        }

        $pipes = [];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + max(1, $timeout);
        $running = true;

        while (microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]);
            // Die Fehlerausgabe muss in jedem Fall gelesen werden; bliebe sie stehen,
            // liefe der Puffer voll und das Programm blockierte beim Schreiben.
            $errors = (string) stream_get_contents($pipes[2]);
            if ($includeErrors) {
                $output .= $errors;
            }

            if (!proc_get_status($process)['running']) {
                $running = false;
                break;
            }

            usleep(20000);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        if ($includeErrors) {
            $output .= (string) stream_get_contents($pipes[2]);
        }

        if ($running && proc_get_status($process)['running']) {
            proc_terminate($process, 9);
            $output = '';
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $status = proc_close($process);

        if ('' === $output && 0 !== $status) {
            return null;
        }

        return $output;
    }

    /**
     * Nur fuer Tests: die gemerkten Pfade und die Verfuegbarkeit vergessen.
     */
    public static function reset(): void
    {
        self::$binaries = [];
        self::$available = null;
    }
}
