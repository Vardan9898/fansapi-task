# FansAPI test task: profile refresh service and incident fix

Laravel 13 · PHP 8.4 · MySQL · Redis + Horizon · Scout · log viewer. A small service that fetches OnlyFans
profiles in background jobs (`onlyfans.com/madison420ivy` for real, via FansAPI), plus a reproduction and fix of the
production incident from the brief.

**Results in one screen**

| | Before (legacy handler) | After (fix) |
|---|---|---|
| Incident cases (`incident:reproduce`, 8 cases from the brief) | **0/8 correct** | **8/8 correct** |
| Same regression tests (`ProfileRefreshTest`) | 7 of 8 red ([commit `fdca7c1`](docs/evidence/T-004-red-expected-behaviour.txt)) | 8/8 green ([commit `c5c8024`](docs/evidence/T-005-green-expected-behaviour.txt)) |
| Workload: stored data valid | **0/25** (all `likes = 0`, all recorded as success) | **25/25** |
| Workload: healthy account first success while another account bursts | 14 s | 3 s |
| Worker SIGKILLed after the DB write, job redelivered | n/a | applied once, replay recorded as `duplicate` |
| Real fetch of madison420ivy | n/a | 609,771 likes, 176 posts, 621 photos, 201 videos stored |

157 tests (`php artisan test`). Time spent: see the end.

---

## 1. Setup

Requirements: PHP 8.3+ (8.4 tested), Composer, MySQL 8+ (9.4 tested), Redis 6+. **No npm/Vite build.**
The Redis client is `predis` (pure PHP), so the `phpredis` extension isn't needed.

```bash
composer install
cp .env.example .env && php artisan key:generate
# set DB_USERNAME / DB_PASSWORD in .env, then:
mysql -u root -p -e "CREATE DATABASE fansapi; CREATE DATABASE fansapi_test;"
php artisan migrate --seed
php artisan test                                          # 157 tests, uses fansapi_test + Redis DB 15

PHP_CLI_SERVER_WORKERS=16 php artisan serve --no-reload   # app + fake upstream on :8000 (--no-reload is required for workers)
php artisan horizon                                       # workers (another terminal)
```

Open http://localhost:8000 (dashboard), `/horizon`, `/log-viewer`. Locally they are open; elsewhere only
emails in `LOG_VIEWER_EMAILS`.

Optional real fetch: put a FansAPI key in `.env` (`FANSAPI_KEY=…`, free signup at app.onlyfansapi.com), then
`php artisan profiles:refresh fansapi madison420ivy --sync`.

## 2. The failure

Symptoms from the brief: the oldest waiting jobs keep getting older, workers report completed jobs but some
profiles show stale or empty data, one busy account delays the others, and nothing was deployed.

**Root cause.** The upstream changed (likes moved into `profile.likes`) and started returning `429` without
`Retry-After`, and `500` with an empty body. The refresh handler ([`LegacyRefreshHandler`](app/Refresh/LegacyRefreshHandler.php),
kept on purpose behind `REFRESH_HANDLER=legacy`):
1. reads `likes` only from the top level and defaults a missing value to `0`, so the format change, every 429 and every empty 500 write **likes = 0**;
2. marks **every** response as a successful refresh, so Horizon is green and `last_success_at` looks fine;
3. has no revision check, so an older response arriving last overwrites newer data;
4. has no isolation or backoff, so a busy, rate-limited account occupies every worker and other accounts' jobs wait.

**Evidence.** [What the legacy handler does, case by case](docs/evidence/T-004-legacy-handler-behaviour.md) ·
[live reproduction](docs/evidence/T-004-legacy-reproduction.txt) · [red tests](docs/evidence/T-004-red-expected-behaviour.txt).
Example log line from the reproduction: `INFO refresh.attempt outcome=updated http_status=429 changes={"likes":[120000,0]}`.

Reproduce it yourself (needs the dev server running):
```bash
php artisan incident:reproduce --handler=legacy     # table: 0/8 correct
php artisan incident:reproduce --handler=fixed      # table: 8/8 correct
```

## 3. The fix

**Protect the data** ([`ProfilePayloadParser`](app/Refresh/ProfilePayloadParser.php), [`FixedRefreshHandler`](app/Refresh/FixedRefreshHandler.php))
- Accepts both shapes, `{"likes":…}` and `{"profile":{"likes":…}}` (and the real FansAPI shape). **Validates before writing**:
  likes and revision must be integers ≥ 0; a missing value is an error, **never 0**; conflicting shapes are rejected.
