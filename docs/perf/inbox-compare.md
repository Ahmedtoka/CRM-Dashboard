# Inbox and board: before / after (UI overhaul, Task 11)

Same MariaDB dataset for both server runs: `crm_perf_load` (300,001 seeded conversations + 6 Arabic-name customers
from Task 4a + 3 «عبدالله» customers added for `list.search_substring` = 300,010; 3,005,000 messages). XAMPP MariaDB
defaults (16 MB InnoDB buffer pool), Windows, in-process bench (`crm:inbox-bench`, no network).

- before = `inbox-baseline.json` / `.md` (Task 1, old code, 2026-10-01).
- after = `inbox-after.json` / `.md` (this branch at e9c1161, all branch migrations run, `--runs=30 --warmup=3`,
  2026-10-02). MariaDB had just been restarted (cold buffer pool before the warm-up).
- Task 4a's intermediate table (with the "old code, fresh statistics" column) is in `inbox-t04.md`.

## Server scenarios

| scenario | before p50 / p95 / queries | after p50 / p95 / queries | target | pass |
|---|---|---|---|---|
| list.default | 85.3 / 185 / 8 | 12.9 / 20.7 / 6 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.status_open | 135.8 / 316.9 / 8 | 16.2 / 23.4 / 7 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.waiting | 2118.5 / 3015.3 / 8.1 | 30.7 / 35.3 / 7.1 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.queue_all | 10335.1 / 12198.7 / 8.5 | 32.4 / 50.3 / 7.1 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.platform | 282.1 / 453.6 / 8 | 31.1 / 39.1 / 6 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.mine | 25934.1 / 31675.7 / 4 | 17.8 / 27.8 / 3 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.search_name | 15356.8 / 18988 / 8.8 | 247.5 / 367 / 6 | p95 ≤ 300 ms, ≤ 10 q | **no** |
| list.search_phone | 336 / 402.8 / 7 | 72.9 / 115.2 / 6 | p95 ≤ 300 ms, ≤ 10 q | yes |
| list.search_substring | — (new scenario) | 100863.6 / 100863.6 / 7 (1 run, see note 1) | p95 ≤ 300 ms, ≤ 10 q | **no (defect)** |
| list.state_bot | 422 (not yet a filter) | 16 / 25.4 / 6 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.state_with_moderator | 422 (not yet a filter) | 25.8 / 41 / 7 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.queue_waiting | 63.6 / 81.3 / 8 (param ignored) | 5.9 / 8.1 / 3 | p95 ≤ 150 ms, ≤ 10 q | yes |
| list.tag | 87.2 / 100.6 / 4.1 | 119.6 / 189.4 / 4 | p95 ≤ 150 ms, ≤ 10 q | **no** |
| list.assignee | 57.6 / 75.9 / 8 (param ignored) | 27.2 / 39.2 / 7 | p95 ≤ 150 ms, ≤ 10 q | yes |
| detail.hot | 50.7 / 70.5 / 19 | 19 / 33.8 / 13 | p95 ≤ 200 ms, ≤ 15 q | yes |
| detail.typical | 12.4 / 17.2 / 18 | 8.8 / 14.9 / 11.1 | p95 ≤ 200 ms, ≤ 15 q | yes |
| messages.older | 13.6 / 17.5 / 5 | 10 / 16.8 / 5 | p95 ≤ 150 ms, ≤ 10 q | yes |
| messages.after | 13.3 / 17.9 / 5 (param ignored) | 3.5 / 4.6 / 5 | p95 ≤ 150 ms, ≤ 10 q | yes |

