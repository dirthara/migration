---
id: running-migrations
title: Running migrations
sidebar_position: 6
description: What Migrator::migrate() checks, how it orders and runs migrations, and what happens when one fails.
---

`Dirthara\Migration\Migrator::migrate()` runs every pending migration of the configured directories.

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$migrator->migrate(new MigrationConfiguration([
    'src/Users/Infrastructure/Migrations',
    'src/Billing/Infrastructure/Migrations',
]));
```

`Dirthara\Migration\Config\MigrationConfiguration` configures a run and a rollback alike:

| Option        | Type           | Default | Meaning                                                                          |
|---------------|----------------|---------|----------------------------------------------------------------------------------|
| `directories` | `list<string>` | none    | The directories to load migrations from. An empty list loads nothing.            |
| `locking`     | `bool`         | `true`  | Whether the run holds the [migration lock](locking.md). Turn it off only on purpose. |

## What a run does

1. Takes the migration lock, unless locking is turned off.
2. Loads the migrations of every directory into one set, and checks the set.
3. Creates the history table when it does not exist, and reads the history.
4. Compares every loaded migration with the history, and keeps the ones that have not run.
5. Resolves the connection of each pending migration and groups the migrations per connection.
6. Runs each group in order, recording every migration in one batch.
7. Releases the lock.

## Checks

Before it reads the history, a run checks the loaded migrations as one set, across every directory:

- every name is a [valid migration name](writing-migrations.md#names);
- no index is empty or only whitespace;
- no explicit connection is empty or only whitespace;
- no two migrations share a name, compared case-insensitively, even when they live in different directories.

It then compares the set with the history. A migration whose name the history holds has run, and runs no more, but:

- its index must still match the history;
- when it declares a connection by name, that must be the connection the history recorded.

A changed description does not matter. A migration the history holds but no directory does is left alone, so a
removed module does not break the run. A history that holds two names differing only in case fails the run.

Every failed check is a `Dirthara\Migration\Exception\MigrationPlanException`, thrown before any migration runs.

## Connections and order

A migration without a connection runs on the default connection, and one with a connection runs on that one.
Pending migrations are grouped by the connection they resolve to. Within a group they run by index, and by file path
when two share an index, however their directories are ordered.

:::caution
Order is only guaranteed within a connection. A migration must not depend on a migration that runs on another
connection having run before or after it.
:::

## Batches

Every migration a run applies is recorded with the same batch number, the highest batch in the history plus one,
whatever connection it ran on. A run with nothing pending records nothing. A [batch rollback](rollback.md) reverses
the latest batch.

## Running a migration

For each pending migration, in order:

1. `beforeUp()` runs, when the migration implements `MigrationHooks`, and decides whether to continue.
2. The migration is recorded in the history.
3. `up()` runs, then `afterUp()`.

The history is written before the migration runs, so a failing history write stops the run before the migration
touches anything. When `beforeUp()`, `up()`, or `afterUp()` throws, the migration is removed from the history again and
the exception reaches the caller unchanged; the migrations before it stay applied, and the ones after it do not run.
The package does not wrap migrations in transactions, so a migration that fails halfway leaves whatever it already
changed.

:::caution
When a migration fails and removing it from the history fails as well, the run throws a
`MigrationRepositoryException` whose chain of previous exceptions ends with the migration's own failure. The history
then records a migration that did not complete. That takes two failures in a row, typically losing the history
connection while the migration fails.
:::

A skipped migration is not recorded and runs again next time. A stop ends the run without recording the stopped
migration; what ran before it stays applied in the current batch, and a later run starts a new batch.
