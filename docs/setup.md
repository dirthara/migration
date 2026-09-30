---
id: setup
title: Setting up
sidebar_position: 3
description: Building the loader, repository, and migrator from Dirthara database and schema services.
---

The migrator is built from services the rest of the framework already has: a `Dirthara\Database\Database` and a
`Dirthara\Schema\Schema` over the application's connections, a Flysystem filesystem that holds the migrations, and a
PSR-20 clock.

```php
use Dirthara\Migration\Migrator;
use League\Flysystem\Filesystem;
use Dirthara\Migration\MigrationLoader;
use Dirthara\Migration\MigrationCreator;
use Dirthara\Migration\MigrationRepository;
use Dirthara\Migration\MigrationSourceLoader;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Dirthara\Migration\Naming\DefaultNamingStrategy;

// $database is a Dirthara\Database\Database, $schema a Dirthara\Schema\Schema,
// and $clock a Psr\Clock\ClockInterface.

$filesystem = new Filesystem(new LocalFilesystemAdapter('/path/to/application'));

$loader = new MigrationLoader($filesystem, new MigrationSourceLoader($filesystem));

$repository = new MigrationRepository(
    $database->using('default'),
    $schema->using('default'),
    'migrations',
);

$migrator = new Migrator($loader, $repository, $database, $schema, $clock);

$creator = new MigrationCreator($clock, $filesystem, new DefaultNamingStrategy());
```

| Service                 | Role                                                                                         |
|-------------------------|----------------------------------------------------------------------------------------------|
| `MigrationSourceLoader` | Loads one migration file from the filesystem. See [loading migrations](loading-migrations.md). |
| `MigrationLoader`       | Loads every migration file of a directory.                                                   |
| `MigrationRepository`   | Reads and writes the migration history table. See [migration history](migration-history.md). |
| `Migrator`              | Runs, previews, rolls back, and rebuilds from migrations. See [running migrations](running-migrations.md). |
| `MigrationCreator`      | Writes new migration files. See [creating migrations](creating-migrations.md).              |

## The history connection

The `ConnectedDatabase` and `ConnectedSchema` given to `MigrationRepository` choose the history connection: the
connection that holds the migration history table and the [migration lock](locking.md). Give both the same connection.
It does not have to be the default connection, and migrations themselves can run on any connection.

## Directories

Migration directories are paths within the filesystem, not absolute paths on disk. With the filesystem above,
`src/Users/Infrastructure/Migrations` names `/path/to/application/src/Users/Infrastructure/Migrations`. Which
directories exist is up to the application; the package only ever receives a list of them.
