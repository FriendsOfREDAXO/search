<?php

use FriendsOfRedaxo\Search\Index\IndexRepository;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_index_repository_test extends TestCase
{
    public function testToBooleanQueryRequiresEveryWordWithPrefixMatch(): void
    {
        self::assertSame('+Hund* +Dackel*', IndexRepository::toBooleanQuery('  Hund   Dackel '));
    }

    public function testToBooleanQueryStripsUserOperators(): void
    {
        self::assertSame('+hund* +katze*', IndexRepository::toBooleanQuery('-hund +"katze"*'));
    }

    public function testToBooleanQueryIsEmptyForBlankInput(): void
    {
        self::assertSame('', IndexRepository::toBooleanQuery(' + - '));
    }
}
