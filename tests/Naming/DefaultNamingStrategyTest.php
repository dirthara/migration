<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Naming;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Migration\Naming\DefaultNamingStrategy;
use Dirthara\Migration\Exception\MigrationCreatorException;

final class DefaultNamingStrategyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'StudlyCase' => ['CreateUsersTable', 'create_users_table'];
        yield 'camelCase' => ['createUsersTable', 'create_users_table'];
        yield 'words' => ['create users table', 'create_users_table'];
        yield 'kebab-case' => ['create-users-table', 'create_users_table'];
        yield 'snake_case' => ['create_users_table', 'create_users_table'];
        yield 'acronym' => ['AddHTTPHeadersToRequests', 'add_http_headers_to_requests'];
        yield 'digits' => ['createUsers2Table', 'create_users2_table'];
        yield 'surrounding and repeated separators' => ['  Add index__to  users! ', 'add_index_to_users'];
    }

    #[Test]
    #[DataProvider('names')]
    public function it_names_the_file_by_its_index_and_snake_cased_name(string $name, string $snakeCase): void
    {
        $fileName = new DefaultNamingStrategy()->fileName($name, '2026_09_30_101500');

        self::assertSame('2026_09_30_101500_' . $snakeCase . '.php', $fileName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'punctuation' => ['!?-'];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_a_name_without_letters_or_digits(string $name): void
    {
        try {
            new DefaultNamingStrategy()->fileName($name, '2026_09_30_101500');
        } catch (MigrationCreatorException $exception) {
            self::assertSame(['name' => $name], $exception->context);

            return;
        }

        self::fail('The name was not rejected.');
    }

    #[Test]
    public function it_escapes_control_characters_in_the_rejection_message(): void
    {
        try {
            new DefaultNamingStrategy()->fileName("\n", '2026_09_30_101500');
        } catch (MigrationCreatorException $exception) {
            self::assertSame(
                'Migration name "\\n" is invalid: a migration name starts with an ASCII letter and contains only ASCII '
                . 'letters, digits, and underscores.',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('The name was not rejected.');
    }
}
