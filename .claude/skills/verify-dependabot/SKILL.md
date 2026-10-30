---
name: verify-dependabot
description: Decide whether a Dependabot pull request can be merged. Finds where the bumped package touches our code, adds the smallest test that would catch a change in that integration (proved with red/green), pushes it to the Dependabot branch, and either hands the PR to a human or enables auto-merge, depending on whether the package has been verified before and how big the version jump is. Use when asked to check, verify, triage or merge Dependabot PRs, or with a PR number of a Dependabot PR.
---

# Verify a Dependabot PR

We don't have enough test coverage to trust a green CI run on a dependency
bump. This skill closes that gap one package at a time: the first time a
package is bumped, we write a test that pins down how *our code* uses it, and
a human reviews that test. From then on, minor and patch bumps of that package
can merge on their own as long as the test stays green.

We are not testing the library. It has its own tests. We are testing the seam
between the library and our code: the inputs we pass it and the outputs we
rely on.

Arguments: a PR number, or nothing to work through every open Dependabot PR
(oldest first).

## The gears

The ledger is `.github/dependency-coverage.json`. An entry for a package on
the default branch means a human has reviewed and merged the tests listed in
it. The ledger only reaches the default branch through a merged PR, so "the
entry exists on `master`" is the approval record. Read it from `origin/master`,
never from the PR branch.

| Situation | Gear | What happens |
|---|---|---|
| No ledger entry on `master` | 1 | Write tests, push them, comment, **wait for a human**. |
| Ledger entry, minor or patch bump | 2 | Re-check usage, wait for CI, **enable auto-merge**. |
| Major bump (any ledger state) | 3 | Do the gear 1 or 2 checks, read the changelog, **wait for a human**. |

Rules that decide the gear:

- `0.x` versions: treat a minor bump (`0.10` to `0.11`) as major, since semver
  allows breaking changes there.
- A PR that bumps several packages takes the highest gear of any of them.
- If anything in gear 2 needs a code change (a new test, a fix to our code),
  the PR drops to gear 1.
- Anything you are unsure about goes to a human. Auto-merge is the reward for
  certainty, not the default.

## Step 1: Read the PR

1. Get the PR (`mcp__github__pull_request_read`, `get` and `get_files`). Check
   the author is `dependabot[bot]`. If not, stop: this skill is only for
   Dependabot PRs.
2. From the title, body and lockfile diff, list every package that changes,
   with old and new versions, and the directory (`packages/join-flow`,
   `packages/join-e2e`, `packages/join-block`, or the root).
3. Work out the gear for each package from the table above.
4. Fetch the branch: `git fetch origin <head-ref>` and check it out.

## Step 2: Find where it touches our code

Most Dependabot PRs here bump **transitive** dependencies (security updates
for things like `fast-uri` or `follow-redirects`) that our code never imports.
So first find out how the package gets in:

- npm: `npm why <package>` in the package's directory.
- Composer: `composer why <package>` in `packages/join-block`.

Then put it in one of these classes. The class decides what "the smallest
test" is.

| Class | How to tell | What verifies it |
|---|---|---|
| `runtime-direct` | We import it in `src/` (JS) or `src/` (PHP) | A unit test of our code that calls it. |
| `runtime-transitive` | A direct dependency we import at runtime depends on it, and it ends up in the shipped bundle or plugin | A unit test of our code that calls the direct dependency, along a path that reaches this package. |
| `build` | Only webpack, Babel, loaders or Sass depend on it, and none of its code ends up in the bundle | `npm run build` succeeds, and the E2E suite (which runs on the built bundle) passes. |
| `dev-server` | Only `webpack-dev-server` depends on it | `scripts/dev-server-smoke.sh`, which CI also runs as the Dev Server workflow. Never ships to users, but designers, engineers and headless Claude Code sessions all depend on the dev server, so it must keep working. |
| `test-tooling` | Only Jest, Testing Library, PHPUnit, PHPStan, phpcs or Playwright depend on it | The suite that uses it runs and passes. |

For `runtime-direct` and `runtime-transitive`, list every call site:

```bash
# JS: imports and requires of the package (and its subpaths)
grep -rnE "from ['\"]<package>(/[^'\"]*)?['\"]|require\(['\"]<package>" packages/join-flow/src
# PHP: use statements and fully qualified names of its namespace
grep -rn "<Vendor\\\\Namespace>" packages/join-block/src packages/join-block/join.php
```

Group call sites by **usage pattern**: the functions we call and the shape of
what we pass. Five call sites that all call `format(date, "yyyy-MM-dd")` are one
pattern. A test per pattern is enough. A test per call site is too many.

For `build`, `dev-server` and `test-tooling`, skip to step 4. There is no seam in
our code to test, and CI or the smoke check already exercises the tool.

