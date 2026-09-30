---
id: fresh-migrations
title: Fresh migrations
sidebar_position: 10
description: How Migrator::fresh() drops every table on the migration connections and runs every migration again.
---

:::danger
`fresh()` drops **every user table** on every connection the migrations run on, whether or not a migration created
it, together with all the data in them. It does not roll anything back and nothing it removes can be recovered. Only
point it at databases you mean to empty, such as a local development or test database.
:::

`Migrator::fresh()` empties the schemas the migrations run on and runs every migration again from the beginning.

```php
use Dirthara\Migration\Config\MigrationConfiguration;

$migrator->fresh(new MigrationConfiguration(['database/migrations']));
```

| Argument        | Type                     | Default | Meaning                                                      |
|-----------------|--------------------------|---------|--------------------------------------------------------------|
| `configuration` | `MigrationConfiguration` | none    | The migration directories and the locking choice of the run. |

## Fresh or refresh

Both rebuild the database from its migrations, but they get rid of the old schema differently.

| Operation                                     | Removes the schema with                         | Runs `down()` and its hooks |
|-----------------------------------------------|-------------------------------------------------|-----------------------------|
| [`refresh()`](refreshing-migrations.md)       | Each applied migration's own `down()`.          | Yes                         |
| `fresh()`                                     | `dropAll()` from `dirthara/schema`, per connection. | No                      |

Fresh is the one to use when rollback code is broken, when a local schema has drifted from its migrations, when
migrations changed while you were developing them, or when you simply want a clean schema.

## What a fresh run does

1. Takes the migration lock, unless locking is turned off.
2. Loads the migrations of every directory into one set, and checks the set.
3. Resolves the connection of every migration to the connection it runs on.
4. Drops every table on each of those connections, one connection at a time, in connection name order.
5. Drops the migration history table.
6. Runs every migration, as a first [`migrate()`](running-migrations.md) would, recording each in batch 1.
7. Releases the lock.

`beforeDown()`, `down()`, and `afterDown()` never run. The only migration code a fresh run calls is that of the
forward run: `beforeUp()`, `up()`, and `afterUp()`.

## Which connections are reset

The connections come from the migrations, never from the history. Every migration's connection is resolved the way a
run resolves it: a declared connection by name, and no connection as the default connection. Each connection they
resolve to is reset once, however many migrations run on it and whether they reach it by name or as the default.

A configured connection that no migration of the configured directories runs on is not touched, even when the history
records migrations on it.

Everything is resolved before the first table is dropped. An unreadable migration file, a set that breaks a
[check](running-migrations.md#checks), or a migration naming a connection that is not configured fails the fresh run
with the same exception a run would throw, and nothing is dropped.

:::caution
Connections are opened lazily. A configured connection whose database cannot be reached is only found out when its
tables are dropped, which can be after other connections were emptied.
:::

## What is dropped

Each connection is emptied with `Schema::dropAll()` from [`dirthara/schema`](https://github.com/dirthara/schema),
which drops every user table in the database or schema the connection points at: the tables migrations created, and
also any other table there, including tables that were never part of a migration. Which objects count as user tables,
and what is left alone, such as views, is up to `dropAll()`; see its documentation in `dirthara/schema`. Migration
does not narrow it down to the tables it thinks migrations created.

## The history

A fresh run leaves a history that matches the schemas it rebuilt. When the history table lives on one of the reset
connections, it goes with the rest of that connection's tables. When it lives on a connection that is not reset, the
migrator drops just the history table there, and nothing else on that connection. Either way the history starts
empty, so every migration is recorded again in batch 1, and nothing that was dropped is still recorded as applied.

Applied migrations that are no longer in the configured directories disappear from the history with the rest.

## Failures

:::caution
A fresh run is not atomic, and cannot be across several databases.
:::

- When dropping the tables of a connection fails, the fresh run throws a
  `Dirthara\Migration\Exception\MigrationFreshException` with the `connection` in its context and the schema failure
  as its previous exception. Connections reset before it stay empty, the ones after it are untouched, and no
  migration runs.
- When dropping the history table fails, the run throws a `MigrationRepositoryException`.
- When a migration fails while the migrations run again, the failure behaves as it does in
  [`migrate()`](running-migrations.md#running-a-migration): the database is partly rebuilt, and the migrations that
  ran before the failure stay applied in batch 1.

Running `fresh()` again once the cause is fixed starts over from empty schemas.

## Locking

One [migration lock](locking.md) covers the whole fresh run, from loading the migrations to recording the last one. It
is not released between dropping the tables and running the migrations, so no other runner can migrate in between.
The lock is a named lock held by the database session, not a table, so dropping every table, the history table
included, does not release it.

A fresh run respects the same `locking` option as a run. On a SQLite history connection, which has no named locks,
turn locking off to run it, as described in [migration locking](locking.md#sqlite).
