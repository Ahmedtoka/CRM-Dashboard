# Inbox bench — final

mysql `crm_perf_load` · 300010 conversations · 3005000 messages · 2026-10-02T12:57:37+00:00

| scenario | status | p50 ms | p95 ms | max ms | queries avg | queries max |
|---|---|---|---|---|---|---|
| list.default | 200 | 16.8 | 20.2 | 21.6 | 6 | 6 |
| list.status_open | 200 | 20.4 | 32.7 | 39.3 | 7 | 7 |
| list.waiting | 200 | 19.4 | 28.3 | 28.5 | 7 | 7 |
| list.queue_all | 200 | 22.4 | 38.2 | 41.3 | 7 | 7 |
| list.platform | 200 | 15.9 | 21.6 | 22.4 | 6.1 | 7 |
| list.mine | 200 | 10.2 | 15.2 | 15.8 | 3 | 3 |
| list.search_name | 200 | 187.7 | 229.2 | 248.6 | 6 | 6 |
| list.search_phone | 200 | 64 | 87.8 | 87.8 | 6 | 6 |
| list.search_substring | 200 | 355 | 472.9 | 476.3 | 8 | 8 |
| list.state_bot | 200 | 18.3 | 30.4 | 37.2 | 6 | 6 |
| list.state_with_moderator | 200 | 16 | 19.7 | 21.2 | 7.1 | 8 |
| list.queue_waiting | 200 | 6.8 | 8.8 | 9.1 | 3 | 3 |
| list.tag | 200 | 119.4 | 236.8 | 385.6 | 4.1 | 5 |
| list.assignee | 200 | 30.7 | 90.5 | 97.6 | 7 | 7 |
| detail.hot | 200 | 54.3 | 67.8 | 80 | 13 | 13 |
| detail.typical | 200 | 33.4 | 45.2 | 50 | 11 | 11 |
| messages.older | 200 | 34 | 66.1 | 75.5 | 5.1 | 6 |
| messages.after | 200 | 25.5 | 34.2 | 34.2 | 5 | 5 |
