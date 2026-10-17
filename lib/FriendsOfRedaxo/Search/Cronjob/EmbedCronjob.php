<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Cronjob;

use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Vector\Embedder;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use rex_addon;
use rex_cronjob;
use Throwable;

use function count;

/**
 * Erzeugt die fehlenden Vektoren der Abschnitte.
 *
 * Eigener Cronjob neben dem Neuaufbau des Index, und das mit Absicht: der Index laeuft in
 * Sekunden durch, das Einbetten haengt an einem fremden Dienst, kostet Geld und kann
 * abbrechen. Beides in einen Lauf zu packen hiesse, dass eine Zeitueberschreitung beim
 * KI-Dienst den Indexaufbau mitreisst.
 *
 * Ein Zeitbudget gehoert dazu, weil ein Lauf sonst in das Zeitfenster des Servers
 * hineinlaeuft. Was nicht fertig wird, bleibt offen und kommt beim naechsten Mal dran.
 */
class EmbedCronjob extends rex_cronjob
{
    private const DEFAULT_SECONDS = 120;

    public function getTypeName(): string
    {
        return self::addon()->i18n('cronjob_embed');
    }

    public function getParamFields(): array
    {
        $addon = self::addon();

        $options = [];
        foreach ((new SourceRepository())->findAll() as $source) {
            if ($source->createsChunks()) {
                $options[$source->requireId()] = $source->name;
            }
        }

        return [
            [
                'label' => $addon->i18n('cronjob_sources'),
                'name' => 'sources',
                'type' => 'checkbox',
                'options' => $options,
                'notice' => $addon->i18n('cronjob_embed_sources_notice'),
            ],
            [
                'label' => $addon->i18n('cronjob_embed_seconds'),
                'name' => 'seconds',
                'type' => 'text',
                'default' => (string) self::DEFAULT_SECONDS,
                'notice' => $addon->i18n('cronjob_embed_seconds_notice'),
            ],
        ];
    }

    public function execute(): bool
    {
        if (!VectorSupport::isAvailable()) {
            $this->setMessage(self::addon()->i18n('search_diagnosis_vector_missing', VectorSupport::getVersion(), VectorSupport::MIN_MARIADB));

            return false;
        }
        if (!EmbeddingClient::isConfigured()) {
            $this->setMessage(self::addon()->i18n('search_settings_embedding_missing'));

            return false;
        }

        $seconds = max(5.0, (float) $this->getParam('seconds', self::DEFAULT_SECONDS));
        $sourceIds = $this->getSelectedSourceIds();
        $embedder = new Embedder();

        $messages = [];
        $ok = true;
        $start = microtime(true);

        // Ohne Auswahl laeuft alles in einem Durchgang; mit Auswahl bekommt jede Quelle
        // ihren Anteil am Budget, damit die erste nicht alles aufbraucht.
        $budget = [] === $sourceIds ? $seconds : $seconds / count($sourceIds);

        foreach ([] === $sourceIds ? [null] : $sourceIds as $sourceId) {
            if (microtime(true) - $start >= $seconds) {
                break;
            }
            try {
                $result = $embedder->run($sourceId, 0, $budget);
            } catch (Throwable $exception) {
                $messages[] = $exception->getMessage();
                $ok = false;
                continue;
            }
            $messages[] = (null === $sourceId ? '' : '#' . $sourceId . ': ') . $result->describe();
            if (0 < $result->failed) {
                $ok = false;
            }
        }

        $this->setMessage(implode(' | ', $messages));

        return $ok;
    }

    /**
     * @return list<int>
     */
    private function getSelectedSourceIds(): array
    {
        $ids = [];
        foreach ((array) $this->getParam('sources', []) as $id) {
            if (0 < (int) $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private static function addon(): rex_addon
    {
        return rex_addon::get('search');
    }
}
