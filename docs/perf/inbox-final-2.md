# Inbox bench — final (repeat)

mysql `crm_perf_load` · 300010 conversations · 3005000 messages · 2026-10-02T13:01:43+00:00

| scenario | status | p50 ms | p95 ms | max ms | queries avg | queries max |
|---|---|---|---|---|---|---|
| list.default | 200 | 15.8 | 20.3 | 20.9 | 6 | 6 |
| list.status_open | 200 | 17.9 | 22.9 | 31.9 | 7.1 | 8 |
| list.waiting | 200 | 16.2 | 20.8 | 25.4 | 7 | 7 |
| list.queue_all | 200 | 16.2 | 22.4 | 27.2 | 7.1 | 8 |
| list.platform | 200 | 17.4 | 26.7 | 30.1 | 6 | 6 |
| list.mine | 200 | 7.8 | 14.5 | 17.9 | 3 | 3 |
| list.search_name | 200 | 205.6 | 230.5 | 231.1 | 6 | 6 |
| list.search_phone | 200 | 68.8 | 92 | 94.4 | 6.1 | 7 |
| list.search_substring | 200 | 328.6 | 364.7 | 370.6 | 8.1 | 9 |
| list.state_bot | 200 | 16.5 | 24.9 | 26.6 | 6.1 | 7 |
| list.state_with_moderator | 200 | 19.2 | 24.6 | 24.8 | 7.1 | 8 |
| list.queue_waiting | 200 | 8.8 | 11.6 | 12 | 3 | 3 |
| list.tag | 200 | 112.8 | 129.2 | 134.7 | 4.1 | 5 |
| list.assignee | 200 | 23.9 | 28 | 28.7 | 7.1 | 8 |
| detail.hot | 200 | 32 | 37.8 | 38.3 | 13 | 13 |
| detail.typical | 200 | 11 | 14.9 | 15.6 | 11.2 | 12 |
| messages.older | 200 | 9.4 | 12.3 | 14.6 | 5 | 5 |
| messages.after | 200 | 4.5 | 5.3 | 5.7 | 5 | 5 |
