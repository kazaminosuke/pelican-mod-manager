# Isolated E2E / Regression Verification Suite (WIP Scaffold — Pre-M2..M5)

> **Status**: **Work-in-progress verification scaffold (intentionally isolated from `phpunit.xml`).**
>
> This directory holds the requirement-driven 4-tier E2E/regression test suite and high-fidelity Pelican Wings HTTP fake (`StrictWingsFake`) built during the UI fix & Modpack comparative audit work. It is intentionally **not** wired into root [`phpunit.xml`](../../phpunit.xml) yet because Features #5–#22 (Milestones M2–M5) are still unimplemented and their corresponding assertions are designed to fail cleanly until those milestones land.

## Current Baseline State (as of M1 commit `875e30f`)

- **Files**: 30 files (`bootstrap.php`, `phpunit-e2e.xml`, `Support/*`, `Fixtures/*`, `Tier1/*`–`Tier4/*`, 81 PHPUnit tests)
- **Static & Style Checks (verified by author on Iteration 2)**:
  - `php -l` across all 29 PHP files: **PASS (0 syntax errors)**
  - Laravel Pint (`pint --test --config=pint.json`): **PASS (29/29 files; 281/281 repo-wide)**
  - PHPStan Level 6 (`Support` + `Fixtures`): **0 errors**
  - PHPStan Level 2 (all `tests/E2E`): **0 errors**
- **Runtime Results (`tests/E2E/phpunit-e2e.xml`)**:
  - `E2E SelfTest`: **13/13 PASS** (274 assertions)
  - Live M1 UI E2E tests (`UiCatalogLayoutAndViewE2ETest`, `UiTileOverflowAndPersistenceBoundaryE2ETest`, `UiModalSyncAndColumnManagerWorkflowE2ETest`): **9/9 PASS** (665 assertions)
  - Full E2E suite: **29 PASS / 52 expected pre-M2..M5 assertion failures / 0 Errors / 0 Skipped / 0 Risky**
- **Remaining E2E Verification**:
  - Iteration 2 test implementation passed all author checks above, while the independent Iteration 2 review pass was intentionally stopped during handoff to conserve quota. Validate and adjust assertions incrementally as Milestones M2–M5 turn the 52 pending tests green, then wire `tests/E2E` into root `tests/bootstrap.php` and `phpunit.xml` at M6.

## Running Locally (Inside a Temporary `pelican-dev/panel` Checkout)

```bash
SCRATCH=$(mktemp -d)
trap 'rm -rf "$SCRATCH"' EXIT
git clone --depth=1 https://github.com/pelican-dev/panel "$SCRATCH/pelican"
(cd "$SCRATCH/pelican" && composer install --no-interaction --prefer-dist --no-progress --no-scripts && cp .env.example .env)
mkdir -p "$SCRATCH/pelican/plugins/pelican-mod-manager"
rsync -a ./ "$SCRATCH/pelican/plugins/pelican-mod-manager/" \
  --exclude=.git --exclude=.github --exclude=.idea --exclude=.agents

# SelfTest (must always pass 100%)
(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/tests/E2E/phpunit-e2e.xml --testsuite="E2E SelfTest")

# Live M1 UI tests (must pass 100%)
(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/tests/E2E/phpunit-e2e.xml --filter="UiCatalogLayoutAndViewE2ETest|UiTileOverflowAndPersistenceBoundaryE2ETest|UiModalSyncAndColumnManagerWorkflowE2ETest")

# Targeted milestone runs as M2..M5 are implemented
(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/tests/E2E/phpunit-e2e.xml --filter="Core")
```

See [`docs/handoff/HANDOFF.md`](../../docs/handoff/HANDOFF.md) and [`docs/handoff/e2e-test-infra.md`](../../docs/handoff/e2e-test-infra.md) for the full traceability matrix, `StrictWingsFake` contract, and Tier 4 quantitative efficiency bounds.
