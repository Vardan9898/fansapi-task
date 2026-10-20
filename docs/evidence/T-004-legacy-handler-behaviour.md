# What the broken (legacy) handler does

Handler: `App\Refresh\LegacyRefreshHandler` (`REFRESH_HANDLER=legacy`): reads `likes` from the top level,
defaults a missing value to `0`, and marks every response as a successful refresh.

Starting state for every case: last valid `likes = 120000`, `revision = 10`.

| Case | Upstream sends | Stored after | Recorded as | Should be |
|---|---|---|---|---|
| Old format, same data | `200 {"likes":120000,"revision":10}` | 120000 / r10 | updated | 120000 / r10, duplicate |
| Format change | `200 {"profile":{"likes":121000},"revision":11}` | **0** / r11 | updated | 121000 / r11, updated |
| Likes missing everywhere | `200 {"revision":12}` | **0** / r12 | updated | 120000 / r10, invalid_payload |
| Rate limit | `429`, empty body, no `Retry-After` | **0** / r10 | updated | 120000 / r10, rate_limited |
| Server error | `500`, empty body | **0** / r10 | updated | 120000 / r10, server_error |
| Timeout | no response | 120000 / r10 | *nothing: job fails* | 120000 / r10, timeout |
| Duplicate run | r11 twice | **0** / r11 | updated, updated | 121000 / r11, updated + duplicate |
| Out of order | r11, then older r10 | **120000 / r10** | updated, updated | 121000 / r11, updated + stale |

**0/8 correct.** Live run: [`T-004-legacy-reproduction.txt`](T-004-legacy-reproduction.txt).
Failing tests for the correct behaviour: [`T-004-red-expected-behaviour.txt`](T-004-red-expected-behaviour.txt).

## How this explains the reported symptoms

- *"Workers report completed jobs, but some profiles show stale or empty data"*: every 429, empty 500 and
  new-format response is stored as `likes = 0` **and** recorded as `updated`, with `last_success_at` bumped.
  The queue job completes normally, so Horizon shows green while the data is wrong.
- *Stale data*: there is no revision check, so a slower, older response overwrites a newer one.
- *No application deployment*: the upstream changed its format (`likes` moved into `profile`) and started
  rate-limiting. The legacy code never validated the response, so the change went unnoticed.
- *The oldest waiting jobs keep getting older* and *one busy account delays the others* are queue
  behaviours (no per-account limits, no backoff). They are reproduced with the workload in T-008.

## Reproduce it yourself

```bash
php artisan serve                                  # fake upstream lives at /fake-upstream
php artisan incident:reproduce --handler=legacy    # table above
REFRESH_HANDLER=legacy php artisan test --filter=ProfileRefreshTest   # expected behaviour: red on legacy (phpunit.xml defaults to fixed)
vendor/bin/phpunit --filter=LegacyRefreshHandlerTest   # records the legacy behaviour (green)
```
