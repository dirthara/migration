# Project instructions

## Ownership
Dirthara owns this package. Attribute copyright, licensing, and authorship to `Dirthara` rather than to an individual 
maintainer. The MIT `LICENSE` reads `Copyright (c) <year> Dirthara`, and new files or documents that name an owner
use the same name.

## Branching
Every supported version has its own branch; there is no `main`. Target a feature at the newest release branch and a fix 
at the earliest supported branch that has the bug, then forward-merge upward. Read [CONTRIBUTING.md](CONTRIBUTING.md) before 
branching, merging, or releasing.

## Committing
Never run `git commit`, `git push`, `git tag`, or anything else that writes to history or to the remote. Stage nothing 
and commit nothing: the maintainer commits and pushes every change themselves. Leave the work in the working tree
and say what is ready.

## Tests
Line coverage of `src` must stay at 100%; `composer coverage` fails below it and lists the uncovered lines. Add tests 
in `tests` with every implementation change.
Behaviour that needs a real database belongs in the shared conformance suite in `tests/Integration`, not in a copy per
driver.

## Development
Use the PHP container for Composer and PHP commands; see [README.md](README.md). Use the `Dirthara\Migration`
namespace for source and `Dirthara\Migration\Tests` for tests. Declare strict types in every PHP file.
`docker compose up -d php` also starts the PostgreSQL, MySQL, and SQL Server services the tests run against, and waits
until each is healthy.

## Language
Write everything in British English: names, messages, comments, documentation, and commit messages. Read and follow 
https://github.com/dirthara/coding-standards/blob/main/docs/coding-standards/cs-2-naming-conventions.md#3-language for 
names fixed by PHP, dependencies, or tools.

## Exceptions
Read and follow https://github.com/dirthara/coding-standards/blob/main/docs/coding-standards/cs-7-exceptions-error-handling.md when creating or modifying exceptions.
Every exception implements `Dirthara\Migration\Exception\MigrationException` and uses the 
`HasExceptionContext` trait for its context.

## Documentation
Read and follow https://github.com/dirthara/coding-standards/blob/main/docs/coding-standards/cs-6-documentation.md when writing the README or anything in `docs`.

## Packaging
Read and follow https://github.com/dirthara/coding-standards/blob/main/docs/coding-standards/cs-8-packaging.md when 
changing what a release contains, the actions the CI workflow uses, or the dependency update configuration.

## Coding Standards
Read and follow all coding standards in https://github.com/dirthara/coding-standards (https://github.com/dirthara/coding-standards/tree/main/docs/coding-standards).
