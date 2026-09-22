<?php

use FriendsOfRedaxo\Search\Source\ConfigField;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceType;
use FriendsOfRedaxo\Search\Source\SourceTypeRegistry;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_source_type_registry_test extends TestCase
{
    protected function setUp(): void
    {
        SourceTypeRegistry::reset();
    }

    protected function tearDown(): void
    {
        SourceTypeRegistry::reset();
    }

    public function testBuiltInTypesAreRegistered(): void
    {
        $types = SourceTypeRegistry::all();

        self::assertArrayHasKey('article', $types);
        self::assertSame('article', $types['article']->getKey());
    }

    public function testExtensionPointAddsForeignTypesAndHidesUnavailableOnes(): void
    {
        $available = $this->createType('demo_available', true);
        $unavailable = $this->createType('demo_unavailable', false);

        $extension = static function (rex_extension_point $ep) use ($available, $unavailable) {
            $types = $ep->getSubject();
            $types[] = $available;
            $types[] = $unavailable;

            return $types;
        };
        rex_extension::register('SEARCH_SOURCE_TYPES', $extension);

        try {
            self::assertSame($available, SourceTypeRegistry::get('demo_available'));
            self::assertNull(SourceTypeRegistry::get('demo_unavailable'));
        } finally {
            $this->unregister('SEARCH_SOURCE_TYPES', $extension);
        }
    }

    public function testNormalizeConfigUsesDefaultsAndFieldTypes(): void
    {
        $type = $this->createType('demo', true);

        $config = $type->normalizeConfig(['title' => ' Hallo ', 'tags' => ['a', '', 'b']]);

        // Eine nicht gesendete Checkbox ist abgewaehlt, ihr Default gilt nur fuer das leere Formular
        self::assertSame(['title' => 'Hallo', 'tags' => ['a', 'b'], 'active' => false], $config);

        $config = $type->normalizeConfig(['title' => 'Hallo', 'active' => '1']);

        self::assertSame(['title' => 'Hallo', 'tags' => [], 'active' => true], $config);
    }

    private function createType(string $key, bool $available): SourceType
    {
        return new class($key, $available) extends SourceType {
            public function __construct(
                private string $key,
                private bool $available,
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return $this->key;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getConfigFields(array $config): array
            {
                return [
                    new ConfigField('title', 'Titel'),
                    new ConfigField('tags', 'Tags', ConfigField::MULTISELECT),
                    new ConfigField('active', 'Aktiv', ConfigField::CHECKBOX, default: true),
                ];
            }

            public function getType(Source $source): string
            {
                return 'demo';
            }

            public function getIndexIdPattern(Source $source): string
            {
                return '{id}';
            }

            public function countItems(Source $source): ?int
            {
                return 0;
            }

            public function getItemIds(Source $source, int $offset, int $limit): array
            {
                return [];
            }

            public function createDocuments(Source $source, string $itemId): iterable
            {
                return [];
            }
        };
    }

    private function unregister(string $extensionPoint, callable $extension): void
    {
        $class = new ReflectionClass(rex_extension::class);
        /** @var array<string, array<int, list<array{callable, array}>>> $extensions */
        $extensions = $class->getStaticPropertyValue('extensions');
        foreach ($extensions[$extensionPoint] ?? [] as $level => $registered) {
            foreach ($registered as $i => $entry) {
                if ($entry[0] === $extension) {
                    unset($extensions[$extensionPoint][$level][$i]);
                }
            }
        }
        $class->setStaticPropertyValue('extensions', $extensions);
    }
}