- **Revision-guarded write**: the HTTP call happens first and outside any transaction. Then, inside one short transaction,
  `SELECT … FOR UPDATE`, compare the revision, and write only a **strictly newer** revision. An older response is recorded as `stale`,
  the same revision as `duplicate`. The data and its attempt row commit **together**.
- **Every attempt records its real outcome** in `refresh_attempts` (append-only): `updated`, `duplicate`, `stale`,
  `invalid_payload`, `rate_limited`, `server_error`, `timeout`, `failed`. A completed job is not proof of a refresh;
  the attempt row is. A large drop (>50%) in a valid newer response is stored but logged as `suspicious_drop`.

[Before/after per case](docs/evidence/T-005-before-after.md). The same test file is red at `fdca7c1` and green at `c5c8024`.

**Keep the queue moving** (details: [docs/queue-behaviour.md](docs/queue-behaviour.md))

| Outcome | Behaviour |
|---|---|
| 429 | **account cooldown** in Redis: `Retry-After` if sent, otherwise randomized exponential (`5s·2^(n-1)`, jittered, max 300s); the job is re-queued for the end of the cooldown |
| 5xx / timeout | exponential backoff with jitter, **max 4 retries** (counted from recorded attempts) |
| 401 | job fails **and the account is blocked** (bad credentials affect every profile) |
| 403 / 404 / invalid payload | job fails, no retry |
| any | job gives up after a 30 min window (`retryUntil`) |

**Account isolation** (job middleware, before any upstream call): blocked account → skip; account cooling down →
re-queue **without calling upstream**; at most **2 concurrent workers per account** (`Redis::funnel`). Waiting jobs
sit in Redis' delayed set and hold no worker.

