# Queue behaviour: retries, isolation, timeouts

## Retry policy per outcome (`RefreshProfile::afterOutcome`)

| Outcome | Action | Limit |
|---|---|---|
| `updated` / `duplicate` / `stale` | done | n/a |
| `rate_limited` (429) | account cooldown starts (`Retry-After` if sent, else randomized exponential: `base·2^(streak-1)` with equal jitter, i.e. 3–5s, 5–10s, 10–20s… max 300s); job re-queued for when it ends | `retry_window_minutes` (30), then the job fails |
| `server_error` (5xx), `timeout` (no response: timeout, DNS, connection refused) | re-queued with exponential backoff + equal jitter (1–2s, 2–4s, 4–8s… max 60s) | `max_retries` (4) upstream failures per job, counted from `refresh_attempts` |
| `failed` (401/403/404) | job fails, no retry. **401 also blocks the account** (bad credentials: every profile would fail) | n/a |
| `invalid_payload` | job fails, no retry (needs a human: likely another format change) | n/a |

5xx and timeouts are counted from the job's own recorded attempts, not `attempts()`: re-queues for
cooldowns also bump `attempts()`, and must not eat the retry budget.

**The 429 streak counts failed probe rounds, not individual 429s.** With 2 slots, one probe after a cooldown
produces two 429s at once. If each escalated, the cooldown would double twice per round. In one measured run
the busy account recovered 104s after its limit cleared instead of ~4s. `AccountThrottle::rateLimited()` uses
Redis `SET NX` on the cooldown key: the worker that wins starts the cooldown and escalates the streak, the
others join the running cooldown (extending it only for a longer `Retry-After`).

## Account isolation (`AccountIsolation` job middleware, before any upstream call)

1. **Blocked account**: the job is dropped (it can't succeed until the profile is moved).
2. **Account cooling down**: the job is re-queued for the end of the cooldown **without calling upstream**.
3. **Concurrency slots**: at most `account_concurrency` (2) workers per account (`Redis::funnel`). A job waits up to
   `slot_wait_ms` (500 ms) for a free slot, then is re-queued in 1–2s. The short wait matters for bursts of short jobs:
   without it, 3 of every 5 healthy jobs arriving together were re-queued for 1–3s even though a slot freed within
   ~50 ms (measured: the healthy account's oldest waiting job went from 8–14s to 3s).

An account is **one upstream credential** (`accounts.api_key`, encrypted; `FANSAPI_KEY` as fallback): rate limits and
401s belong to a key, so throttling and blocking per account means per key.

Re-queued jobs sit in Redis' delayed set and hold no worker, so a bursting or rate-limited account can't
occupy all workers, and other accounts' jobs keep flowing. Evidence: [`evidence/T-006-horizon-smoke.txt`](evidence/T-006-horizon-smoke.txt).

## How the timeouts relate

```
HTTP connect 2s + request 5s  <  job $timeout 15s  <  Horizon worker timeout 20s  <  redis retry_after 30s
```

- **HTTP timeout < job timeout**: a slow upstream ends the *attempt* (recorded as `timeout`, retried with
  backoff), not the worker.
- **Job timeout ≤ worker timeout**: if a job hangs anyway, its worker process is killed (`pcntl` alarm) and the job
  becomes available for retry. Horizon restarts the worker.
- **Worker timeout < `retry_after`**: Redis only hands a reserved job to another worker after `retry_after`.
  Because the worker always dies first, a job that is **still running is never given to a second worker**.
  If `retry_after` were shorter than a long job, two workers would process the same attempt.
- **Locks expire on their own** if a worker dies while holding them: the account slot (`slot_ttl_seconds` 30) and the
  per-profile overlap lock (`OVERLAP_LOCK_SECONDS` 25). The overlap lock must be **>= the worker timeout** (it covers
  any run that can still be alive) and **< `retry_after`** (when Redis redelivers a crashed job, the dead worker's
  lock is already gone, otherwise the redelivered job would be dropped as "already refreshing").

`TimeoutChainTest` fails if anyone breaks this order in config.

## Avoiding two workers on the same attempt or profile

1. **The timeout chain above**: no redelivery while the job is still running.
2. **`SkipIfProfileBeingRefreshed`** (Laravel's `WithoutOverlapping` + `dontRelease`, but logged as
   `refresh.skipped_overlapping` and counted per account): a second job for a profile that is being refreshed right now
   is dropped (the running one fetches fresh data). The lock expires after 25s if its worker died.
3. **The data layer is idempotent anyway**: the write is a compare-and-set on `revision` under a row lock,
   so a replayed or duplicate job records `duplicate` / `stale` and changes nothing.

Layers 1 and 2 save upstream calls; layer 3 is what guarantees correct data if 1 or 2 ever fail
(e.g. a worker dies after the DB write but before acknowledging the job: `php artisan workload:crash-replay`,
evidence in [`evidence/T-008-workload-summary.md`](evidence/T-008-workload-summary.md)). The data write and its attempt row commit in one
transaction, so a crash can't leave a change without its history.

## Legacy mode

`REFRESH_HANDLER=legacy` runs the job with no middleware (no isolation, no overlap protection), so the
same workload can be measured before/after.
