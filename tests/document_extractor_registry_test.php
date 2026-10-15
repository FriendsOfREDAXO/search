<?php

use FriendsOfRedaxo\Search\Extract\DocumentExtractor;
use FriendsOfRedaxo\Search\Extract\DocumentExtractorRegistry;
use FriendsOfRedaxo\Search\Extract\ExtractedDocument;
use FriendsOfRedaxo\Search\Extract\ExtractionContext;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_document_extractor_registry_test extends TestCase
{
    /** @var list<callable> */
    private array $registered = [];

    protected function setUp(): void
    {
        DocumentExtractorRegistry::reset();
    }

    protected function tearDown(): void
    {
        // Angemeldete Zuhoerer wuerden sonst in die folgenden Tests desselben Laufs lecken.
        foreach ($this->registered as $extension) {
            $this->unregister('SEARCH_DOCUMENT_EXTRACTORS', $extension);
        }
        $this->registered = [];
        DocumentExtractorRegistry::reset();
    }

    public function testBuiltInExtractorsAreRegistered(): void
    {
        $keys = array_map(static fn (DocumentExtractor $e): string => $e->getKey(), DocumentExtractorRegistry::all());

        self::assertContains('text', $keys);
        self::assertContains('office', $keys);
        self::assertContains('html', $keys);
    }

    public function testSupportedExtensionsAreSortedAndUnique(): void
    {
        $extensions = DocumentExtractorRegistry::supportedExtensions();

        self::assertContains('docx', $extensions);
        self::assertContains('pdf', $extensions);
        self::assertSame(array_values(array_unique($extensions)), $extensions);
        $sorted = $extensions;
        sort($sorted);
        self::assertSame($sorted, $extensions);
    }

    public function testUnavailableExtractorsAreSkipped(): void
    {
        $this->register(new class extends TestExtractorDouble {
            public function getKey(): string
            {
                return 'never';
            }

            public function isAvailable(): bool
            {
                return false;
            }
        });

        $keys = array_map(static fn (DocumentExtractor $e): string => $e->getKey(), DocumentExtractorRegistry::all());

        self::assertNotContains('never', $keys);
    }

    public function testHigherPriorityWins(): void
    {
        $this->register(new class extends TestExtractorDouble {
            public function getKey(): string
            {
                return 'besser';
            }

            public function getExtensions(): array
            {
                return ['pdf'];
            }

            public function getPriority(): int
            {
                return 100;
            }
        });

        // Ohne die Sortierung nach Prioritaet koennte ein Projekt den mitgelieferten
        // PDF-Weg nicht ersetzen.
        self::assertSame('besser', DocumentExtractorRegistry::forFile('pdf')?->getKey());
    }

    public function testUnknownExtensionHasNoExtractor(): void
    {
        self::assertNull(DocumentExtractorRegistry::forFile('xyz'));
    }

    public function testWrongTypeIsRejected(): void
    {
        $extension = static function (rex_extension_point $ep) {
            $extractors = $ep->getSubject();
            $extractors[] = new stdClass();

            return $extractors;
        };
        rex_extension::register('SEARCH_DOCUMENT_EXTRACTORS', $extension);
        $this->registered[] = $extension;
        DocumentExtractorRegistry::reset();

        $this->expectException(rex_exception::class);
        DocumentExtractorRegistry::all();
    }

    private function register(DocumentExtractor $extractor): void
    {
        $extension = static function (rex_extension_point $ep) use ($extractor) {
            $extractors = $ep->getSubject();
            $extractors[] = $extractor;

            return $extractors;
        };
        rex_extension::register('SEARCH_DOCUMENT_EXTRACTORS', $extension);
        $this->registered[] = $extension;
        DocumentExtractorRegistry::reset();
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

/** @internal */
abstract class TestExtractorDouble extends DocumentExtractor
{
    public function getLabel(): string
    {
        return 'Test';
    }

    public function getExtensions(): array
    {
        return ['zzz'];
    }

    public function extract(string $path, ExtractionContext $context): ?ExtractedDocument
    {
        return new ExtractedDocument('Test');
    }
}