**Timeouts:** `HTTP 2s connect + 5s < job 15s < Horizon worker 20s < Redis retry_after 30s`. A slow upstream
fails the attempt, not the worker, and Redis never hands a job that is **still running** to a second worker.
**No two workers on the same profile:** a per-profile overlap lock (25 s: ≥ worker timeout, < `retry_after`, so a
crashed job's redelivery isn't blocked by the dead worker's lock); the write is idempotent anyway. `TimeoutChainTest` enforces the order.

## 4. Before / after under load

`php artisan workload:run --handler=legacy|fixed` starts its own Horizon (6 workers) and runs: **`wl-busy`** 60 jobs at t=0,
upstream slow (1 s) and returning 429 for 15 s; **`wl-healthy`** 5 profiles every second for 20 s (100 jobs).
[Summary](docs/evidence/T-008-workload-summary.md) · raw: [before](docs/evidence/T-008-workload-before.txt), [after](docs/evidence/T-008-workload-after.txt).

| | Before | After |
|---|---|---|
| Healthy: successful refreshes / first success / oldest waiting | 100 recorded, **0 real** / 14 s / 13 s | **86** (+14 duplicate jobs skipped) / **3 s** / 8 s |
| Busy: upstream calls hitting the rate limit | all 60, recorded as success | **4**, then cooldown |
| Busy: successful refreshes | 60 recorded, **0 real** | 27 (+33 duplicate jobs skipped) |
| Attempts per successful refresh | 1.00 (false: data is wrong) | busy 1.15, healthy 1.00 |
| Stored data check (`likes = 100000 + revision`) | **0/25 valid, 25 zeroed** | **25/25 valid** |

Visible trade-off: the busy account recovered ~24 s after its rate limit cleared, because the cooldown keeps growing without
`Retry-After` (tunable: `REFRESH_COOLDOWN_BASE/MAX`).

**Worker crash after the DB write** (`php artisan workload:crash-replay`, [output](docs/evidence/T-008-crash-replay.txt)): a real
`queue:work` process is SIGKILLed right after the commit; the job stays reserved; Redis redelivers it after `retry_after`
(same job uuid, attempt #2), and the replay is recorded as `duplicate` with no change. What this does and doesn't prove
about a real crash: [summary § Crash replay](docs/evidence/T-008-workload-summary.md#crash-replay-worker-dies-after-its-db-write-before-acknowledging).

## 5. Real profile data

The brief asks to retrieve `onlyfans.com/madison420ivy`. OnlyFans' own API requires request signatures that exist to
block automated clients. I didn't forge them, and didn't use "unblocker" proxy services. The data comes from
**FansAPI's documented public-profile endpoint** (`GET /api/profiles/{username}`; their docs use madison420ivy as the
example). [Real fetch output](docs/evidence/T-009-real-fetch.txt): name, likes (`favoritedCount` 609,771), counts, avatar, header, bio.

FansAPI has no revision, so the **data's own timestamp** is used: `_meta._cache.cached_at` for cached answers, and the
response `Date` header for fresh ones (same upstream clock). An old cached copy arriving after newer data is therefore `stale`.
Tests use a captured response ([fixture](tests/Fixtures/upstream/fansapi_profile_madison420ivy.json), bio replaced) and can't reach the real API
(`.invalid` base URL + `Http::preventStrayRequests()`).

## 6. Observability

- **Every attempt is one log line** (`refresh.attempt`) with outcome, HTTP status, duration, format, revisions and changes. Every line of a
  job carries `account_id`, `profile_id`, `username`, `job_uuid`, `attempt` (Laravel `Context`, also propagated into jobs).
  Other events: `account.cooldown_started`, `refresh.deferred` (reason), `refresh.skipped_overlapping`, `account.blocked`,
  `refresh.suspicious_drop`, and `upstream.response` (with FansAPI cache hit, credits, rate limit remaining).
- **Secrets never reach logs**: a Monolog processor masks cookie/authorization/token/password/api_key/signing headers at any depth,
  plus bearer tokens and `?api_key=` in text. Tested.
- `/log-viewer` (opcodesio/log-viewer) shows `storage/logs` only; the dashboard links each profile to its filtered log.
- **Retention**: `LOG_REFRESH_DAYS` (7) for both log files and `refresh_attempts` rows (daily `model:prune`, chunked deletes).
- Dashboard: per-account status (active / cooling down / blocked), 24 h success rate, per-profile freshness
  (fresh / stale / failing ×N / never), refresh, block/unblock, move profiles between accounts without data loss.

## 7. Production plan

**First 15 minutes**
1. *Confirm scope from data, not job status:* Horizon wait time and throughput per queue; `refresh_attempts` (or app logs)
   by HTTP status and account for the last hour; the count of profiles whose likes dropped to 0 recently; a few raw upstream responses (`curl`).
2. *Stop the damage:* pause the refresh dispatch (or `horizon:pause-supervisor`) so no more zeros are written. Pausing beats
   letting a corrupting job run. If one account is the hot spot, pause or block that account only.
3. *Preserve evidence:* snapshot the affected profiles (ids, current values) before anything rewrites them.
4. *Communicate:* incident channel, symptom, impact (which profiles show 0), next update time.

**Rollout**
1. Migrations are additive (new tables and columns), so they run first with no behaviour change.
2. Deploy with `REFRESH_HANDLER=legacy` (no-op), then switch on **one Horizon host / supervisor** (canary) to `fixed`. Watch for 30 min:
   outcome mix, `invalid_payload` rate, attempts/success, oldest waiting age per account, DB lock waits.
3. Ramp to all workers; `horizon:terminate` on deploy so workers pick up the new code.
4. Repair data: re-refresh profiles with `likes = 0`, recently zeroed, or `last_success_at` in the incident window (bulk dispatch is
   rate-limited per account by design). Where upstream can't be reached, restore from the last valid value (backup or audit).

**Roll back when** `invalid_payload` or `failed` rise above the agreed baseline (we would be dropping valid data), DB lock waits or deadlocks
spike, the oldest waiting job keeps growing for 10+ min, or error rates in our own app rise. Rollback = flip the handler / redeploy the
previous release + `horizon:terminate`; the schema needs no rollback. Prefer **pausing** over going back to `legacy`, which writes zeros again.

**Verify recovery:** profiles with `likes = 0` back to the legitimate baseline; a spot check of 20 profiles against the upstream; the outcome mix
dominated by `updated`/`duplicate`; attempts/success ≈ 1.0–1.2; the oldest waiting job per account under its SLO; stale/failing
counts on the dashboard trending to zero; no growth in failed jobs.

## 8. Scaling: 50 million jobs per day

50M/day ≈ 579 jobs/s on average. That says nothing about peaks or required capacity. What I would measure next:

| Measure | Why | Where |
|---|---|---|
| Peak arrivals per minute (p99 vs mean) | capacity is sized for peaks; schedulers often fire on the hour | dispatch counter, Horizon throughput |
| Job duration p50/p95 and concurrency | Little's law: concurrency ≈ arrivals × duration (579/s × ~0.5 s ≈ 300 busy workers at the mean, ×3–5 at peak) | `refresh.attempt.duration_ms`, Horizon runtime |
| Retry amplification | attempts/success and deferrals/success; a 429 storm can multiply the load | `refresh_attempts` outcomes, `refresh.deferred` |
| Upstream limits | FansAPI allows **60 req/min per key** (seen in `_meta`): 579/s needs ~580 keys at 100% use | `rate_limit_remaining_minute` |
| Largest accounts | one account with 100k profiles at 60/min needs ~28 h for one sweep | profiles per account, per-account queue age |
| DB pressure | ~4 statements per refresh plus 50M attempt rows a day; pruning deletes 50M rows a day | MySQL QPS, row lock waits, table size |
| Redis pressure | pops, funnel evals, locks, delayed-set churn per job | Redis ops/s, memory, `queues:refresh:delayed` size |

**Likely bottleneck in this solution:** after the external per-key limit, it's **consumer-side throttling churn**. Isolation works by
re-queueing jobs (`account_busy`, `account_cooldown`). With a large backlog for one account and 2 slots, thousands of its jobs
cycle pop → defer → delayed set every few seconds, so Redis and worker time go to jobs that can't run. In the small workload,
the busy account produced 60 jobs for 27 real refreshes.
**Evidence to collect:** the deferral-to-attempt ratio per account, Redis ops/s by command, time workers spend on jobs shorter than 10 ms.
**First change:** move rate control to the **producer**. A scheduler admits refreshes per account through a token bucket sized to that
account's upstream limit (and dedupes per profile before enqueueing), so the queue only ever holds runnable work. Next steps
after that: monthly or daily partitioning of `refresh_attempts` (drop partitions instead of `DELETE`) or moving attempt history to an
analytics store; one shared `creators` table so a creator tracked by several accounts is fetched once.

This local workload (2 accounts, 160 jobs, one laptop) proves the mechanisms, **not** that the system handles 50M jobs a day.

## 9. Remaining limits and what I'd do next

- **Rate limit is reactive** (cooldown after a 429), not paced to FansAPI's 60/min. Producer-side pacing is the first scaling change.
- **Recovery lag** after a rate limit clears (exponential cooldown without `Retry-After`). It could probe with a single request first.
- **Single `refresh` queue**: a burst still costs pops and re-queues (see § 8).
- **FansAPI cache-miss path** (`Date` header) is tested with a fixture only; the live calls were cache hits. A real 429 from FansAPI was not observed (the fake upstream was used).
- **No login UI**: outside `local`, dashboard, Horizon and log viewer are allowlist-only for signed-in users, and nothing signs users in yet.
- **Scout** uses the database driver (`LIKE`), fine here, not at scale.
- The **legacy handler** and the **chaos hook** (`REFRESH_CHAOS_KILL_AFTER_WRITE`, never active in production) stay in the code for the
  before/after evidence; they'd be removed after the incident.
- Explicit `likes: 0` with a newer revision is accepted (valid data) and flagged as `suspicious_drop`. Holding it until a second response confirms it was considered but not built.

## 10. Map

| Path | What |
|---|---|
| `app/Jobs/RefreshProfile.php` | the job: retry policy, timeouts, middleware |
| `app/Jobs/Middleware/` | `AccountIsolation`, `SkipIfProfileBeingRefreshed` |
| `app/Refresh/` | handlers (fixed/legacy), parser, recorder, `AccountThrottle` |
| `app/Upstream/` | HTTP client (timeouts, size cap), fake upstream (Redis-scripted) |
| `app/Actions/MoveProfiles.php` | move profiles between accounts without data loss |
| `app/Console/Commands/` | `incident:reproduce`, `workload:run`, `workload:crash-replay`, `profiles:refresh[-all]`, `profiles:move` |
| `tests/` | 157 tests; fixtures in `tests/Fixtures/upstream/` |
| `docs/` | [queue behaviour](docs/queue-behaviour.md), [evidence](docs/evidence/) |

Fake upstream controls for live demos: `fake-upstream:push|default|rate-limit|latency|reset`
(e.g. `php artisan fake-upstream:push default madison420ivy new_format --likes=121000 --revision=11`).

## Time spent

About **3 h 21 min** wall clock (11:27–14:48 on 2026-10-20), including reading the brief, setup, investigating the real data source
(~30 min, ending at FansAPI's API), reviewing each step, and these docs. AI use: see [AI.md](AI.md).
