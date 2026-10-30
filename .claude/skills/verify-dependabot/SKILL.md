---
name: verify-dependabot
description: Get a Dependabot pull request to merge-ready. Finds where the bumped package touches our code, adds the smallest test that would catch a change in that integration (proved with red/green), fixes whatever the upgrade breaks until CI is green, has fresh review agents review our commits until they are satisfied (at most three rounds), then either hands the PR to a human or enables auto-merge, depending on whether the package has been verified before and how big the version jump is. Use when asked to check, verify, triage, fix or merge Dependabot PRs, or with a PR number of a Dependabot PR.
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

Broken things get fixed, not reported. If the upgrade breaks a new test, an
existing test, the build, lint or E2E, fix our code until CI is green. A
person should only ever be handed a PR that works and that review agents have
already been through (step 7), or one that three rounds of fixing and review
could not get there, with a clear ask for help.

Arguments: a PR number, or nothing to work through every open Dependabot PR
(oldest first).

## The gears

The ledger is `.github/dependency-coverage/`, with one JSON file per package
at `<ecosystem>/<directory>/<package>.json`, for example
`npm/packages/join-flow/fast-uri.json`. One file per package means Dependabot
PRs that are open at the same time never conflict over the ledger. An entry on
the default branch means a human has reviewed and merged the tests listed in
it. Entries only reach the default branch through a merged PR, so "the file
exists on `master`" is the approval record. Read it from `origin/master`
(`git show origin/master:<path>`), never from the PR branch.

| Situation | Gear | What happens |
|---|---|---|
| No ledger entry on `master` | 1 | Write tests, fix what breaks, get CI green and review agents satisfied, **then hand to a human**. |
| Ledger entry, minor or patch bump | 2 | Re-check usage, wait for CI, **enable auto-merge**. |
| Major bump (any ledger state) | 3 | As gear 1 or 2, plus read the changelog, **then hand to a human**. |

Rules that decide the gear:

- `0.x` versions: treat a minor bump (`0.10` to `0.11`) as major, since semver
  allows breaking changes there.
- A PR that bumps several packages takes the highest gear of any of them.
- If anything in gear 2 needs a code change (a new test, a fix to our code,
  because CI is red), the PR drops to gear 1.
- Red CI on a Dependabot PR is always work to do, whatever the gear and
  whether or not we have pushed anything to it yet. Go to step 7.
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
4. If the PR is behind `master`, bring it up to date first with
   `mcp__github__update_pull_request_branch`. This merges `master` into the
   branch, so CI runs the current checks (such as the Dev Server workflow)
   and the helper scripts in `scripts/` are there to use.
5. Fetch the branch (`git fetch origin <head-ref>`) and check it out.

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
     changelog and find out what changed, then fix our code so it works with
     the new version (step 7 says what counts as a fix). The test stays as
     it is unless our expectation, not our code, was what was out of date.

After the proof, `git status` must show only the new or changed test files,
the ledger, and any fix. No leftover mutations.

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

These classes have nothing new to commit except the ledger entry, but they
still need the red/green proof from step 3. It shows the build or the dev
server really does exercise the package:

1. Green on the old version, green on the new version (as in step 3).
2. Red on our side: break something in our use of the tool. For a package
   used for options validation (`schema-utils`, `ajv`), add an unknown option
   to a loader in `webpack/common.js` that actually runs. Use the `.scss`
   rule: nothing imports `.css`, so that rule never runs.
3. Red on the library side: edit the package in `node_modules` so a function
   on the path returns something different, and check the build or dev server
   fails. If it still passes, the tool never calls that code. Say so on the
   PR, because then our checks can't catch a bad upgrade of this package.

## Step 5: Update the ledger

Create or update the package's file in `.github/dependency-coverage/`, for
example `npm/packages/join-flow/google-libphonenumber.json`:

```json
{
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
```

- The path is `<ecosystem>/<directory>/<package>.json`, with the ecosystem
  `npm` or `composer`. A scoped or vendored name becomes a subdirectory
  (`npm/packages/join-flow/@sentry/react.json`,
  `composer/packages/join-block/guzzlehttp/guzzle.json`). The same package in
  two directories gets two files, because the usage is different.
- `via` is the dependency chain from `npm why`/`composer why`, top level
  first. When several top-level dependencies pull it in, list them all,
  comma-separated, as the first item. It is empty for direct dependencies.
- `usage` lists the files of ours that use it (from step 2). It is empty for
  non-runtime classes.
- `tests` lists what verifies it. For non-runtime classes, name the CI
  job or the smoke check, for example `"ci: E2E Tests › Build join-flow bundle"`.
- `reviewedVersion` and `reviewedIn` are the new version and this PR. Only
  gear 1 and 3 PRs, which a human reviews, write them.

## Step 6: Push to the Dependabot branch

Commit the tests, any fix and the ledger in one commit on the PR's head
branch, with a message saying what the tests pin down. Then
`git push origin HEAD:<head-ref>`.

Things to know about pushing to a Dependabot branch:

- After anyone else commits, Dependabot stops rebasing the PR. When it falls
  behind `master` or conflicts, update it with
  `mcp__github__update_pull_request_branch`, or merge `origin/master` into it
  and push. Never rebase or force-push a Dependabot branch, and never comment
  `@dependabot recreate`: both throw away commits.
