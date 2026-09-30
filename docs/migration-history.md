---
id: migration-history
title: Migration history
sidebar_position: 10
description: The migration history table, what it records, and the repository that reads and writes it.
---

The migration history records every applied migration. `Dirthara\Migration\MigrationRepository` owns it: the table,
its queries, and the [migration lock](locking.md), all on the history connection.

```php
use Dirthara\Migration\MigrationRepository;

$repository = new MigrationRepository($database->using('default'), $schema->using('default'), 'migrations');
```

The third argument names the table. The table is created the first time a run or rollback needs it.

## The table

| Column        | Type                       | Holds                                                                  |
|---------------|----------------------------|------------------------------------------------------------------------|
| `id`          | auto-incrementing integer  | The order migrations were applied in.                                  |
| `name`        | string, unique             | The migration name, as the migration spells it.                        |
| `index`       | string                     | The migration's index when it ran.                                     |
| `description` | text, nullable             | The migration's description when it ran.                               |
| `connection`  | string                     | The connection the migration actually ran on, never `null`.            |
| `batch`       | unsigned integer           | The batch that applied the migration.                                  |
| `applied_at`  | date and time              | When the migration was applied, in UTC.                                |

Names are stored as written but compared case-insensitively. Migrations are rolled back in reverse `id` order, which is
the order they were applied in, not the order of their indexes.

:::caution
Change the history only through the migrator. Removing or editing rows by hand makes the history disagree with the
database, and the next run or rollback acts on what the history says.
:::

## The repository

The migrator uses the repository internally. Its public methods:

| Method              | Does                                                                              |
|---------------------|-----------------------------------------------------------------------------------|
| `initialise()`      | Creates the table when it does not exist.                                         |
| `record()`          | Records an `AppliedMigration`.                                                    |
| `forget()`          | Removes a migration by name.                                                      |
| `hasRun()`          | Tells whether the history holds a migration with that name.                       |
| `getApplied()`      | Returns every applied migration, in the order they were applied.                  |
| `getLatestBatch()`  | Returns the migrations of the latest batch, latest applied first.                 |
| `getLatest($count)` | Returns up to `$count` of the latest applied migrations, latest applied first.    |
| `getNextBatch()`    | Returns the number the next batch gets.                                           |
| `acquireLock()`     | Takes the migration lock on the history connection.                               |
| `releaseLock()`     | Releases it.                                                                      |

Applied migrations come back as `Dirthara\Migration\ValueObject\AppliedMigration`, with `name`, `index`,
`description`, `connection`, `batch`, and `appliedAt`, a `DateTimeImmutable` in UTC. The `id` stays inside the
repository. A database failure is a `MigrationRepositoryException`, and a lock failure a `MigrationLockException`.
