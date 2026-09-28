<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Cronjob;

use FriendsOfRedaxo\Search\Index\Indexer;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use rex_addon;
use rex_cronjob;
use Throwable;

/**
 * Baut den Index ausgewaehlter Quellen neu auf.
 *
 * Der Index wird nicht laufend fortgeschrieben, sondern in Abstaenden komplett erneuert.
 * Ohne Auswahl laufen alle aktiven Quellen.
 */
class ReindexCronjob extends rex_cronjob
{
    public function getTypeName(): string
    {
        return self::addon()->i18n('cronjob_reindex');
    }

    public function getParamFields(): array
    {
        $addon = self::addon();

        $options = [];
        foreach ((new SourceRepository())->findAll() as $source) {
            $label = $source->name;
            $type = $source->getType();
            if (null !== $type) {
                $label .= ' [' . $type->getNamespace() . ' / ' . $type->getType($source) . ']';
            }
            if (!$source->status) {
                $label .= ' – ' . $addon->i18n('inactive');
            }
            $options[$source->requireId()] = $label;
        }

        return [
            [
                'label' => $addon->i18n('cronjob_sources'),
                'name' => 'sources',
                'type' => 'checkbox',
                'options' => $options,
                'notice' => $addon->i18n('cronjob_sources_notice'),
            ],
        ];
    }

    public function execute(): bool
    {
        $addon = self::addon();
        $selected = $this->getSelectedSourceIds();

        $messages = [];
        $failed = 0;
        $indexer = new Indexer();

        foreach ($this->getSources($selected) as $source) {
            if (!$source->status) {
                $messages[] = $source->name . ': ' . $addon->i18n('cronjob_skipped_inactive');
                continue;
            }

            try {
                $result = $indexer->rebuild($source);
                $messages[] = $addon->i18n(
                    'cronjob_result',
                    $source->name,
                    $result->items,
                    $result->documentsWritten,
                    $result->documentsDeleted,
                    $result->skipped,
                );
            } catch (Throwable $exception) {
                ++$failed;
                $messages[] = $source->name . ': ' . $exception->getMessage();
            }
        }

        if ([] === $messages) {
            $this->setMessage($addon->i18n('cronjob_no_sources'));

            return true;
        }

        $this->setMessage(implode(' | ', $messages));

        return 0 === $failed;
    }

    /**
     * Die gewaehlten Quellen, oder alle wenn nichts gewaehlt ist.
     *
     * @param list<int> $selected
     * @return list<Source>
     */
    private function getSources(array $selected): array
    {
        $repository = new SourceRepository();

        if ([] === $selected) {
            return $repository->findAll(true);
        }

        $sources = [];
        foreach ($selected as $id) {
            $source = $repository->find($id);
            if (null !== $source) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * Die Checkbox-Gruppe speichert ihre Werte mit Pipes umschlossen, etwa "|1|3|".
     *
     * @return list<int>
     */
    private function getSelectedSourceIds(): array
    {
        $raw = trim((string) $this->getParam('sources'), '|');
        if ('' === $raw) {
            return [];
        }

        $ids = [];
        foreach (explode('|', $raw) as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function addon(): rex_addon
    {
        return rex_addon::get('search');
    }
}
