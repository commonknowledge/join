# Dependency coverage ledger

One JSON file per dependency, at `<ecosystem>/<directory>/<package>.json`, records the tests that verify how our code uses it. The `/verify-dependabot` skill (`.claude/skills/verify-dependabot/SKILL.md`) writes these files when a package is first bumped, and a human reviews them in that Dependabot PR.

A file on `master` means its tests have been reviewed, so later minor and patch bumps of that package can auto-merge when CI is green. Adding or editing a file outside a reviewed PR bypasses that review.
