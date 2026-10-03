# Inbox bench — baseline

mysql `crm_perf_load` · 300001 conversations · 3005000 messages · 2026-10-01T13:29:55+00:00

| scenario | status | p50 ms | p95 ms | max ms | queries avg | queries max |
|---|---|---|---|---|---|---|
| list.default | 200 | 85.3 | 185 | 200.3 | 8 | 8 |
| list.status_open | 200 | 135.8 | 316.9 | 332 | 8 | 9 |
| list.waiting | 200 | 2118.5 | 3015.3 | 3092 | 8.1 | 11 |
| list.queue_all | 200 | 10335.1 | 12198.7 | 12415.4 | 8.5 | 11 |
| list.platform | 200 | 282.1 | 453.6 | 505.4 | 8 | 8 |
| list.mine | 200 | 25934.1 | 31675.7 | 34338.7 | 4 | 6 |
| list.search_name | 200 | 15356.8 | 18988 | 20225.7 | 8.8 | 11 |
| list.search_phone | 200 | 336 | 402.8 | 402.8 | 7 | 7 |
| list.state_bot | 422 | 2.5 | 4.2 | 4.8 | 2 | 3 |
| list.state_with_moderator | 422 | 2.4 | 4.8 | 5.2 | 2 | 2 |
| list.queue_waiting | 200 | 63.6 | 81.3 | 81.9 | 8 | 9 |
| list.tag | 200 | 87.2 | 100.6 | 109 | 4.1 | 5 |
| list.assignee | 200 | 57.6 | 75.9 | 82.6 | 8 | 8 |
| detail.hot | 200 | 50.7 | 70.5 | 70.9 | 19 | 19 |
| detail.typical | 200 | 12.4 | 17.2 | 17.2 | 18 | 18 |
| messages.older | 200 | 13.6 | 17.5 | 20.8 | 5 | 5 |
| messages.after | 200 | 13.3 | 17.9 | 20.9 | 5 | 5 |

## Browser (headless Chrome 1440x900, Vite build of this branch, PHP built-in server, 4 workers)

See `browser-baseline.json`. Taken on the same `crm_perf_load` data via `tools/perf/browser-bench.mjs`.

| metric | baseline |
|---|---|
| first list paint (ms) | 1258 |
| chat switch cold (ms, 4 chats) | 482, 235, 175, 257 |
| chat switch again (ms, 4 chats) | 194, 264, 194, 215 |
| list rows in DOM after scrolling | 2730 |
| DOM nodes after list scroll | 48471 |
| long tasks during list scroll (count / max ms) | 4 / 63 |
| thread bubbles with data-message-id | 0 (attribute not present yet) |
| DOM nodes after hot-thread scroll | 47025 |
| long tasks during hot-thread scroll (count / max ms) | 177 / 687 |

Notes: `list.queue_waiting`, `messages.after` and the tag/assignee scenarios answer 200 today because the unknown parameter is ignored (only `status=bot|with_moderator` answer 422).
