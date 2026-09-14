# Documentation

Documentation lives in two places with no overlap between them.

## README.md

The root README covers the repository, not the library. It contains, in order:

1. The logo, centred above the title, linking to `logo-no-bg.png` by relative
   path. A relative path survives the branch-per-version strategy; an absolute
   `raw.githubusercontent.com` URL would name one branch and be wrong on every
   other.
2. A short description of the package.
3. How to install the package with Composer, and its requirements.
4. How to set up the local development environment.
5. How to run the tests.
6. How to run the linters, formatter, and static analyzer, and the coverage gate.
7. Where the contributing and branching rules live, linking to `CONTRIBUTING.md`.
8. Where to report a vulnerability, linking to `SECURITY.md`.
9. The license, linking to `LICENSE`.

Usage, options, and API documentation do not belong in the README. It links to
`docs` instead.

## CONTRIBUTING.md

The root `CONTRIBUTING.md` owns the branching and release strategy, what a pull
request has to satisfy, and the maintainer steps for a new release branch. The
supported versions table there and the one in `SECURITY.md` list the same
branches; update both together.

## SECURITY.md

The root `SECURITY.md` states which versions are supported, how to report a
vulnerability privately, and what is in and out of scope. Reports go through
GitHub's private advisory form; do not publish an email address as the reporting
channel. Keep the scope section grounded in what the package actually defends
against, and update it when those defences change.

## docs/

The `docs` directory holds the usage documentation as markdown files. Another
package reads these files and builds a documentation website with Docusaurus, so
write them as if they are already part of a Docusaurus site.

That means:

- Every file starts with YAML front matter containing `id`, `title`,
  `sidebar_position`, and `description`. Add `sidebar_label` when the sidebar
  needs a shorter title than the page.
- Group related pages in a subdirectory with a `_category_.json` that sets
  `label`, `position`, and a `generated-index` link.
- Link between pages with relative paths that include the `.md` extension, so
  Docusaurus can resolve and validate them.
- Use admonitions (`:::note`, `:::tip`, `:::caution`, `:::danger`) for caveats
  instead of bolded prose.
- Fence every code block with its language.
- Keep the content MDX-safe: wrap generics, array shapes, and anything
  containing `<` or `{` in backticks, or MDX parses it as JSX.

Document how the package is used, which options exist, and what each option
means. Options belong in a table with their type, default, and meaning. Say why
a default is what it is when the reason is not obvious, and document the
behaviour that will surprise someone before they hit it.

Keep the documentation truthful against the source. When behaviour changes,
update the page that describes it in the same commit.
