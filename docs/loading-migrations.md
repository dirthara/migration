---
id: loading-migrations
title: Loading migrations
sidebar_position: 11
description: How migration files are found in a directory and loaded from any Flysystem storage.
---

Migrations are read through [Flysystem](https://flysystem.thephpleague.com/), so they can live on the local disk or
in any storage Flysystem supports, such as S3.

## Finding migrations

`Dirthara\Migration\MigrationLoader::loadDirectory()` loads the files of one directory whose name ends in `.php`.
It does not look into subdirectories. A directory that does not exist loads nothing, the way Flysystem lists it.
A failure to list a directory is an `InvalidMigrationFileException` with the directory in its context.

The migrator decides the order migrations run in; see [running migrations](running-migrations.md#connections-and-order).

## Loading a file

`Dirthara\Migration\MigrationSourceLoader::loadFile()` reads a file through Flysystem and requires it through a
stream wrapper, so nothing is copied to a temporary file. The file must return an object implementing
`Dirthara\Migration\Contract\Migration`; every load returns a new object.

A file that does not exist, cannot be read, does not compile, throws while loading, or returns something other than a
migration fails with a `Dirthara\Migration\Exception\InvalidMigrationFileException` naming the file.

### Paths inside a migration

Inside a migration, `__FILE__` and `__DIR__` name the file by its stream-wrapper path, such as
`dirthara-migration://src/Users/Infrastructure/Migrations/2026_09_30_101500_create_users_table.php`, and so do error
messages and stack traces. A migration cannot reach files next to it through local paths.

While a migration loads, `is_file()`, `file_exists()`, and `filesize()` on its path report a regular, read-only file
of its size. Once it has loaded, and for any other path on the scheme, they report no file, without a warning, so
error handlers that inspect stack traces can check those paths safely.

### The stream wrapper scheme

The loader registers the `dirthara-migration` stream wrapper scheme the first time it loads a file. When another
component has already registered that scheme, loading fails with a
`Dirthara\Migration\Exception\MigrationSourceLoaderException` instead of loading migrations through a wrapper the
package does not own. The other component's wrapper is left as it is.
