---
id: installation
title: Installation
sidebar_position: 2
description: Requirements and installation status for Dirthara Migration.
---

## Requirements

PHP 8.5 or later within the PHP 8 series is required, together with the `pdo`
extension and a PDO driver for the database you migrate: `pdo_mysql`,
`pdo_pgsql`, `pdo_sqlite`, or `pdo_sqlsrv`.

Composer installs `dirthara/database` `^0.2` and `dirthara/schema` `^0.2`
alongside the package.

## Package installation

Once published, install the package using Composer:

```sh
composer require dirthara/migration
```

:::caution
There is no published release yet. The command above describes the intended
installation after publication.
:::

For development, follow the Docker and Composer setup in the repository's
[README](https://github.com/dirthara/migration#readme). Development tooling
includes PHPUnit, Mago, and Xdebug.
