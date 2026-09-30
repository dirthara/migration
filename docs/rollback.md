---
id: rollback
title: Rolling back migrations
sidebar_position: 7
description: How batch and step rollback select, order, and reverse applied migrations.
---

`Migrator::rollback()` reverses applied migrations by running their `down()` methods. It has two modes, which answer
different questions.

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$configuration = new MigrationConfiguration(['database/migrations']);

// Roll back the latest batch.
$migrator->rollback($configuration);

// Roll back the latest two applied migrations, whatever their batches.
$migrator->rollback($configuration, steps: 2);
```

| Argument        | Type                     | Default | Meaning                                                        |
|-----------------|--------------------------|---------|----------------------------------------------------------------|
| `configuration` | `MigrationConfiguration` | none    | The migration directories and the locking choice of the run.   |
| `steps`         | `int` or `null`          | `null`  | `null` rolls back the latest batch; a number rolls back that many of the latest applied migrations. |

## Batch rollback

Without `steps`, a rollback reverses every migration of the latest batch: the migrations one `migrate()` call
applied. That undoes a release as a whole, which makes it the operation to use when deploying.

## Step rollback

With `steps`, a rollback reverses exactly that many of the most recently applied migrations, ignoring batches.
`steps: 1` reverses the single latest migration, across every connection, not one per connection. Asking for more
steps than there are applied migrations rolls back all of them.

Step rollback is meant for local development: undoing the migration you are working on, changing it, and running it
again, without touching the rest of its batch.

`steps` must be at least 1. Zero or a negative number fails with a
`Dirthara\Migration\Exception\InvalidRollbackStepsException` before anything runs.

## Order

A rollback reverses migrations in the opposite order to the one they were applied in, latest first. The migration
history records that order as it happens, so a rollback follows the history, not the migrations' indexes.

## Where down() runs

A migration is rolled back on the connection the history recorded when it was applied, which is where its `up()` ran.
Its current declaration does not decide that: a migration that declares no connection is not rolled back on whatever
the default connection is today. A migration that declares a connection by name must still declare the recorded one,
and its index must still match the history, or the rollback fails before anything runs.

## Reversing a migration

For each selected migration, in order:

1. `beforeDown()` runs, when the migration implements `MigrationHooks`, and decides whether to continue.
2. The migration is removed from the history.
3. `down()` runs, then `afterDown()`.

| Decision   | Effect                                                                                     |
|------------|--------------------------------------------------------------------------------------------|
| `continue` | The migration is removed from the history, then `down()` and `afterDown()` run.            |
| `skip`     | The migration stays applied, and the rollback moves on to the next selected migration.     |
| `stop`     | The migration stays applied, and the rollback ends without reversing anything after it.     |

The history is changed before the migration runs, so a failing history write stops the rollback before `down()`
touches anything. When `beforeDown()`, `down()`, or `afterDown()` throws, the migration is recorded in the history again
with its original name, index, description, connection, batch, and time, and the exception reaches the caller
unchanged. The migrations reversed before it stay reversed, and the ones after it are not reversed.

:::caution
When `down()` fails and restoring the history fails as well, the rollback throws a `MigrationRepositoryException`
whose chain of previous exceptions ends with the migration's own failure, and the history no longer records a
migration whose `down()` did not complete.
:::

:::note
A restored migration is recorded as a new history entry, so it counts as applied after every entry that is still in
the history. That changes nothing unless an earlier migration in the same rollback was skipped: the restored
migration then sorts after the skipped one, and a later step rollback reverses it first.
:::

## Selection is fixed

The migrations a rollback reverses are chosen before any of them runs. When one of them skips, a step rollback does
not reach further back to make up the number.

A skipped or stopped migration stays in its batch. The next batch rollback selects the latest batch again, including
that migration, and its `beforeDown()` decides once more.

## Missing migrations

A rollback needs a migration's source to run its `down()`. When a selected migration is no longer in any of the
configured directories, the rollback fails with a `Dirthara\Migration\Exception\MigrationRollbackException` before
anything runs. The history keeps the migration.

## Checks

A rollback loads and checks every migration of the configured directories, as a [run](running-migrations.md#checks)
does, even to reverse a single step. One invalid migration file anywhere in the directories therefore stops every
rollback until it is fixed.

Unlike a run, a rollback does not check the history for names that differ only in case: it reverses the selected
entries and removes each by the exact name the history recorded.

## Batches after a step rollback

A step rollback does not renumber the batches that remain. When batch 4 held A, B, and C and a step rollback removed
C, A and B stay in batch 4. Migrating C again records it in the next batch, 5, so the next batch rollback reverses
only C.

## Locking

A rollback holds the same migration lock as a migration run, from reading the history to removing the last reversed
migration from it, and respects the same `locking` option. See [migration locking](locking.md).