## Step 3: Write the smallest test (runtime classes only)

First check whether an existing test already covers each pattern: grep the
test directories for the function in our code that wraps the call. If a test
covers the pattern *with the real library* (not mocked), you can use it.
Record it in the ledger and still do the red/green proof below.

Otherwise write a new one. What makes a test small and useful here:

- **Test our function, not the library's.** Call the function in our code that
  uses the package, with the real package installed. Never mock the package
  you are verifying.
- **Assert on what we depend on.** If we use `parsePhoneNumber(x).format(E164)`
  to build a CRM payload, assert on the E.164 string we send, not on every
  property of the parsed object.
- **One `it`/test method per usage pattern**, with inputs that hit the
  behaviour we rely on, including the edge cases our code handles (empty
  values, the formats our members actually enter).
- **No snapshots of whole components** and no network calls.
- **Put it where it belongs**: next to the code, following the existing
  conventions. Jest tests are `packages/join-flow/src/**/*.test.ts(x)`. PHPUnit
  tests are `packages/join-block/tests/*Test.php`. Add to an existing test file
  for that module when there is one.
- **Make it findable**: name the `describe` block (Jest) or add a docblock line
  (PHPUnit) with `dependency: <package>`, so the next run can grep for it.
- Add a one-line comment saying which behaviour of the package the test
  depends on and why it matters to members or organisers.

### Red/green proof

A test that cannot fail proves nothing. Prove each new or reused test in this
order and keep the output of each run for the PR comment.

1. **Green on the old version.** Restore the base lockfile and install:
   ```bash
   git checkout origin/master -- <dir>/package.json <dir>/package-lock.json   # or composer.json/composer.lock
   npm --prefix <dir> ci                                                    # or composer install
   ```
   Run only the new test (`npx jest <file> -t "<name>"`, or
   `./vendor/bin/phpunit --filter <name>`). It must pass.
2. **Red when our integration breaks.** Make a temporary change in *our* code
   at the call site: pass a different argument, drop an option, or use the
   result differently. Run the test. It must fail, and it must fail on the
   assertion you wrote rather than on a crash in the test setup. Revert the change.
3. **Red when the library's behaviour changes.** Temporarily edit the
   package's code in `node_modules/<package>` or `vendor/<vendor>/<package>`
   so the function we rely on returns something slightly different (a changed
   format, an off-by-one, a missing field). Run the test. It must fail.
   Restore with `npm ci` or `composer install`.
   This is the step that shows the test would catch a bad upgrade. If you
   can't make the test fail this way, the test is checking our code only.
   Rewrite it.
4. **Green on the new version.** Restore the PR's lockfile and install:
   ```bash
   git checkout HEAD -- <dir>/package.json <dir>/package-lock.json
   npm --prefix <dir> ci
   ```
   Run the test again.
   - Passes: good.
   - Fails: this is what the skill exists to catch. Read the package's
     changelog for the change. If our code needs a small, clear fix, make
     it and note it for the human reviewer. Otherwise leave the code alone and
     report the failure on the PR. Either way the PR goes to a human.

After the proof, `git status` must show only the new or changed test files,
the ledger, and any fix to our code. No leftover mutations, and no changes to
`package.json` or the lockfiles beyond what Dependabot made.

Then run the full suite for the affected package on the new version (the
same commands CI runs: `npm test`, `npx prettier --check packages/join-flow`
and `scripts/dev-server-smoke.sh` for join-flow; `./vendor/bin/phpunit` and
`composer lint` for join-block).

## Step 4: Non-runtime classes

- `build`: first confirm none of the package's code ends up in the bundle.
  `scripts/bundled-packages.js <package>` builds the production bundle and
  prints how many of the package's modules are in it. If the count isn't 0,
  the package ships to users. It is `runtime-transitive`,
  so go back to step 3. Otherwise compare `npm run build` on the base and PR
  lockfiles and check for no new errors or warnings. CI's E2E job runs the
  test build and the suite against it.
- `dev-server`: run `scripts/dev-server-smoke.sh` on the PR's lockfile. It
  checks the page, the in-memory bundle (with hot reloading) and the stubbed
  `/join/v1/*` endpoints. CI runs the same script in the Dev Server workflow.
- `test-tooling`: the suite that uses it is the test. Run it.

These classes have nothing new to commit except the ledger entry.

## Step 5: Update the ledger

Add or update the package's entry in `.github/dependency-coverage.json`:

```json
{
  "packages": {
    "npm:packages/join-flow:google-libphonenumber": {
      "class": "runtime-direct",
      "via": [],
      "usage": [
        "packages/join-flow/src/schema.ts"
      ],
      "tests": [
        "packages/join-flow/src/schema.test.ts › dependency: google-libphonenumber"
      ],
      "reviewedVersion": "3.2.40",
      "reviewedIn": "#142"
    }
  }
}
```

