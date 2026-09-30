---
id: locking
title: Migration locking
sidebar_position: 10
description: How migration runs are locked against each other, and when to turn locking off.
---

A migration run and a rollback both hold the same named database lock from start to finish, so two runners never
plan, apply, or reverse the same migrations at the same time. For a migration run, the lock covers reading the
migration history, planning, choosing the batch number, running every migration, and recording each one. For a
[rollback](rollback.md), it covers reading the history, selecting the migrations to reverse, planning, running every
`down()`, and removing each reversed migration from the history. The lock is released when the run ends, whether it
finishes, finds nothing to do, is stopped by a migration, or fails.

A [refresh](refreshing-migrations.md) holds the lock once, from planning its rollback to recording the last migration
it runs again, and does not release it between rolling back and migrating.

A [preview](previewing-migrations.md) takes no lock: it only reads, and its result can be out of date as soon as it
is returned.

## How the lock behaves

- **Locking is on by default.** A runner that has not been told otherwise takes the lock.
- **A second runner waits.** When another run or rollback holds the lock, a new one blocks until it is released,
  then plans against the history the first one left behind. It does not fail because a legitimate run is in progress.
- **The lock lives on the migration history connection.** The migration repository's connection decides what has
  been applied and which batch comes next, so the lock is taken there, not on the default connection or on the
  connections individual migrations run against.
- **The lock name is fixed.** Every runner uses the lock `dirthara:migrations`, so every runner of an application
  excludes every other one.

A failure to take or release the lock is reported as a `Dirthara\Migration\Exception\MigrationLockException`,
with the lock name and the connection name in its context.

When a run or rollback fails, its own exception is what reaches the caller. The lock is still released, but a failure
to release it after a failed run is not reported, because it would hide why the run failed. A lock that could not be
released stays held until its database session ends, which normally happens when the process does.

## Configuration

`Dirthara\Migration\Config\MigrationConfiguration` controls a run and a rollback:

| Option        | Type           | Default | Meaning                                                                 |
|---------------|----------------|---------|-------------------------------------------------------------------------|
| `directories` | `list<string>` | none    | The directories to load migrations from. An empty list loads nothing.   |
| `locking`     | `bool`         | `true`  | Whether the run holds the migration lock. Turn it off only on purpose. |

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$migrator->migrate(new MigrationConfiguration(['database/migrations']));
```

## SQLite

:::caution
SQLite has no named locks, so a run on a SQLite history connection cannot be locked. With the default configuration,
such a run fails with a `MigrationLockException` before it reads or changes anything, rather than running unlocked
without saying so.
:::

When a single process migrates a SQLite database, for example during development or in a test suite, running without
the lock is an acceptable trade-off. Say so explicitly:

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$migrator->migrate(new MigrationConfiguration(['database/migrations'], locking: false));
```

Without the lock, nothing stops two runners from applying the same migrations at the same time. Only turn it off when
you can guarantee that a single runner migrates the database.
