---
id: installation
title: Installation
sidebar_position: 2
description: Requirements and installation status for Dirthara Migration.
---

## Requirements

PHP 8.5 or later within the PHP 8 series is required, together with the `pdo` extension and a PDO driver for the
database you migrate: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, or `pdo_sqlsrv`.

Composer installs these alongside the package:

| Package              | Version | Used for                                                              |
|----------------------|---------|-----------------------------------------------------------------------|
| `dirthara/database`  | `^0.2`  | Connections, queries on the migration history, and the migration lock. |
| `dirthara/schema`    | `^0.3`  | The schema changes migrations make and the migration history table.   |
| `league/flysystem`   | `^3.0`  | Reading migration files and writing new ones, from any storage.       |
| `psr/clock`          | `^1.0`  | The time that indexes new migrations and stamps applied ones.         |

## Package installation

Once published, install the package using Composer:

```sh
composer require dirthara/migration
```

:::caution
There is no published release yet. The command above describes the intended installation after publication.
:::

Continue with [setting up](setup.md) the migrator.

For development, follow the Docker and Composer setup in the repository's
[README](https://github.com/dirthara/migration#readme). Development tooling includes PHPUnit, Mago, and Xdebug.