- Never change the version Dependabot bumped. If the fix needs another
  dependency to move too (a companion or peer package), change it with
  `npm install <package>@<version>` or `composer require`, so the lockfile is
  regenerated properly, and explain why in the commit and the hand-off.

## Step 7: Get it green and reviewed

Every PR we have pushed commits to (tests, fixes, even a ledger entry alone)
goes through this before a person sees it. Tests are code, and code gets
reviewed. So does every Dependabot PR whose CI is red, even before we have
added anything: an existing test, lint, the build or E2E failing on the new
version is a break to fix.

Keep one status comment on the PR, with `<!-- verify-dependabot -->` as its
first line, and edit it (`mcp__github__update_issue_comment`) rather than
posting new ones. It holds the gear, the current round, and what is
outstanding, so a later run can pick up where this one stopped.

**A round** starts when you push and ends when a review agent has reviewed
that push, or when CI on it comes back red.

1. **Get CI green.** For each failing check, read its log
   (`get_check_runs`, `get_job_logs`), reproduce the failure locally, find
   the root cause and fix it, as you would for any broken build. Fixing
   means making our code work with the new version: adapting a call to a
   changed API, updating a type, handling a new return value, updating
   config. Run the affected suites locally, then push. Then subscribe to the
   PR (`mcp__claude-code-remote__subscribe_pr_activity`) and end your turn:
   the CI result wakes you, so don't poll. Never:
   - skip, disable, delete or weaken a test or assertion to get green;
   - pin the package back to its old version, which undoes the PR;
   - push an empty commit, or close and reopen the PR, to re-run CI.

   Changing a test's expectation is fine only when the library's new
   behaviour is correct and our expectation was what was out of date. Say
   so explicitly in the hand-off. If the fix would change something members
   or organisers notice (the form, an email, a payment, CRM data), stop and
   escalate (below), because that is a product decision.
2. **Review.** Once CI is green on the head commit, start a review agent with
   fresh context. Use `Agent` with `subagent_type: "general-purpose"` and
   `run_in_background: false`, never `fork`, which would inherit your
   reasoning. Give it the PR, not your opinion of it:

   > Run the `code-review` skill (Skill tool, args
   > `high --comment <PR number>`) on commonknowledge/join#<PR number>, so
   > your findings are posted as inline review comments on the PR. It is a
   > Dependabot dependency bump. The commits after Dependabot's add tests,
   > fixes and a ledger entry under `.github/dependency-coverage/` that
   > verify how our code uses the bumped package. Review all of it. Then
   > reply to me with each finding (file:line, what is wrong, how serious)
   > and end with exactly one line: `VERDICT: ready` if nothing blocking
   > remains, or `VERDICT: changes needed`.

3. **Act on the findings.** Fix every finding that is correct and in
   proportion, then answer every thread the review agent opened, so the
   person reviewing later can follow what happened:
   - Fixed: reply with the commit that fixes it, and resolve the thread
     (`mcp__github__add_reply_to_pull_request_comment`,
     `mcp__github__resolve_review_thread`).
   - Not fixed: reply with the reason and leave the thread open, so the
     person reviewing can see it and decide.

   If you pushed anything, that push starts the next round,
   which gets a **new** review agent. Never resume an earlier one with
   `SendMessage`: every review needs a fresh read.

The PR is ready for a person when CI is green on the head and the latest
review agent said `VERDICT: ready`. Go to step 8.

**At most three rounds.** If after the third round CI is still red or the
latest review agent still says `VERDICT: changes needed`, stop pushing and
escalate. Also escalate straight away, at any round, when the fix is a product
decision or the new version can't work with our code without a larger change
than a dependency bump should carry. To escalate, post a new comment (so the
mention notifies) that mentions the reviewer chosen as in step 8 and asks for
their help. Say what is still failing (check names and log excerpts), the
open review findings, what you tried and what you think the options are.
Request their review too. Update the status comment to say it was escalated.

## Step 8: Hand off or merge

**Gear 1 and 3: hand off to a human.** Post one new comment on the PR (the
status comment's edits don't notify anyone) with:

- The packages, versions, class and gear, and why it needs a human ("first
  time this package has been verified", or "major version").
- Where the package touches our code (file:line, grouped by pattern).
- The tests added or reused, and what each one pins down.
- The red/green evidence: for each test, the four runs from step 3 with the
  mutation you made and a short excerpt of each failure.
- For major bumps: the breaking changes in the changelog and whether any of
  them affect our usage.
- Any change to our code, and why it was needed. Call out any test whose
  expectation changed, and why the new behaviour is right.
- The review rounds from step 7: how many, and a short summary of what was
  fixed. The detail is in the review threads. Point the reviewer at any
  thread left open, which is a finding we chose not to fix.
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
fails, drop to gear 1. If a check is red or a ledgered test fails, fix it in
step 7 rather than handing it over as it is.

1. The package's ledger file exists on `origin/master`.
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

List open PRs by `dependabot[bot]` and take them oldest first. For each one,
read its status comment first and carry on from the round it records,
instead of starting again. While one PR waits for CI, move on to the next
and come back when the CI result arrives. Several PRs often bump packages in
the same lockfile. After one merges, the others may need updating from
`master` (see step 6) before their CI means anything.

Finish with a table: PR, packages, class, gear, rounds used, and outcome
(handed to a person / auto-merge enabled / waiting on CI / escalated, and
why).
