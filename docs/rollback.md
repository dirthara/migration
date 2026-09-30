---
id: rollback
title: Rolling back migrations
sidebar_position: 4
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
the default connection is today. A migration that declares a connection must still declare the recorded one, and its
index must still match the history, or the rollback fails before anything runs.

## Hooks

A migration that implements `Dirthara\Migration\Contract\MigrationHooks` decides in `beforeDown()` what happens:

| Decision   | Effect                                                                                     |
|------------|--------------------------------------------------------------------------------------------|
| `continue` | `down()` and `afterDown()` run, then the migration is removed from the history.            |
| `skip`     | The migration stays applied, and the rollback moves on to the next selected migration.     |
| `stop`     | The migration stays applied, and the rollback ends without reversing anything after it.     |

A migration leaves the history only after `down()` and `afterDown()` have both succeeded. When `beforeDown()`,
`down()`, or `afterDown()` throws, the migration stays applied and the exception reaches the caller.

:::note
The migrations a step rollback reverses are chosen before any of them runs. When one of them skips, the rollback does
not reach further back to make up the number.
:::

## Missing migrations

A rollback needs a migration's source to run its `down()`. When a selected migration is no longer in any of the
configured directories, the rollback fails with a `Dirthara\Migration\Exception\MigrationRollbackException` before
anything runs. The history keeps the migration.

## Batches after a step rollback

A step rollback does not renumber the batches that remain. When batch 4 held A, B, and C and a step rollback removed
C, A and B stay in batch 4. Migrating C again records it in the next batch, 5, so the next batch rollback reverses
only C.

## Locking

A rollback holds the same migration lock as a migration run, from reading the history to removing the last reversed
migration from it, and respects the same `locking` option. See [migration locking](locking.md).