Notes:
1. **`list.search_substring` (`?q=الله`, found only inside «عبدالله») is a defect, not a measurement.** The
   indexed search finds nothing, so `ConversationQuery::paginate()` re-runs the page with the old
   `whereHas('customer', name/phone LIKE '%term%')` (Task 4b fallback). On MariaDB that is a dependent `EXISTS`
   driven from `conversations` in `last_message_at` order: with 3 matching conversations out of 300,010 it walks the
   whole index and looks up every customer. One request took **100.9 s** (bench `--runs=1`); the same SQL in the
   mysql client took 125 s. `EXPLAIN`: `conversations` by `conversations_last_message_at_id_index` ("Using where")
   + `customers` eq_ref. The customers-only scan (`SELECT id FROM customers WHERE name LIKE '%الله%' OR phone LIKE
   '%الله%'`) takes 0.48 s, so starting from the matching customer ids (as the indexed path does with its derived
   table) should bring it near the target. The 30-run bench was run with this one scenario commented out locally
   (the committed scenario list includes it): 30 runs of it would take about an hour.
2. `list.search_name` p95 is 367 ms against the 300 ms target (p50 248 ms). Task 4a measured 223.5 ms p95 on the
   same data and code path; this run started on a just-restarted MariaDB with a 16 MB buffer pool, and the FULLTEXT
   + prefix union reads from the OS file cache. Borderline; worth one re-run on a warm server before calling it.
3. `list.tag` p95 189 ms (> 150): unchanged code; all 5,000 tagged conversations are spam in this dataset, so the
   page is empty and every candidate is checked (see `inbox-t04.md`). Same range as Task 4a (146.9 ms).
4. `list.state_*`, `list.queue_waiting`, `list.assignee` and `messages.after` were 422, or answered with the
   parameter ignored, before the overhaul: their before rows are not comparable.

## Browser (headless Chrome 1440x900, Vite build of e9c1161, PHP built-in server on port 8011, same data)

`node tools/perf/browser-bench.mjs http://127.0.0.1:8011 bench@load.test load-password after 300001`;
JSON: `browser-after.json` and `browser-after-2.json` (an immediate repeat). Windows' built-in PHP server cannot
fork, so it serves one request at a time, as in the baseline.

| metric | before | after | after (repeat run) |
|---|---|---|---|
| first list paint (ms) | 1258 | 761 | 809 |
| chat switch cold (ms, 4 chats) | 482, 235, 175, 257 | 513, 1131, 139, 145 | 442, 1112, 180, 180 |
| chat switch again, cached (ms, 4 chats) | 194, 264, 194, 215 | 56, 56, 55, 49 | 77, 84, 76, 76 |
| list rows loaded (scrolled to ~3000) | 2730 | 2850 | 2730 |
| list rows in the DOM | 2730 | 26 (virtualised) | 26 |
| DOM nodes after list scroll | 48471 | 910 | 912 |
| long tasks during list scroll (count / max) | 4 / 63 ms | 0 / 0 ms | 3 / 59 ms |
| thread bubbles in the DOM (hot thread, 5,000 messages) | 0 (no data-message-id then) | 16 (virtualised) | 16 |
| DOM nodes after hot-thread scroll | 47025 | 812 | 812 |
| long tasks during hot-thread scroll (count / max) | 177 / 687 ms | 5 / 69 ms | 5 / 73 ms |

Note: the second cold switch (about 1.1 s in both after runs) is clicked while the first switch's follow-up requests
(heartbeat, `/queue/me`, `/notifications`, fonts) are still queued on the single-process server. Probed alone after
a 1.5 s pause, the same chat opens in 132 ms (thread request 117 ms). It is the test server, not the client.

## Board (Task 7 trace, `tools/perf/board-trace.mjs`, 60 s, 1440x900, demo step every 10 s)

From `board-baseline.json` (3 comparable runs, build before Task 7) and `board-after.json` (4 runs, Task 7 build);
not re-run.

| metric (mean of runs) | baseline | after |
|---|---|---|
| scripting (ms per s) | 6.53 | 1.83 |
| frames over 16.7 ms (per 60 s) | 28.67 | 17 |
| frames over 33 ms (per 60 s) | 5.33 | 2.75 |
| long tasks | 0 | 0 |
| DOM nodes | 863 | 910 |
| smallest rendered font (px) | 7.18 | 11.62 |
| stage scale | 0.9 | 0.99 |
