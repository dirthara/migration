# Packaging

What a release contains, and how the inputs that build it are pinned. This is
separate from [CONTRIBUTING.md](../CONTRIBUTING.md), which owns branching and
when a tag is cut.

## A release contains only what an install loads

`.gitattributes` marks every development path `export-ignore`, so the archive
Composer downloads is `src`, `composer.json`, `LICENSE`, `README.md`,
`SECURITY.md`, and `docs`. Tests, CI configuration, the Docker environment,
Mago and PHPUnit configuration, the agent instructions, the branding images, and
`composer.lock` all stay in the repository and out of the archive.

Weight is the smaller reason. The larger one is that a consumer's tooling reads
what ships: a security scanner walking `vendor/` finds any lockfile there and
reports the versions pinned inside it, which are not the versions the consuming
project resolved, so every downstream user gets false alarms. The lockfile
therefore stays committed — CI installs from it and `composer validate --strict`
keeps it honest — and is excluded from the archive rather than deleted.

Two things to remember when changing this:

- **Add the path when you add the file.** A new top-level development file that
  is not listed in `.gitattributes` ships to every consumer. The list is
  explicit rather than pattern-based so that adding something is a deliberate
  choice.
- **It only takes effect in the next tag.** A host builds the archive from the
  tagged tree, and applies `export-ignore` as that tree declares it, so a
  change to `.gitattributes` reaches consumers when the next version is tagged
  and not before.

Check what would ship rather than trusting a scanner to tell you:

```sh
git archive HEAD | tar -t
```

## Third-party GitHub Actions are pinned to a commit SHA

Every `uses:` reference to a repository other than this one names a full
40-character commit SHA, with the version as a trailing comment:

```yaml
uses: actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09 # v5
```

A tag or a branch is a moving target. Whoever controls that repository can
point it at different code at any time, and the next workflow run executes the
new code with access to this repository's secrets. A commit SHA cannot be moved.
The trailing comment is not decoration: it is what Dependabot reads to know
which version the pin represents and to move the pin when a new one appears.

Resolve a reference before pinning it, because two things are easy to get wrong:

```sh
git ls-remote https://github.com/<owner>/<repo> 'refs/tags/<tag>' 'refs/tags/<tag>^{}'
```

- An **annotated** tag resolves to a tag object, not a commit. Use the
  `refs/tags/<tag>^{}` line when there is one.
- A major version such as `v3` is sometimes a **branch** rather than a tag, in
  which case nothing is returned and `git ls-remote --tags` shows what the real
  releases are. Pin the commit the branch currently points at and comment it
  with the release it matches.

## Dependency updates are automated with a cooldown

`.github/dependabot.yml` carries one entry per ecosystem in use: `composer`
while a lockfile is committed, and `github-actions` while there are workflows.
An updater that skips an ecosystem leaves that part of the repository unpatched.

Each entry sets a cooldown of at least three days, and groups its ecosystem into
a single pull request:

```yaml
cooldown:
  default-days: 7
groups:
  composer:
    patterns:
      - '*'
```

A malicious or broken release is usually discovered and withdrawn within days,
so waiting costs nothing and avoids the early-adopter window the 2024 xz-utils
backdoor targeted. Grouping matters because what gets judged, by a reviewer and
by a scorer, is how long the *oldest* open update has been waiting; one pull
request a week per ecosystem stays current in a way that one per dependency does
not.

## Verifying it

Every package scores 100 before it is released, which is what these rules exist
to satisfy; [CONTRIBUTING.md](../CONTRIBUTING.md) states the gate.
[Plumb](https://plumbphp.dev) scores it mechanically, and its free API needs no
key:

```sh
curl https://plumbphp.dev/api/v1/packages/dirthara/<package>
curl -X POST https://plumbphp.dev/api/v1/packages/dirthara/<package>
```

A category score is the passed weight over the applicable weight, and the
composite is security 55%, maintenance 30%, ecosystem 15%. A check that does not
apply leaves the denominator rather than counting against the package.

The distinction that matters when reading a result: the checks for the workflow
pins, the updater configuration, and the security policy read the **repository**,
so they change as soon as a commit is pushed. The checks for the lockfile and the
lean archive read the **released archive**, so they cannot change until a version
is tagged. A package sitting below 100 immediately after a packaging change is
usually waiting for a tag rather than misconfigured.
