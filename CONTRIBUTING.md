# Contributing

## Branching

There is no `main`. Every supported version has its own long-lived branch, named
for its major and minor version:

```text
0.1   ← the first release line
0.2   ← the next minor, branched from 0.1
1.0   ← the next major, branched from 0.2
```

The newest release branch is the repository's default branch. A release branch
lives as long as the version it carries is supported, and is deleted only when
that version reaches end of life.

Patch versions do not get a branch. They are tags on the release branch they
belong to, so `0.1.3` is a tag on `0.1`.

### Where work goes

Work happens on a short-lived branch and arrives through a pull request. Name it
after what it does:

| Branch | For |
| --- | --- |
| `feature/<issue>-short-slug` | A new feature. |
| `bugfix/<issue>-short-slug` | A fix for a reported bug. |
| `hotfix/<short-slug>` | An urgent fix that ships as a patch release immediately. |

What you target depends on what you are doing:

- **A feature** targets the newest release branch. Features never go into an
  older line, because that line is already released.
- **A fix** targets the *earliest supported branch that has the bug*, not the
  newest. A bug present since `0.1` is fixed on `0.1`.

Branch off the branch you intend to target, so the pull request contains your
commits and nothing else.

### Forward merging

A fix that lands on an older branch has to reach every newer one. After the pull
request merges, merge the release branches upward in ascending order:

```sh
git switch 0.1
git pull

git switch 0.2
git merge 0.1
git push

git switch 1.0
git merge 0.2
git push
```

Merge, rather than cherry-pick. A merge records that the fix reached each
branch, so the next forward merge does not offer it again and the same conflict
is never resolved twice.

:warning: Never merge a newer branch into an older one. That drags unreleased
work into a released line, which is how a patch release ends up containing a
feature.

Expect conflicts in `composer.json` and `.github/workflows/ci.yml` when the
branches support different PHP versions, or dependency constraints differ. Resolve them in favour of the branch you are merging
into — the newer branch keeps its own constraints and its own matrix.

### PHP versions are per branch

Each release branch declares the PHP versions it supports in two places, and
they have to agree:

- the `php` constraint in `composer.json`
- the `php` matrix in `.github/workflows/ci.yml`, which CI passes to the image
  as the `PHP_VERSION` build argument

Because the workflow lives on the branch, every branch tests exactly the
versions it claims to support. Dropping a PHP version in `1.0` does not change
what `0.1` tests.

## Releases

A release is a tag on a release branch:

```sh
git switch 0.1
git tag 0.1.3
git push origin 0.1.3
```

Opening a new minor or major means branching from the newest release branch,
pointing the repository's default branch at it, and applying branch protection
to it:

```sh
git switch -c 0.2 0.1
git push -u origin 0.2
```

Then update the supported versions table below and in
[SECURITY.md](SECURITY.md); both must list the same branches.

## Supported versions

| Branch | PHP | Status |
| --- | --- | --- |
| `0.1` | 8.5 | Active |

The initial `0.1` scaffold is unreleased.

## Before you open a pull request

Run everything CI runs:

```sh
docker compose exec php composer ci
```

That is Mago's formatter, linter, analyzer, and architecture rules, then the
test suite with coverage, then the coverage gate. Start the PHP container with `docker compose up -d php`. CI uses the same
image. The empty scaffold skips tests and coverage until PHP files exist in
`src` or `tests`. The individual commands are in [README.md](README.md).

Your pull request needs:

- **Every check green.** A single `CI` check reports the result of Mago and of
  the test suite on every supported PHP version.
- **Full coverage of `src`.** The gate fails the build below 100% line coverage
  and prints the uncovered lines. Cover new code with tests in the pull request that adds it.

- **Documentation that matches.** Behaviour that the [docs](docs) describe is
  updated in the same pull request. See the conventions in
  [agents/documentation.md](agents/documentation.md).

## Maintainers: protecting a release branch

Branch protection is per branch, so a new release branch starts unprotected.
Apply it as soon as the branch exists:

```sh
scripts/protect-branch.sh 0.2
```

The script requires the [`gh` CLI](https://cli.github.com/) with admin rights on
the repository. It requires the `CI` check to pass and be up to date with the
branch before a pull request can merge, and blocks force pushes and deletion.

`CI` is the only required check on purpose. It is a job that succeeds only when
Mago and every matrix entry succeeded, so adding a PHP version to the matrix
never means editing branch protection.
