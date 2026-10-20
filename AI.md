# AI usage

## Tools

- **Claude Code** (Anthropic, desktop app) as a pair programmer: it wrote most of the code, tests and docs, ran commands, and drove a
  built-in browser (to read the brief, check the dashboard and the log viewer).
- How I worked with it: the work was split into 11 tasks. After each one I read the full diff (with a walkthrough of
  likely interview questions), asked questions, requested changes, and **committed manually** (the AI never committed or pushed).
  Design decisions were mine: e.g. MySQL instead of SQLite, the log viewer with retention, blocking and moving accounts in the UI,
  "store explicit 0 but flag a suspicious drop", and using the real API instead of scraping.
- Plain tooling: PHPUnit, Laravel Pint, Horizon, curl, MySQL/Redis CLIs.

## What was checked (not taken on trust)

- **Every behaviour claim has a test or a live run**: 157 tests; the incident replayed over real HTTP (`incident:reproduce`); the workload under
  real Horizon workers; a real SIGKILL of a worker; a real FansAPI call.
- **Tests were sabotage-checked**: key guards were broken on purpose (revision comparison `<` → `<=` or removed, cooldown check removed,
  concurrency slot bypassed, redaction tap disabled) to confirm the tests fail, then restored.
- **Framework behaviour was read in the vendor source** instead of assumed: Laravel `Context` hydration per job (no leaks between jobs),
  `ConcurrencyLimiter` (slot release in `finally`, key names), `WithoutOverlapping` (`dontRelease` doesn't delete),
  Guzzle's handler selection, Horizon's supervisor provisioning, `artisan serve` worker handling.

## Incorrect or risky suggestions that were caught

1. **"OnlyFans has no revision field"**: stated from memory, unverified. Checked against FansAPI's published schema and a real response.
   The real response then showed `_meta._cache.cached_at`, and the design changed from a Redis request counter to the **data's own timestamp**,
   which also handles stale cached copies.
2. **Fetching OnlyFans directly by forging request signatures** (public "dynamic rules") was attempted and blocked; a paid "unblocker"
   proxy was suggested later. Both bypass a site's bot protection, so I rejected them and used FansAPI's documented API instead.
3. **`stream => true` to cap response memory**: Guzzle then switches to its PHP-stream handler, which ignores `connect_timeout` and treats
   `timeout` as an idle timeout. Replaced with curl plus a `progress` callback that aborts oversized bodies.
4. **`CURLOPT_MAXFILESIZE`** as the size cap: rejected by Guzzle's allow-list.
5. **The first workload run was invalid**: `php artisan serve` ignores `PHP_CLI_SERVER_WORKERS` without `--no-reload`, so the fake upstream
   served one request at a time and jobs timed out at the server. Caught from the timeline (stuck "running" jobs, unexpected retries in legacy mode);
   the harness was fixed and the run repeated before any numbers were used.
6. **Overlap lock vs crash redelivery**: with the lock lasting longer than `retry_after`, a crashed job's redelivery would have been silently
   dropped by the dead worker's lock. Found while designing the crash test; fixed with an invariant (worker timeout ≤ lock < retry_after), now a test.
7. **Data write and attempt history in separate commits**: a crash in between would lose the history of a change. Merged into one transaction.
8. **Overlapping jobs were dropped silently** (Laravel's `dontRelease`): made visible with a log line and a per-account counter.
9. **"Auto-block the account on 401/403"** (initial plan): a 403 usually means one restricted profile, not bad credentials. Only 401 blocks.
10. **XSS in the dashboard**: an account name was put into an inline `confirm()` with `{{ }}` (HTML-escaped, not JS-escaped). Switched to `@js()`.
11. Smaller ones: a test closure captured by value (arrow function), so an assertion silently checked `null`; test helpers clashing with framework
    methods (`call()`, `run()`, `$output`); a pagination view (`simple-default`) that doesn't exist in Laravel 13; a full bio copied into
    every attempt diff (now summarized); explicit adult text kept out of the committed fixture.

## What I have not verified

- A worker killed **during** the DB transaction (relying on MySQL rolling back), or between the HTTP call and the write.
- Multi-host behaviour: clock skew, network partitions, a worker paused longer than `retry_after`, Redis failover losing reservations.
- Load anywhere near production: the workload is 2 accounts and 160 jobs on one laptop.
- FansAPI's **cache-miss** path (only cache hits were observed live; the `Date`-header versioning is covered by a fixture test) and a real FansAPI 429.
- The dashboard/Horizon/log viewer access rules with a real login (there's no login UI; the allowlist gate is tested with a test user).
- Horizon autoscaling, worker memory under sustained load, PHP-FPM/Nginx instead of `artisan serve`.
