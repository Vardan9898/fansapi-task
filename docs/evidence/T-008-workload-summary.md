# Workload: before vs after

`php artisan workload:run --handler=legacy|fixed` (same workload, only the handler differs). Raw reports:
[`T-008-workload-before.txt`](T-008-workload-before.txt), [`T-008-workload-after.txt`](T-008-workload-after.txt).

**Workload (small on purpose, runs in about 1 minute on a laptop):**
- `wl-busy`: 20 profiles × 3 rounds = **60 jobs at t=0**. Its upstream answers in **1000 ms** and returns
  **429 with an empty body and no `Retry-After` for the first 15 s**.
- `wl-healthy`: 5 profiles dispatched **every 1 s for 20 s = 100 jobs**. Its upstream answers in 50 ms with valid data.
- 6 Horizon refresh workers. The fake upstream serves the new format with `likes = 100000 + revision`, so stored
  data can be checked exactly.

| | Before (legacy) | After (fixed) |
|---|---|---|
| **Healthy: successful refreshes** | 100 recorded, **0 real** | **86** (+14 skipped as already refreshing) |
| **Healthy: first success** | 14 s (stuck behind the burst) | **3 s** |
| **Healthy: oldest waiting job (max)** | 13 s | **8 s** (≈3 s of it is Horizon startup) |
| **Busy: upstream calls during the rate limit** | all 60 jobs called upstream, every 429 stored as `updated` | **4** (then the account cooled down) |
| **Busy: successful refreshes** | 60 recorded, **0 real** | **27** (+33 skipped as already refreshing) |
| **Attempts per successful refresh** | 1.00 / 1.00 (looks perfect: the recorded successes are wrong) | busy **1.15**, healthy **1.00** |
| **Busy: oldest waiting job (max)** | 13 s | 62 s (deliberately parked during the cooldown, holding no worker) |
| **Stored data check** | **0/25 valid, 25 zeroed** | **25/25 valid** |
| Failed jobs | 0 | 0 |

## What it shows

- **Before**: the busy burst occupies every worker for ~13 s, so the healthy account's jobs wait behind it
  (*"one busy account delays the others"*, *"the oldest waiting jobs keep getting older"*). Every attempt is
  recorded as a success while **every profile ends up with `likes = 0`**: 429s and the new response format both
  default to zero. The healthy account is wiped too, because its upstream uses the new format.
- **After**: the busy account can use at most 2 workers. After its first 429s the whole account cools down and its jobs
  wait in Redis' delayed set (no worker held, no upstream call). The healthy account keeps 4+ workers and refreshes
  from t=3 s. Once the rate limit clears, the busy account recovers all 20 profiles with correct data.
- "Skipped (already refreshing)": a job for a profile that another worker is refreshing at that moment is dropped
  (logged as `refresh.skipped_overlapping`, counted per account). Every job is accounted for:
  busy 27 + 33 = 60, healthy 86 + 14 = 100.

## Trade-off visible in the numbers: recovery lag

The upstream stopped rate-limiting at 15 s, but the busy account's first success came at ~39 s. With no
`Retry-After` we can't know when the limit clears, so the cooldown grows with each consecutive 429
(3–5 s, 5–10 s, 10–20 s, 20–40 s). That is politer to an upstream that is struggling, but recovery is slower.
The knobs are `REFRESH_COOLDOWN_BASE` / `REFRESH_COOLDOWN_MAX`. In production I'd tune them against the real
upstream's limits, or let a single probe request test the account before releasing the backlog.

## Crash replay (worker dies after its DB write, before acknowledging)

`php artisan workload:crash-replay`, raw output: [`T-008-crash-replay.txt`](T-008-crash-replay.txt).

1. A real `queue:work` process runs the job; a chaos hook (`REFRESH_CHAOS_KILL_AFTER_WRITE`, never in production)
   sends it **SIGKILL right after the DB commit**.
2. Redis still has the job **reserved** (never acknowledged). After `retry_after` (30 s) it is handed to a second
   worker (attempt #2, **same job uuid**).
3. The second run fetches upstream again (same revision 11). The compare-and-set sees `revision 11 = 11` and records
   **`duplicate`**: the data was applied once, and the history shows both executions.

Found while building it: the data write and its attempt row used to commit separately, so a crash between them would
lose that attempt from the history. They now commit in **one transaction**.

**What this local test proves:** Redis at-least-once redelivery after a hard kill; the write is idempotent
(revision compare-and-set under a row lock); data + history are atomic; the dead worker's locks expire before
redelivery (overlap lock 25 s < `retry_after` 30 s, enforced by `TimeoutChainTest`); the replay is visible
(`attempt #2`, `duplicate`).

**What it does not prove:**
- a kill **during** the transaction (we rely on MySQL rolling back an uncommitted transaction when the connection drops)
- a kill **between the HTTP call and the write**: harmless by design (nothing written yet; redelivery fetches again),
  but not exercised here
- **Redis failover / data loss**: a reservation lost on failover can drop or duplicate a job; Redis is not a durable log
- **multi-host effects**: clock skew between workers, network partitions, a worker frozen by GC/swap longer than
  `retry_after` (a "zombie" that wakes up and writes after the job was redelivered; the revision check still protects
  the data, but two runs overlap)
- Horizon's own supervisor restart behaviour, and load: one job, one profile, one machine
- side effects outside MySQL (none here; an email or webhook would need its own idempotency key)

## Test harness note

The first run was invalid: `php artisan serve` ignores `PHP_CLI_SERVER_WORKERS` unless `--no-reload` is passed,
so the fake upstream answered one request at a time and the jobs timed out at the server, not in the app.
Fixed in `.claude/launch.json` (`PHP_CLI_SERVER_WORKERS=16 php artisan serve --no-reload`), verified with
6 parallel 1 s requests finishing in 1.06 s, then both workloads were re-run.