- The key is `<ecosystem>:<directory>:<package>`. The same package in
  two directories gets two entries, because the usage is different.
- `via` is the dependency chain from `npm why`/`composer why`, top level first.
  It is empty for direct dependencies.
- `usage` lists the files of ours that use it (from step 2). It is empty for
  non-runtime classes.
- `tests` lists what verifies it. For non-runtime classes, name the CI
  job or the smoke check, for example `"ci: E2E Tests › Build join-flow bundle"`.
- `reviewedVersion` and `reviewedIn` are the new version and this PR. Only
  gear 1 and 3 PRs, which a human reviews, write them.

Keep keys sorted so diffs stay readable.

## Step 6: Push to the Dependabot branch

Commit the tests, any fix and the ledger in one commit on the PR's head
branch, with a message saying what the tests pin down. Then
`git push origin HEAD:<head-ref>`.

Things to know about pushing to a Dependabot branch:

- After anyone else commits, Dependabot stops rebasing the PR. If it later
  needs a rebase, rebase it yourself (`git rebase origin/master`, then
  `git push --force-with-lease`). Do not comment `@dependabot recreate`,
  because that throws away our commit.
- Don't touch `package.json` or the lockfiles. Dependabot owns those.

## Step 7: Hand off or merge

**Gear 1 and 3: hand off to a human.** Post one comment on the PR with:

- The packages, versions, class and gear, and why it needs a human ("first
  time this package has been verified", or "major version").
- Where the package touches our code (file:line, grouped by pattern).
- The tests added or reused, and what each one pins down.
- The red/green evidence: for each test, the four runs from step 3 with the
  mutation you made and a short excerpt of each failure.
- For major bumps: the breaking changes in the changelog and whether any of
  them affect our usage.
- Any change to our code, and why it was needed.
- What the reviewer should check: that the tests assert on behaviour we
  actually depend on, and that nothing we use was missed.

Then request a review from the person who chose the dependency (see below)
and mention them in the comment, saying why they were picked.

Do not merge, approve, or enable auto-merge.

**Choosing the reviewer.** The reviewer is the person who added the
dependency to our `package.json` or `composer.json`: the package itself if it
is direct, or the top-level dependency that pulls it in if it is transitive
(the top of the `via` chain). They chose the library, so they know best what
we use it for.

1. For each top-level dependency, find the commit that added it. History must
   be complete first: `git fetch --unshallow origin` if
   `git rev-parse --is-shallow-repository` says `true`.
   ```bash
   git log --reverse --format='%H %an <%ae>' -S '"<top-level>"' -- <dir>/package.json | head -1
   ```
   For Composer, search `packages/join-block/composer.json` instead.
2. Get their GitHub login from `mcp__github__get_commit` (`author.login`).
3. Use them if they have a login, aren't a bot, and are in
   `mcp__github__list_repository_collaborators`.
4. Otherwise use the most recent person who changed that dependency's line,
   skipping bots and anyone who fails step 3:
   ```bash
   git log --format='%H %an <%ae>' -G '^\s+"<top-level>": ' -- <dir>/package.json
   ```
5. If nobody qualifies, request no reviewer and say so in the comment.

A transitive package can have several top-level parents, for example
`fast-uri` comes in through both `babel-loader` and `webpack`. Request
everyone who qualifies, without duplicates. Request reviews with
`mcp__github__update_pull_request` (`reviewers`).

**Gear 2: merge on green.** All of the following must hold. If any of them
fails, drop to gear 1.

1. The ledger entry on `origin/master` matches this package and directory.
2. The usage grep from step 2 finds no files beyond the entry's `usage`. If
   it finds new ones, the package is used in a new way that has no test yet:
   cover it (step 3), which makes this PR gear 1.
3. `npm why`/`composer why` gives the same class as the entry. A dev-only
   package that now ships to users needs a new review.
4. Every test listed in the entry still exists and passes on the new version.
5. Every CI check on the PR's head commit has finished and succeeded
   (`get_check_runs`). Wait for checks that are still running rather than
   enabling auto-merge while they are pending.

Gear 2 makes no commits: the ledger records what a human reviewed, so it
keeps the reviewed version, and leaving the branch alone lets Dependabot keep
rebasing it. Enable auto-merge (`mcp__github__enable_pr_auto_merge`, merge
method `MERGE`) and post a short comment naming the ledger entry and the tests
that approved it.

## When working through every open PR

List open PRs by `dependabot[bot]` and handle them one at a time, oldest
first. Several PRs often bump packages in the same lockfile. After one
merges, the others may need a rebase (see step 6) before their CI means
anything. Finish with a table: PR, packages, class, gear, outcome (handed to
human / auto-merge enabled / failed, and why).
