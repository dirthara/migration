---
id: refreshing-migrations
title: Refreshing migrations
sidebar_position: 9
description: How Migrator::refresh() rolls back every applied migration with down() and runs every migration again.
---

`Migrator::refresh()` rolls back every applied migration through its own `down()`, then runs every migration again
through `up()`, as one operation under one lock.

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$migrator->refresh(new MigrationConfiguration(['database/migrations']));
```

| Argument        | Type                     | Default | Meaning                                                      |
|-----------------|--------------------------|---------|--------------------------------------------------------------|
| `configuration` | `MigrationConfiguration` | none    | The migration directories and the locking choice of the run. |

Refresh is meant for development: checking that a set of migrations goes down and up again cleanly, or rebuilding a
database from migrations whose `down()` you trust.

## What a refresh does

1. Takes the migration lock, unless locking is turned off.
2. Loads the migrations of every directory into one set, checks the set, and resolves the connection every migration
   declares.
3. Creates the history table when it does not exist, and selects every applied migration, latest applied first.
4. Plans the rollback of all of them, as a [rollback](rollback.md) would.
5. Reverses them one by one: `beforeDown()`, `down()`, `afterDown()`.
6. When the history is empty, runs every migration again, as [`migrate()`](running-migrations.md) would:
   `beforeUp()`, `up()`, `afterUp()`.
7. Releases the lock.

## The rollback phase

The rollback phase reverses every applied migration, ignoring batches, in the opposite order to the one they were
applied in. It follows the history, as a [rollback](rollback.md#order) does: each `down()` runs on the connection the
history recorded, not on the one the migration would resolve to today.

Everything is planned before the first `down()` runs. A migration whose source is missing, whose index changed, whose
declared connection no longer matches the history, or whose recorded connection cannot be resolved fails the refresh
before anything is rolled back. The connection every migration would run on in the forward phase is resolved at the
same time, so a pending migration naming a connection that does not exist also stops the refresh before it starts.

## The forward phase

Once every migration has been rolled back, the history is empty, and the migrations run again exactly as a first
`migrate()` would: per connection, by index, each on the connection it resolves to now. The history is written anew,
and every migration is recorded in batch 1, whatever batches it was in before.

## An incomplete rollback

:::caution
Refresh only starts the forward phase when the rollback phase reversed every applied migration.
:::

Rollback hooks are honoured. When `beforeDown()` skips a migration, the rollback moves on to the next one; when it
stops, the rollback ends. Either way, migrations are still applied, and running every migration again on top of them
would not be a refresh. The refresh therefore throws a `Dirthara\Migration\Exception\MigrationRefreshException`
without running any migration again. Its `migrations` context lists the migrations that are still applied, in the
order they were applied. What was rolled back stays rolled back.

A migration that throws in `beforeDown()`, `down()`, or `afterDown()` ends the refresh the same way a failing
[rollback](rollback.md#reversing-a-migration) does: the migration is recorded in the history again, its exception
reaches the caller unchanged, and no migration runs again.

A failure in the forward phase behaves as it does in [`migrate()`](running-migrations.md#running-a-migration). The
migrations that ran before it stay applied in batch 1.

:::note
A refresh is not atomic. When it fails partway, the database holds what the phases completed before the failure. Fix
the cause, then migrate, or refresh again.
:::

## Locking

One [migration lock](locking.md) covers the whole refresh, from planning the rollback to recording the last migration
it runs again. It is not released between the phases, so no other runner can migrate or roll back in between. The
refresh respects the same `locking` option as a run.
