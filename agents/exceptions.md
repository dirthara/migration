# Exceptions

Exceptions in Dirthara follow the same convention as PHP's built-in exceptions. Each package has a base exception that 
extends from `Exception`. All exceptions thrown by the Dirthara package extend from this base exception.

The package base exception accepts an optional `array<string, mixed> $context` as
the fourth constructor argument, after message, code, and previous. It exposes
this data through `getContext(): array` and stores it in a protected property.
Specialized exceptions inherit this behaviour.

Exceptions carry data without logging themselves or depending on a logger package.
The application exception handler can pass `getContext()` to a PSR-3 logger,
adding the caught exception under the `exception` key. That key must contain the
caught exception even if context already contains an `exception` entry.

Include useful diagnostic metadata, such as the migration name, the direction
it ran in, and the connection. Do not include credentials, credential-bearing
DSNs, or raw query parameter values in context.

When a dependency can throw, catch the exception types it documents and rethrow
them as a specialized exception from this package. Pass the original as
`previous` and attach the operation's context. A caller should never need the
dependency in a `catch` block to handle a failure this package caused.

Catch the specific types, not `Throwable`. A `TypeError` or a `LogicException`
is a bug in this package rather than a failure of the operation, and turning one
into a domain exception hides it.

An exception that is already a Dirthara exception from this same package is not
rewrapped. Add what you know with `addContext()` and rethrow it, so its specific
type survives for the caller. An exception from another Dirthara package is
wrapped like any other dependency's: which package this one is built on is not
something its callers should have to catch.

A dependency's message can carry sensitive values or credentials. The prohibition on
credentials applies to the message as much as to the context, so do not copy one
verbatim without knowing what it can contain.
