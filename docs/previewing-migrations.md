---
id: previewing-migrations
title: Previewing migrations
sidebar_position: 8
description: What Migrator::preview() and Migrator::previewRollback() report, and what a preview does not do.
---

A preview shows the plan of a run or a rollback: which migrations it would attempt, in which order, on which
connection, and in which batch. It is planning, not a simulated run.

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$configuration = new MigrationConfiguration(['database/migrations']);

// What migrate() would run.
$pending = $migrator->preview($configuration);

// What rollback() would reverse: the latest batch, or the latest two migrations.
$batch = $migrator->previewRollback($configuration);
$steps = $migrator->previewRollback($configuration, steps: 2);

foreach ($pending as $migration) {
    echo "{$migration->name} on {$migration->connection}, batch {$migration->batch}\n";
}
```

| Method              | Arguments                                   | Returns                  | Previews                     |
|---------------------|---------------------------------------------|--------------------------|------------------------------|
| `preview()`         | `MigrationConfiguration`                    | `list<MigrationPreview>` | [`migrate()`](running-migrations.md) |
| `previewRollback()` | `MigrationConfiguration`, `steps` (`int` or `null`, default `null`) | `list<MigrationPreview>` | [`rollback()`](rollback.md) with the same `steps` |

## No migration code runs

A migration is arbitrary PHP, so there is no safe way to run it and call the result a dry run. A preview therefore
never calls `up()` or `down()`, never calls `beforeUp()`, `afterUp()`, `beforeDown()`, or `afterDown()`, never opens
a transaction to roll back afterwards, and never executes the SQL a migration would generate.

What it does is everything a run or rollback does before the first migration runs: it loads the migrations of every
directory, [checks the set](running-migrations.md#checks), compares it with the history, resolves each migration's
connection, and selects what would run. A failure in any of these fails the preview with the exception the run or
rollback would throw, such as a `MigrationPlanException` or a `MigrationRollbackException`.

Loading a migration file does execute the file itself, which returns the migration object. Keep work out of the file
body and in `up()` and `down()`.

## Hooks are not predicted

:::caution
A preview shows what Dirthara would attempt, not a guarantee that hooks will allow every listed migration to execute.
:::

A migration whose `beforeUp()` would skip or stop it is still listed while it is pending, and a migration whose
`beforeDown()` would skip or stop its rollback is still listed when a rollback would select it. The hooks only decide
when the migration actually runs.

## The database is not changed

A preview only reads. It does not write to the migration history, and it does not create the history table: against
a database without one, `preview()` treats the history as empty and lists every migration, and `previewRollback()`
lists nothing. The table stays missing until a run or rollback creates it.

## No lock is taken

A preview does not take the [migration lock](locking.md), whatever the `locking` option says, so it works on a SQLite
history connection without turning locking off, and never waits for a running migration.

:::note
A preview describes the moment it was made. Another process can migrate or roll back immediately afterwards, so the
next run or rollback may attempt something other than what the preview listed. Take the plan as information, not as
a reservation.
:::

## The preview

Each entry is a `Dirthara\Migration\ValueObject\MigrationPreview`, in the order the operation would attempt them.

| Property      | Type                 | Going up (`preview()`)                                   | Going down (`previewRollback()`)                 |
|---------------|----------------------|----------------------------------------------------------|--------------------------------------------------|
| `name`        | `string`             | The migration's name.                                    | The name the history recorded.                   |
| `index`       | `string`             | The migration's index.                                   | The index the history recorded.                  |
| `description` | `string` or `null`   | The migration's description.                             | The description the history recorded.            |
| `connection`  | `string`             | The connection it resolves to now, never `null`.         | The connection it ran on, from the history.      |
| `direction`   | `MigrationDirection` | `MigrationDirection::Up`.                                | `MigrationDirection::Down`.                      |
| `batch`       | `int`                | The batch the run would record it in.                    | The batch it was applied in.                     |
| `path`        | `string`             | The path of its migration file.                          | The path of its migration file.                  |

`Dirthara\Migration\MigrationDirection` has the cases `Up` and `Down`.

## Previewing a run

`preview()` lists the pending migrations exactly as [`migrate()`](running-migrations.md#connections-and-order)
orders them: per connection, in connection name order, and within a connection by index, then by file path. Applied
migrations are left out. Every entry has the same batch, the next one the history would give out, or 1 without a
history.

## Previewing a rollback

`previewRollback()` selects what [`rollback()`](rollback.md) would reverse, with the same `steps` argument:

- without `steps`, every migration of the latest batch;
- with `steps`, exactly that many of the latest applied migrations, ignoring batches and across every connection.

Entries come latest applied first, the order a rollback reverses them in, and name the connection the history
recorded, where `down()` would run. `steps` below 1 fails with the same `InvalidRollbackStepsException` as
`rollback()`. With nothing applied, the preview is empty.
