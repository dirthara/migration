<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use Dirthara\Schema\Schema;
use Dirthara\Database\Database;
use PHPUnit\Framework\TestCase;
use League\Flysystem\FileAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemReader;
use Dirthara\Migration\MigrationLoader;
use Dirthara\Migration\MigrationRepository;
use Dirthara\Migration\MigrationSourceLoader;
use Dirthara\Database\Connection\Driver\Driver;
use Dirthara\Schema\Grammar\SQLiteSchemaGrammar;
use Dirthara\Schema\Grammar\SchemaGrammarResolver;
use Dirthara\Database\Connection\ConnectionFactory;
use Dirthara\Database\Connection\ConnectionManager;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\SQLiteDriver;
use Dirthara\Database\Query\Grammar\SQLiteQueryGrammar;
use Dirthara\Database\Query\Grammar\QueryGrammarResolver;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;

use function trim;
use function strtr;
use function dirname;
use function array_map;
use function array_keys;
use function var_export;
use function array_filter;
use function array_values;
use function array_key_exists;

/**
 * @require-extends TestCase
 */
trait MigrationEnvironment
{
    private const string HISTORY_TABLE = 'migrations';

    private Database $database;

    private Schema $schema;

    private MigrationRepository $repository;

    private MigrationLoader $loader;

    /**
     * @var array<string, string>
     */
    private array $files = [];

    private function setUpEnvironment(?Driver $driver = null): void
    {
        $this->files = [];

        $manager = new ConnectionManager(
            new ConnectionFactory([$driver ?? new SQLiteDriver(new StandardTransactionGrammar(new SavepointPrefix()))]),
            [
                new ConnectionConfig(driver: DriverName::SQLite, name: 'primary', database: ':memory:'),
                new ConnectionConfig(driver: DriverName::SQLite, name: 'reporting', database: ':memory:'),
            ],
            default: 'primary',
        );

        $this->database = new Database($manager, new QueryGrammarResolver([
            DriverName::SQLite->value => new SQLiteQueryGrammar(),
        ]));
        $this->schema = new Schema($this->database, new SchemaGrammarResolver([
            DriverName::SQLite->value => new SQLiteSchemaGrammar(),
        ]));
        $this->repository = new MigrationRepository(
            $this->database->using(),
            $this->schema->using(),
            self::HISTORY_TABLE,
        );

        $filesystem = $this->createStub(FilesystemReader::class);
        $filesystem
            ->method('listContents')
            ->willReturnCallback(fn(string $location): DirectoryListing => new DirectoryListing(array_values(array_map(
                static fn(string $path): FileAttributes => new FileAttributes($path),
                array_filter(
                    array_keys($this->files),
                    static fn(string $path): bool => dirname($path) === trim($location, characters: '/'),
                ),
            ))));
        $filesystem
            ->method('fileExists')
            ->willReturnCallback(fn(string $path): bool => array_key_exists($path, $this->files));
        $filesystem->method('read')->willReturnCallback(fn(string $path): string => $this->files[$path]);

        $this->loader = new MigrationLoader($filesystem, new MigrationSourceLoader($filesystem));
    }

    private function addMigration(
        string $path,
        string $name,
        string $index,
        ?string $connection = null,
        ?string $description = null,
        string $up = '',
        ?string $beforeUp = null,
        string $afterUp = '',
        string $down = '',
        ?string $beforeDown = null,
        string $afterDown = '',
    ): void {
        $hooked = $beforeUp !== null || $beforeDown !== null;

        $hooks = <<<'PHP'

                public function beforeUp(MigrationContext $context, MigrationDecision &$decision): void
                {
                    {{ beforeUp }}
                }

                public function afterUp(MigrationContext $context): void
                {
                    {{ afterUp }}
                }

                public function beforeDown(MigrationContext $context, MigrationDecision &$decision): void
                {
                    {{ beforeDown }}
                }

                public function afterDown(MigrationContext $context): void
                {
                    {{ afterDown }}
                }
            PHP;

        $source = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Dirthara\Migration\Contract\Migration;
            use Dirthara\Migration\Contract\MigrationHooks;
            use Dirthara\Migration\Tests\Fixtures\MigrationLog;
            use Dirthara\Migration\ValueObject\MigrationContext;
            use Dirthara\Migration\ValueObject\MigrationDecision;

            return new class implements Migration{{ implements }} {
                public string $name = {{ name }};

                public ?string $description = {{ description }};

                public string $index = {{ index }};

                public ?string $connection = {{ connection }};

                public function up(MigrationContext $context): void
                {
                    {{ up }}
                }

                public function down(MigrationContext $context): void
                {
                    {{ down }}
                }
            {{ hooks }}
            };
            PHP;

        $this->files[$path] = strtr($source, [
            '{{ implements }}' => $hooked ? ', MigrationHooks' : '',
            '{{ name }}' => var_export($name, return: true),
            '{{ description }}' => $description === null ? 'null' : var_export($description, return: true),
            '{{ index }}' => var_export($index, return: true),
            '{{ connection }}' => $connection === null ? 'null' : var_export($connection, return: true),
            '{{ up }}' => $up,
            '{{ down }}' => $down,
            '{{ hooks }}' => $hooked
                ? strtr($hooks, [
                    '{{ beforeUp }}' => $beforeUp ?? '',
                    '{{ afterUp }}' => $afterUp,
                    '{{ beforeDown }}' => $beforeDown ?? '',
                    '{{ afterDown }}' => $afterDown,
                ])
                : '',
        ]);
    }
}
