---
id: writing-migrations
title: Writing migrations
sidebar_position: 4
description: The migration contract, what a valid name, index, and connection are, and the optional hooks.
---

A migration is a PHP file that returns an object implementing `Dirthara\Migration\Contract\Migration`, usually an
anonymous class:

```php
<?php

declare(strict_types=1);

use Dirthara\Schema\Table;
use Dirthara\Migration\Contract\Migration;
use Dirthara\Migration\ValueObject\MigrationContext;

return new class implements Migration {
    public string $name {
        get => 'CreateUsersTable';
    }

    public ?string $description {
        get => 'Creates the users table';
    }

    public string $index {
        get => '2026_09_30_101500';
    }

    public ?string $connection {
        get => null;
    }

    public function up(MigrationContext $context): void
    {
        $context->schema->createIfNotExists('users', static function (Table $table): void {
            $table->id();

            $table->timestamps();
        });
    }

    public function down(MigrationContext $context): void
    {
        $context->schema->dropIfExists('users');
    }
};
```

[Creating migrations](creating-migrations.md) generates files like this one.

## Properties

| Property      | Type             | Meaning                                                                             |
|---------------|------------------|-------------------------------------------------------------------------------------|
| `name`        | `string`         | The migration's identity. Must be a valid migration name, see below.                |
| `description` | `string` or `null` | Documentation for people. It may change after the migration ran.                 |
| `index`       | `string`         | The ordering value. Must not be empty or only whitespace.                           |
| `connection`  | `string` or `null` | The connection to run on. `null` means the default connection.                   |

### Names

A migration name is an ASCII identifier: a letter, followed by letters, digits, and underscores. `CreateUsers`,
`create_users`, and `Region2Migration` are valid; `create users`, `create-users`, `_create_users`, `123_create_users`,
and `résumé` are not. Names are never corrected: an invalid name fails.

Names are compared case-insensitively, so `CreateUsers` and `createusers` are the same migration, on every database
and whatever its collation. The history keeps a name as it was written. The ASCII restriction exists so that no
database's Unicode or accent rules can change which names are equal.

### Index

The index orders migrations that run on the same connection: migrations run by index, and by file path when two share
an index. Duplicate indexes are allowed. Once a migration has run, its index must not change: a run or rollback that
finds a different index for an applied migration fails.

### Connection

`null` runs the migration on the application's default connection. A name runs it on that connection. An explicit
connection must not be empty or only whitespace; use `null` for the default.

The history records the connection a migration actually ran on. That recorded connection is authoritative afterwards:
a rollback runs `down()` there, and a migration declared with `null` is not considered changed when the default
connection later changes. A migration that declares a connection by name must keep declaring the one the history
recorded.

## The context

`up()` and `down()` receive a `Dirthara\Migration\ValueObject\MigrationContext`, scoped to the migration's connection:

| Property   | Type                                | Use                                  |
|------------|-------------------------------------|--------------------------------------|
| `database` | `Dirthara\Database\ConnectedDatabase` | Queries, such as moving data.       |
| `schema`   | `Dirthara\Schema\ConnectedSchema`     | Schema changes.                     |

Both point at the same connection.

## Hooks

A migration can also implement `Dirthara\Migration\Contract\MigrationHooks`, which adds `beforeUp()`, `afterUp()`,
`beforeDown()`, and `afterDown()`. The `before` hooks receive a `Dirthara\Migration\ValueObject\MigrationDecision`
by reference, set to continue, and can replace it:

| Decision                            | Effect                                                                      |
|-------------------------------------|-----------------------------------------------------------------------------|
| `MigrationDecision::continue()`     | Run the migration, then the `after` hook.                                   |
| `MigrationDecision::skip($reason)`  | Leave this migration for a later run and carry on with the next one.       |
| `MigrationDecision::stop($reason)`  | Leave this migration and end the whole run.                                 |

```php
public function beforeUp(MigrationContext $context, MigrationDecision &$decision): void
{
    if (!$context->schema->hasTable('users')) {
        $decision = MigrationDecision::skip('The users table does not exist yet.');
    }
}
```

A skipped migration stays pending, so the next run asks it again. A stop is not an error: the run ends normally, and
what ran before it stays applied. The reason is kept on the decision for tooling; the package does not report it yet.

[Running migrations](running-migrations.md) and [rolling back migrations](rollback.md) describe exactly when each hook
runs.
