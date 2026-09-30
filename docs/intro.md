---
id: intro
title: Dirthara Migration
sidebar_position: 1
description: What Dirthara Migration does, the concepts it works with, and where each part is documented.
---

Dirthara Migration creates, runs, previews, rolls back, and refreshes database migrations for the Dirthara framework. It builds on
[`dirthara/database`](https://github.com/dirthara/database) for connections and locks and on
[`dirthara/schema`](https://github.com/dirthara/schema) for schema changes, and decides which migrations run, in what
order, on which connection, and records what has been applied.

:::note
There is no published release yet. The API described here is what the `0.1` branch provides today.
:::

## Concepts

| Concept    | Meaning                                                                                                    |
|------------|------------------------------------------------------------------------------------------------------------|
| Migration  | A PHP file that returns an object implementing `Dirthara\Migration\Contract\Migration`, with `up()` and `down()`. |
| Name       | The migration's identity: an ASCII identifier, compared case-insensitively.                                 |
| Index      | The migration's ordering value, usually a UTC timestamp such as `2026_09_30_101500`.                        |
| Connection | The database connection a migration runs on. `null` means the default connection.                           |
| History    | The table that records every applied migration, where it ran, and in which batch.                          |
| Batch      | The migrations one `migrate()` call applied. A batch rollback reverses one batch.                           |
| Lock       | A named database lock that keeps two runs, rollbacks, or refreshes from working at the same time.           |

Migrations can live in any number of directories, for example one per domain or module. A run loads all of them,
checks them as one set, and orders them per connection.

## Pages

- [Installation](installation.md): requirements and installing the package.
- [Setting up](setup.md): building the services the migrator needs.
- [Writing migrations](writing-migrations.md): the migration contract, names, indexes, connections, and hooks.
- [Creating migrations](creating-migrations.md): generating migration files from templates.
- [Running migrations](running-migrations.md): what `migrate()` checks, in which order it runs, and how it fails.
- [Rolling back migrations](rollback.md): batch and step rollback.
- [Previewing migrations](previewing-migrations.md): the plan of a run or rollback, without running anything.
- [Refreshing migrations](refreshing-migrations.md): rolling back every migration and running them all again.
- [Migration locking](locking.md): how runs are protected against each other.
- [Loading migrations](loading-migrations.md): how migration files are found and loaded from any filesystem.
- [Migration history](migration-history.md): the history table and the repository around it.
- [Exceptions](exceptions.md): every exception the package throws and when.
