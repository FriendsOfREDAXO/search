<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Source;

use DateTimeImmutable;
use FriendsOfRedaxo\Search\Index\IndexRepository;
use rex;
use rex_sql;

use function is_array;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class SourceRepository
{
    public static function table(): string
    {
        return rex::getTable('search_source');
    }

    /**
     * @return list<Source>
     */
    public function findAll(bool $onlyActive = false): array
    {
        $where = $onlyActive ? ' WHERE status = 1' : '';
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . self::table() . $where . ' ORDER BY name, id');

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return list<Source>
     */
    public function findByType(string $typeKey, bool $onlyActive = true): array
    {
        $where = ' WHERE type_key = :type' . ($onlyActive ? ' AND status = 1' : '');
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . self::table() . $where . ' ORDER BY name, id', ['type' => $typeKey]);

        return array_map([$this, 'hydrate'], $rows);
    }

    public function find(int $id): ?Source
    {
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . self::table() . ' WHERE id = :id', ['id' => $id]);

        return [] === $rows ? null : $this->hydrate($rows[0]);
    }

    public function save(Source $source): Source
    {
        $sql = rex_sql::factory();
        $sql->setTable(self::table());
        $sql->setValue('name', $source->name);
        $sql->setValue('type_key', $source->typeKey);
        $sql->setValue('config', json_encode($source->config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sql->setValue('status', $source->status ? 1 : 0);
        $sql->addGlobalUpdateFields();

        if (null === $source->id) {
            $sql->addGlobalCreateFields();
            $sql->insert();
            $source->id = (int) $sql->getLastId();
        } else {
            $sql->setWhere(['id' => $source->id]);
            $sql->update();
        }

        return $source;
    }

    public function delete(int $id): void
    {
        (new IndexRepository())->deleteBySource($id);

        $sql = rex_sql::factory();
        $sql->setTable(self::table());
        $sql->setWhere(['id' => $id]);
        $sql->delete();
    }

    public function updateStats(int $id, int $itemCount, DateTimeImmutable $indexedAt): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(self::table());
        $sql->setWhere(['id' => $id]);
        $sql->setValue('item_count', $itemCount);
        $sql->setValue('last_indexed_at', $indexedAt->format('Y-m-d H:i:s'));
        $sql->update();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Source
    {
        $config = json_decode((string) ($row['config'] ?? ''), true);

        return new Source(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['type_key'],
            is_array($config) ? $config : [],
            (bool) $row['status'],
            empty($row['last_indexed_at']) ? null : new DateTimeImmutable((string) $row['last_indexed_at']),
            (int) $row['item_count'],
        );
    }
}
