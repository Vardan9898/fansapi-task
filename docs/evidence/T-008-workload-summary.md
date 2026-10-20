# Workload: before vs after

`php artisan workload:run --handler=legacy|fixed` (same workload, only the handler differs). Raw reports:
[`T-008-workload-before.txt`](T-008-workload-before.txt), [`T-008-workload-after.txt`](T-008-workload-after.txt).

**Workload (small on purpose, runs in under a minute on a laptop):**
- `wl-busy`: 20 profiles × 3 rounds = **60 jobs at t=0**. Its upstream answers in **1000 ms** and returns
  **429 with an empty body and no `Retry-After` for the first 15 s**.
- `wl-healthy`: 5 profiles dispatched **every 1 s for 20 s = 100 jobs**. Its upstream answers in 50 ms with valid data.
- 6 Horizon refresh workers, `account_concurrency` 2. The fake upstream serves the new format with
  `likes = 100000 + revision`, so stored data can be checked exactly.

| | Before (legacy) | After (fixed) |
|---|---|---|
| **Healthy: successful refreshes** | 100 recorded, **0 real** | **69** (+31 skipped as already refreshing) |
| **Healthy: first success / oldest waiting job** | 13 s / 13 s (stuck behind the burst) | **4 s** (≈3 s Horizon startup) / **3 s** |
| **Busy: upstream calls answered 429** | 60, every one stored as `updated` | **6** (3 probe rounds × 2 slots), then cooldowns |
| **Busy: successful refreshes** | 60 recorded, **0 real** | **30** (+30 skipped as already refreshing) |
| **Busy: first success after the limit cleared (15 s)** | never (data zeroed) | **19 s**, i.e. 4 s later |
| **Busy: oldest waiting job** | 13 s | 35 s: deliberately parked during the cooldown, holding no worker |
| **Upstream calls per successful refresh** | 1.00 / 1.00 (looks perfect: the recorded successes are wrong) | busy **1.20**, healthy **1.00** |
| **Stored data check** | **0/25 valid, 25 zeroed** | **25/25 valid** |
| Failed jobs | 0 | 0 |

Definitions:
- **Upstream calls per successful refresh** = attempt rows / successful attempt rows. A re-queue without an upstream call
  (`account_cooldown`, `account_busy`) is not an attempt.
- **Skipped (already refreshing)**: a job dropped because another worker was refreshing the same profile at that moment
  (logged as `refresh.skipped_overlapping`, counted per account). Every job is accounted for: busy 30 + 30 = 60, healthy 69 + 31 = 100.

Repeatability: three runs of the fixed workload gave healthy oldest waiting 3 s every time, and the busy account's
first success at 19–23 s with 20/20 valid.

## What it shows

- **Before**: the busy burst occupies every worker for ~13 s, so the healthy account's jobs wait behind it
  (*"one busy account delays the others"*, *"the oldest waiting jobs keep getting older"*). Every attempt is
  recorded as a success while **every profile ends up with `likes = 0`**: 429s and the new response format both
  default to zero. The healthy account is wiped too, because its upstream uses the new format.
- **After**: the busy account can use at most 2 workers. After its first 429s the whole account cools down and its jobs
  wait in Redis' delayed set (no worker held, no upstream call). The healthy account keeps flowing (oldest wait 3 s).
  When the rate limit clears, the busy account recovers within seconds and ends with correct data on all 20 profiles.

## Two queue bugs found by re-measuring (fixed)

Re-running the workload after the first "after" measurement showed inconsistent results. Both causes were real:

1. **Concurrent 429s escalated the cooldown several times.** With 2 slots, one probe after a cooldown returns two 429s
   at once, and each doubled the cooldown. In one run the busy account recovered **104 s** after its limit cleared.
   Fix: the streak counts failed *probe rounds*. Redis `SET NX` on the cooldown key elects one worker to escalate; the
   others join the running cooldown. Now recovery comes 4–8 s after the limit clears.
2. **Bursts of short jobs were re-queued for seconds.** The healthy account's 5 jobs per second arrive together; with
   2 slots, 3 of them were re-queued for 1–3 s although a slot freed within ~50 ms. The healthy account's oldest wait
   was 8–14 s. Fix: a job waits up to 500 ms for a slot before re-queueing (bounded worker time). Now it's 3 s, every run.

Both have tests (`test_concurrent_429s_of_one_wave_escalate_only_once`, `test_waits_briefly_for_a_slot_then_gives_up`),
and the first was sabotage-checked (removing `SET NX` makes the test fail with a 16 s cooldown).

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

## Memory

Every job logs `peak_memory_mb` (the worker process's peak so far). Across 244 workload jobs plus the real fetch, the
maximum was **38.5 MB**, against Horizon's 128 MB worker limit.

## Test harness note

The first run was invalid: `php artisan serve` ignores `PHP_CLI_SERVER_WORKERS` unless `--no-reload` is passed,
so the fake upstream answered one request at a time and the jobs timed out at the server, not in the app. The fake
upstream must run as `PHP_CLI_SERVER_WORKERS=16 php artisan serve --no-reload`. This was verified with 6 parallel 1 s
requests finishing in 1.06 s, and both workloads were re-run.
