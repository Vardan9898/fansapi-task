# Before / after the fix

Same cases, same starting state (likes 120000, revision 10), same job and HTTP client against the fake
upstream. Only `REFRESH_HANDLER` differs.

| Case | Legacy: stored / recorded | Fixed: stored / recorded |
|---|---|---|
| Old format, same data | 120000 / r10 · updated | 120000 / r10 · duplicate |
| New format `profile.likes=121000` r11 | **0** / r11 · updated | **121000 / r11** · updated |
| Likes missing everywhere | **0** / r12 · updated | 120000 / r10 · invalid_payload |
| 429, empty body, no Retry-After | **0** / r10 · updated | 120000 / r10 · rate_limited |
| 500, empty body | **0** / r10 · updated | 120000 / r10 · server_error |
| Timeout | 120000 / r10 · *job failed, nothing recorded* | 120000 / r10 · timeout |
| r11 delivered twice | **0** / r11 · updated, updated | 121000 / r11 · updated, duplicate |
| r11, then older r10 | **120000 / r10** · updated, updated | 121000 / r11 · updated, stale |
| **Correct** | **0/8** | **8/8** |

Raw output: [`T-004-legacy-reproduction.txt`](T-004-legacy-reproduction.txt) and [`T-005-fixed-reproduction.txt`](T-005-fixed-reproduction.txt).
Tests: the same `ProfileRefreshTest` file is red on legacy ([`T-004-red-expected-behaviour.txt`](T-004-red-expected-behaviour.txt))
and green on fixed ([`T-005-green-expected-behaviour.txt`](T-005-green-expected-behaviour.txt)).

Guard check: changing the stale comparison from `<` to `<=`, or deleting it, makes 2 of these tests fail.
