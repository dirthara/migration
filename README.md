<p align="center">
  <img src="logo-no-bg.png" alt="Dirthara" width="480">
</p>

# Dirthara Migration

Migrations for the Dirthara framework: creating, running, and rolling back migrations across connections. No release is available yet. Usage 
documentation lives in [`docs`](docs/intro.md) and is published on the Dirthara documentation site at 
<https://dirthara.github.io/docs/>, which documents every package in the framework.

## Installation

Requires PHP `^8.5` (PHP 8.5 or a later PHP 8 release), the `pdo` extension, and 
[`dirthara/database`](https://github.com/dirthara/database) `^0.2` and [`dirthara/schema`](https://github.com/dirthara/schema) 
`^0.3`, which Composer installs for you. Those packages own the connections and the schema definitions; this one decides 
which schema changes run, in what order, and records what has already been applied. Each database also needs its own PDO 
extension: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, or `pdo_sqlsrv`. Install with:

```sh
composer require dirthara/migration
```

## Docker development environment

Requires Docker with Docker Compose. The development image provides PHP 8.5 CLI, Composer 2.10.3, Mago 1.47.3, Xdebug, 
and a PDO driver for every database the package supports: `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, and `pdo_sqlsrv`.

```sh
git clone git@github.com:dirthara/migration.git
cd migration
LOCAL_UID=$(id -u) LOCAL_GID=$(id -g) docker compose up -d --build php
docker compose exec php composer install
```

The container runs as the non-root `developer` user. The build arguments `LOCAL_UID` and `LOCAL_GID` default to 1000; 
the command above uses your host IDs so generated files remain editable. Set `PHP_VERSION` to override the default
8.5 image. Rebuild when the Dockerfile or build arguments change.

`docker compose up -d php` also starts PostgreSQL, MySQL, and SQL Server and waits until each reports healthy, because 
the tests run against every driver the package supports. The first start pulls roughly a gigabyte of images, and SQL
Server takes around thirty seconds to accept connections. The SQL Server image is published for amd64 only, so its tests 
skip on an arm64 host.

Open a shell or stop the environment with:

```sh
docker compose exec php bash
docker compose down
```

## Tests

```sh
docker compose exec php composer test
```

Tests belong in `tests`, under `Dirthara\Migration\Tests`. Source belongs in `src`, under `Dirthara\Migration`.

Behaviour that needs a real database belongs in `tests/Integration`, where one conformance suite runs against every 
driver. SQLite runs in memory and always runs; the PostgreSQL, MySQL, and SQL Server suites skip when their PDO driver 
is missing, and read their connection from `DIRTHARA_POSTGRES_*`, `DIRTHARA_MYSQL_*`, and `DIRTHARA_SQLSRV_*` 
(`_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD`), defaulting to the services in `compose.yaml`.

The package starts with its exception interface, `Dirthara\Migration\Exception\MigrationException`, and the 
`HasExceptionContext` trait every exception uses to carry its context, both covered by tests.

## Code quality

Run the same checks as CI:

```sh
docker compose exec php composer ci
```

Run individual checks:

```sh
docker compose exec php composer fmt-check
docker compose exec php composer lint
docker compose exec php composer analyze
docker compose exec php composer guard
```

`composer mago` runs the formatting, import-order, lint, analysis, and configured architecture checks. `composer ci` 
also runs tooling tests, unit tests, and the coverage gate.

Apply formatting and import sorting with `composer fmt`, or include automatic lint fixes with `composer cs`:

```sh
docker compose exec php composer fmt
docker compose exec php composer cs
```

`composer cs` includes potentially unsafe lint fixes; review its changes.

Run coverage separately with:

```sh
docker compose exec php composer test-coverage
docker compose exec php composer coverage
```

Xdebug is inactive by default and enabled for the coverage run. The report is written to `build/coverage/clover.xml`. 
The gate requires 100% line coverage of `src` and lists uncovered lines.

## Contributing

Each supported version has its own branch, beginning with `0.1`; there is no `main`. See [CONTRIBUTING.md](CONTRIBUTING.md) for 
branching, release, and pull request requirements, and [AGENTS.md](AGENTS.md) for agent instructions.

## Security

Report vulnerabilities through GitHub's private advisory form. See [SECURITY.md](SECURITY.md) for the reporting process and 
scope.

## License

Copyright (c) 2026 Dirthara. Released under the [MIT License](LICENSE).
