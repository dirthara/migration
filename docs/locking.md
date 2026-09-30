---
id: locking
title: Migration locking
sidebar_position: 3
description: How migration runs are locked against each other, and when to turn locking off.
---

A migration run holds a named database lock from start to finish, so two runners never plan or apply the same
migrations at the same time. The lock covers the whole run: reading the migration history, planning, choosing the
batch number, running every migration, and recording each one. It is released when the run ends, whether the run
finishes, finds nothing to do, is stopped by a migration, or fails.

## How the lock behaves

- **Locking is on by default.** A runner that has not been told otherwise takes the lock.
- **A second runner waits.** When another run holds the lock, a new run blocks until that run releases it, then
  plans against the history the first run left behind. It does not fail because a legitimate run is in progress.
- **The lock lives on the migration history connection.** The migration repository's connection decides what has
  been applied and which batch comes next, so the lock is taken there, not on the default connection or on the
  connections individual migrations run against.
- **The lock name is fixed.** Every runner uses the lock `dirthara:migrations`, so every runner of an application
  excludes every other one.

A failure to take or release the lock is reported as a `Dirthara\Migration\Exception\MigrationLockException`,
with the lock name and the connection name in its context.

## Configuration

`Dirthara\Migration\Config\MigrationConfiguration` controls a run:

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
