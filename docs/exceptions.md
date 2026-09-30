---
id: exceptions
title: Exceptions
sidebar_position: 11
description: Every exception Dirthara Migration throws, and when.
---

Every exception the package throws implements `Dirthara\Migration\Exception\MigrationException`, so one `catch`
handles all of them. Each carries a `context` array of non-sensitive details, such as the migration name, path,
connection, or table, and wraps the failure of a dependency as its previous exception. Exceptions thrown by migration
code itself, in `up()`, `down()`, or a hook, reach the caller unchanged.

All of them live in the `Dirthara\Migration\Exception` namespace.

| Exception                        | Base                       | Thrown when                                                                                          |
|----------------------------------|----------------------------|------------------------------------------------------------------------------------------------------|
| `InvalidMigrationFileException`  | `RuntimeException`         | A migration file or directory cannot be read, the file does not compile or throws while loading, or it returns no migration. |
| `MigrationSourceLoaderException` | `RuntimeException`         | The `dirthara-migration` stream wrapper scheme is taken by another component, or cannot be registered. |
| `MigrationPlanException`         | `RuntimeException`         | The loaded migrations break a rule, the history conflicts with them, or a connection cannot be resolved. |
| `MigrationRepositoryException`   | `RuntimeException`         | The migration history cannot be created, read, or written, or holds an invalid record.               |
| `MigrationLockException`         | `RuntimeException`         | The migration lock cannot be taken or released, or the history connection has no named locks.        |
| `MigrationRollbackException`     | `RuntimeException`         | A migration selected for rollback has no source in the configured directories.                       |
| `InvalidRollbackStepsException`  | `InvalidArgumentException` | `rollback()` receives fewer than one step.                                                           |
| `MigrationCreatorException`      | `RuntimeException`         | A new migration has an invalid name, its template cannot be read, its file exists, or cannot be written. |

## Planning failures

`MigrationPlanException` covers everything a run or rollback checks before it changes anything:

| Failure                   | Context                                                  |
|---------------------------|----------------------------------------------------------|
| Invalid name              | `migration`, `path`                                      |
| Empty index               | `migration`, `path`                                      |
| Empty explicit connection | `migration`, `path`                                      |
| Duplicate name            | `migration`, `names`, `paths`                            |
| Conflicting history names | `migration`, `conflictingMigration`                      |
| Changed index             | `migration`, `path`, `index`, `appliedIndex`             |
| Changed connection        | `migration`, `path`, `connection`, `appliedConnection`   |
| Unresolvable connection   | `migration`, `path`, `connection`                        |
