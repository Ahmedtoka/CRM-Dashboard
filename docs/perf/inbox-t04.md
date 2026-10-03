# Inbox bench — t04

mysql `crm_perf_load` · 300007 conversations · 3005000 messages · 2026-10-01T16:04:21+00:00

| scenario | status | p50 ms | p95 ms | max ms | queries avg | queries max |
|---|---|---|---|---|---|---|
| list.default | 200 | 13.2 | 22.4 | 61.2 | 6.1 | 7 |
| list.status_open | 200 | 12.6 | 14.2 | 16.8 | 7 | 7 |
| list.waiting | 200 | 16.4 | 24.9 | 28.5 | 7 | 7 |
| list.queue_all | 200 | 15.9 | 27.3 | 29.1 | 7.1 | 8 |
| list.platform | 200 | 16.9 | 24.2 | 26.9 | 6 | 6 |
| list.mine | 200 | 6.2 | 7.9 | 8.4 | 3 | 3 |
| list.search_name | 200 | 190.6 | 223.5 | 250.4 | 6 | 6 |
| list.search_phone | 200 | 62.5 | 89.9 | 96.5 | 6 | 6 |
| list.state_bot | 200 | 16 | 22.2 | 22.3 | 6 | 6 |
| list.state_with_moderator | 200 | 17.6 | 28.2 | 31.4 | 7.1 | 8 |
| list.queue_waiting | 200 | 8.5 | 10.8 | 12 | 3 | 3 |
| list.tag | 200 | 121.6 | 146.9 | 153.3 | 4 | 4 |
| list.assignee | 200 | 23.5 | 31.2 | 34.8 | 7.1 | 8 |
| detail.hot | 200 | 31.9 | 55.9 | 66.4 | 13 | 13 |
| detail.typical | 200 | 14.9 | 17.7 | 20.2 | 11 | 11 |
| messages.older | 200 | 17.8 | 20.4 | 23.4 | 5.1 | 6 |
| messages.after | 200 | 5.3 | 6.5 | 6.9 | 5 | 5 |

## Before / after (Task 4a)

`baseline` = docs/perf/inbox-baseline.md (old code, optimizer statistics as left by the seeder).
`old code, fresh stats` = the old code re-run (runs=5) after `ANALYZE TABLE` on the same data, to separate the
statistics effect from the code changes (see the note below). Targets: list p95 ≤ 150 ms, search ≤ 300 ms,
detail ≤ 200 ms, list ≤ 10 queries, detail ≤ 15 queries.

| scenario | baseline p50 / p95 / q | old code, fresh stats p50 / p95 / q | t04 p50 / p95 / q |
|---|---|---|---|
| list.default | 85.3 / 185 / 8 | 18.6 / 19.4 / 8 | 13.2 / 22.4 / 6.1 |
| list.status_open | 135.8 / 316.9 / 8 | 13.9 / 40.3 / 8 | 12.6 / 14.2 / 7 |
| list.waiting | 2118.5 / 3015.3 / 8.1 | 2563.1 / 3323.4 / 8 | 16.4 / 24.9 / 7 |
| list.queue_all | 10335.1 / 12198.7 / 8.5 | 13737.9 / 16120.2 / 8.6 | 15.9 / 27.3 / 7.1 |
| list.platform | 282.1 / 453.6 / 8 | 20.0 / 27.9 / 8 | 16.9 / 24.2 / 6 |
| list.mine | 25934.1 / 31675.7 / 4 | 40351.4 / 45971.7 / 4.8 | 6.2 / 7.9 / 3 |
| list.search_name | 15356.8 / 18988 / 8.8 | 16713.7 / 17861.4 / 9.2 | 190.6 / 223.5 / 6 |
| list.search_phone | 336 / 402.8 / 7 | 290.4 / 345.2 / 7 | 62.5 / 89.9 / 6 |
| list.state_bot | 422 (not yet a filter) | 422 | 16.0 / 22.2 / 6 |
| list.state_with_moderator | 422 (not yet a filter) | 422 | 17.6 / 28.2 / 7.1 |
| list.queue_waiting | 63.6 / 81.3 / 8 (param ignored) | 27.1 / 81.4 / 8 (ignored) | 8.5 / 10.8 / 3 |
| list.tag | 87.2 / 100.6 / 4.1 | 191.6 / 213.8 / 4 | 121.6 / 146.9 / 4 |
| list.assignee | 57.6 / 75.9 / 8 (param ignored) | 13.9 / 16.7 / 8 (ignored) | 23.5 / 31.2 / 7.1 |
| detail.hot | 50.7 / 70.5 / 19 | 34.9 / 39.5 / 19 | 31.9 / 55.9 / 13 |
| detail.typical | 12.4 / 17.2 / 18 | 9.5 / 9.9 / 18 | 14.9 / 17.7 / 11 |
| messages.older | 13.6 / 17.5 / 5 | 7.8 / 8.2 / 5 | 17.8 / 20.4 / 5.1 |
| messages.after | 13.3 / 17.9 / 5 (param ignored) | 7.6 / 8.2 / 5 (ignored) | 5.3 / 6.5 / 5 |

Notes:
- The MariaDB here is XAMPP's default (`innodb_buffer_pool_size` = 16 MB against a 196 MB `conversations`
  table), so every row lookup outside a small working set is a read from the OS file cache. Plans that touch
  thousands of rows (list.tag: 5,000 tagged conversations, all of them spam in this dataset, so the page is empty
  and every candidate is checked) stay in the 100-200 ms range whatever the SQL; production should run with a
  buffer pool that holds the hot tables.
- The seeder left stale optimizer statistics (`conversation_tag` estimated one row per tag, so the tags eager load
  scanned the pivot: 45-55 ms on every list). `ANALYZE TABLE` was run on the perf tables during Task 4a; the middle
  column shows what that alone does for the old code. list.tag is unchanged code: its plan moved with the statistics.
- list.tag / list.assignee / list.queue_waiting / messages.after / state_* were 200-with-ignored-param or 422 in
  the baseline, so their baseline rows are not comparable.
- The dataset has 6 extra conversations with Arabic customer names (search check), hence 300,007.
