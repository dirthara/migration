---
id: creating-migrations
title: Creating migrations
sidebar_position: 5
description: Generating migration files with MigrationCreator, its templates, and file naming.
---

`Dirthara\Migration\MigrationCreator` writes a new migration file from a template.

```php
use Dirthara\Migration\MigrationTemplate;

$created = $creator->create(
    directory: 'src/Users/Infrastructure/Migrations',
    name: 'CreateUsersTable',
    table: 'users',
    description: 'Creates the users table',
    template: MigrationTemplate::Create,
);

$created->path; // src/Users/Infrastructure/Migrations/2026_09_30_101500_create_users_table.php
```

| Argument      | Type                | Default  | Meaning                                                                    |
|---------------|---------------------|----------|----------------------------------------------------------------------------|
| `directory`   | `string`            | none     | The directory within the filesystem to write to.                           |
| `name`        | `string`            | none     | The migration name. Must be a [valid name](writing-migrations.md#names).    |
| `table`       | `string` or `null`  | `null`   | The table a create or alter migration works on.                            |
| `description` | `string` or `null`  | `null`   | The migration's description.                                               |
| `connection`  | `string` or `null`  | `null`   | The connection the migration declares. `null` keeps the file portable.     |
| `template`    | `MigrationTemplate` | `Basic`  | The shape of the migration.                                                |

`create()` returns a `Dirthara\Migration\ValueObject\CreatedMigration` with the `name`, `index`, `description`,
`connection`, and `path` it wrote.

## Templates

| Template          | Generates                                                                            |
|-------------------|--------------------------------------------------------------------------------------|
| `Basic`           | Empty `up()` and `down()` methods, not tied to a table.                              |
| `BasicWithHooks`  | `Basic`, with every hook.                                                            |
| `Create`          | `createIfNotExists()` with an id and timestamps in `up()`, `dropIfExists()` in `down()`. |
| `CreateWithHooks` | `Create`, with every hook.                                                           |
| `Alter`           | A `table()` call to fill in, in both `up()` and `down()`.                            |
| `AlterWithHooks`  | `Alter`, with every hook.                                                            |

The hooks the templates generate continue by default.

Choosing a template and a table from a migration name, such as `CreateUsersTable`, is left to the framework's command
layer; the creator only receives them.

### Without a table

A create or alter migration created without a table uses the placeholder table `table_name`. Replace it before
running the migration.

:::caution
`table_name` is a valid table name. A create migration run without replacing it creates a table called
`table_name`, and an alter migration fails because no such table exists.
:::

## The index and the file name

The index is the current time of the clock in UTC, formatted as `Y_m_d_His`, such as `2026_09_30_101500`. The clock is
read once, and the same index goes into the file and into its name, so migrations created by developers in different
time zones still sort correctly.

The file name comes from the `Dirthara\Migration\Naming\NamingStrategy` given to the creator. The
`DefaultNamingStrategy` writes the index followed by the name in snake case: `CreateUsersTable` becomes
`2026_09_30_101500_create_users_table.php`. Implement `NamingStrategy` to name files differently; it receives the
name and the index and returns the file name.

## Safety

- An invalid name fails with a `MigrationCreatorException` before anything is written or named.
- An existing file is never overwritten: creating a second migration with the same file name fails.
- Names, descriptions, connections, and tables are written as PHP string literals, so quotes, placeholders, and code
  in them stay text.

## Custom templates

The creator's last constructor argument is the template directory, which defaults to the package's own templates.
A custom directory must hold a file for every template: `migration.php.stub`, `migration-with-hooks.php.stub`,
`create-migration.php.stub`, `create-migration-with-hooks.php.stub`, `alter-migration.php.stub`, and
`alter-migration-with-hooks.php.stub`. The creator replaces `{{ name }}`, `{{ description }}`, `{{ index }}`,
`{{ connection }}`, and `{{ table }}` in them with PHP literals. A template that cannot be read fails with a
`MigrationCreatorException`.
