<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Vector;

use FriendsOfRedaxo\AiPlatform\Service;
use rex_addon;
use rex_config;
use Throwable;

use function class_exists;
use function count;
use function is_array;

/**
 * Der Zugang zum KI-Dienst, ueber das AddOn ai_platform.
 *
 * Eine eigene Klasse davor, nicht weil ai_platform umstaendlich waere -- die Schnittstelle
 * ist eine Zeile --, sondern aus drei anderen Gruenden: das AddOn kann fehlen, das
 * gewaehlte Profil kann verschwinden, und fuer Tests muss sich der Dienst ersetzen
 * lassen, ohne dass ein Testlauf Geld kostet.
 *
 * Nicht final, genau dafuer.
 */
class EmbeddingClient
{
    /**
     * So viele Texte gehen in einen Aufruf.
     *
     * Gemessen ist das der entscheidende Wert: einzeln dauert ein Abschnitt 172 ms, zu
     * zwanzig 20 ms, zu hundert 4 ms. Fast alles davon ist Wartezeit auf die Verbindung,
     * nicht Rechnen -- wer einzeln fragt, zahlt sie jedes Mal neu.
     */
    public const BATCH_SIZE = 100;

    public static function isAvailable(): bool
    {
        return rex_addon::exists('ai_platform')
            && rex_addon::get('ai_platform')->isAvailable()
            && class_exists(Service::class);
    }

    /**
     * Die Embedding-Profile, die ai_platform anbietet.
     *
     * @return array<int, string> Profil-Id auf Beschriftung
     */
    public static function getProfiles(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $profiles = [];
        try {
            foreach (Service::getInstance()->getProfiles('embedding') as $profile) {
                $label = (string) ($profile['name'] ?? '');
                $model = (string) ($profile['model'] ?? '');
                $profiles[(int) $profile['id']] = '' === $model ? $label : $label . ' (' . $model . ')';
            }
        } catch (Throwable) {
            return [];
        }

        return $profiles;
    }

    /**
     * Das eingestellte Profil, oder null.
     *
     * Geprueft wird auch, ob es das Profil noch gibt: es lebt in einem anderen AddOn und
     * kann dort geloescht werden, ohne dass hier jemand gefragt wird.
     */
    public static function getProfileId(): ?int
    {
        $id = (int) rex_config::get('search', 'embedding_profile', 0);

        return 0 < $id && isset(self::getProfiles()[$id]) ? $id : null;
    }

    public static function isConfigured(): bool
    {
        return null !== self::getProfileId();
    }

    /**
     * Die Modellbezeichnung des eingestellten Profils, fuer die Anzeige.
     */
    public static function getModel(): string
    {
        $id = self::getProfileId();
        if (null === $id) {
            return '';
        }

        try {
            return (string) (Service::getInstance()->getProfile($id)['model'] ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Vektoren zu Texten, in der Reihenfolge der Eingabe.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        if ([] === $texts) {
            return [];
        }

        $profileId = self::getProfileId();
        if (null === $profileId) {
            throw new EmbeddingException('Kein Embedding-Profil eingestellt.');
        }

        try {
            $result = Service::getInstance()->generateEmbedding($texts, $profileId);
        } catch (Throwable $exception) {
            throw new EmbeddingException($exception->getMessage(), 0, $exception);
        }

        return self::checkShape($result, count($texts));
    }

    /**
     * Prueft, dass zu jedem Text genau ein Vektor zurueckkam.
     *
     * Die Antwort wird der Reihe nach den Abschnitten zugeordnet -- stimmt die Anzahl
     * nicht, bekaeme jeder Abschnitt ab der Luecke den Vektor eines anderen, und zwar
     * ohne dass irgendetwas auffaellt. Erst eine Suche liefert dann Unsinn, und niemand
     * wuesste warum.
     *
     * Dazu kommt eine Eigenheit von generateEmbedding(): bei einem einzelnen Text
     * liefert es einen flachen Vektor, erst bei einer Liste eine Liste von Vektoren.
     * Hier geht immer eine Liste hinein, also muss auch eine herauskommen.
     *
     * @param array<mixed> $result
     * @return list<list<float>>
     */
    public static function checkShape(array $result, int $expected): array
    {
        if (count($result) !== $expected) {
            throw new EmbeddingException('Der Dienst hat ' . count($result) . ' Vektoren zu ' . $expected . ' Texten geliefert.');
        }
        if (0 < $expected && !is_array($result[0] ?? null)) {
            throw new EmbeddingException('Der Dienst hat keine Liste von Vektoren geliefert.');
        }

        /** @var list<list<float>> $result */
        return array_values($result);
    }
}
