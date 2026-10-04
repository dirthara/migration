# Security Policy

## Supported versions

| Version | Status |
| --- | --- |
| 0.1.x | Active |
| Older | Unsupported |

While the package is pre-1.0, only the latest release line receives fixes.

## Reporting a vulnerability

Report vulnerabilities privately using GitHub's
[Report a vulnerability](https://github.com/dirthara/migration/security/advisories/new)
form. Do not disclose vulnerabilities in public issues or pull requests.

Include the affected version or commit, PHP version, a minimal reproduction,
and the impact and conditions needed to trigger the issue. Maintainers will
acknowledge and assess the report. Confirmed fixes are published with an
advisory crediting the reporter unless they prefer otherwise.

## Scope

Report security issues in this package's code or development configuration, such as:

- a migration running, or a table being dropped, on a connection other than the one the documentation says: the
  connection a migration declares or resolves to for a run, the connection the history recorded for a rollback, and
  only the migration connections for a fresh run;
- a preview writing to the database, creating the history table, or running any migration code;
- two runners that both hold the migration lock applying, reversing, or dropping at the same time;
- the migration creator writing a name, description, connection, or table into a new file as anything other than a
  PHP string literal, so that it becomes code;
- the `dirthara-migration` stream wrapper serving a file other than the migration being loaded, or migrations being
  loaded through a wrapper another component registered;
- an exception message or context that exposes a credential, a connection string, or a value read from the
  database. Messages and context are meant to hold only names, paths, connections, tables, and numbers, and never
  repeat the message of an exception thrown by a dependency or by migration code;
- a control character in a name, path, connection, or table that is not escaped in an exception message, and so can
  forge a line in a log.

### Migrations and configuration are trusted input

A migration file is PHP that the loader executes with the full privileges of the application, and `up()`, `down()`,
and the hooks can do anything the application can. The filesystem and directories migrations are loaded from, the
template directory given to the creator, the migration configuration, the connection configuration, and the contents
of the migration history table are trusted application input. Loading migrations from storage that someone else can
write to lets that person run code in the application. Keep migration storage and the history table writable only by
the people and processes that deploy the application.

`fresh()` drops every user table on the migration connections by design, and `refresh()` runs every `down()`. Calling
them against a database that must keep its data is a misuse, not a vulnerability.

A report that depends on loading untrusted migration files, passing untrusted input as a directory, name, or
template, or editing the history table describes that documented contract rather than a vulnerability, and is out of
scope. So is anything that depends on changing the application's own code or configuration.

### Out of scope

Bugs in PHP or third-party dependencies should be reported upstream. That includes
[`dirthara/database`](https://github.com/dirthara/database), which owns connections and named locks, and
[`dirthara/schema`](https://github.com/dirthara/schema), which decides what `dropAll()` removes. Application code and
the sensitivity of data an application chooses to store are the application's responsibility.
