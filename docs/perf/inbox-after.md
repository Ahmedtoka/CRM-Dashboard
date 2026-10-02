# Inbox bench — after

mysql `crm_perf_load` · 300010 conversations · 3005000 messages · 2026-10-02T12:30:38+00:00

| scenario | status | p50 ms | p95 ms | max ms | queries avg | queries max |
|---|---|---|---|---|---|---|
| list.default | 200 | 12.9 | 20.7 | 55.6 | 6 | 6 |
| list.status_open | 200 | 16.2 | 23.4 | 23.9 | 7 | 8 |
| list.waiting | 200 | 30.7 | 35.3 | 36.7 | 7.1 | 8 |
| list.queue_all | 200 | 32.4 | 50.3 | 66.4 | 7.1 | 8 |
| list.platform | 200 | 31.1 | 39.1 | 40.2 | 6 | 7 |
| list.mine | 200 | 17.8 | 27.8 | 28.9 | 3 | 3 |
| list.search_name | 200 | 247.5 | 367 | 506.1 | 6 | 6 |
| list.search_phone | 200 | 72.9 | 115.2 | 127.3 | 6 | 6 |
| list.state_bot | 200 | 16 | 25.4 | 27.5 | 6 | 6 |
| list.state_with_moderator | 200 | 25.8 | 41 | 42.8 | 7 | 8 |
| list.queue_waiting | 200 | 5.9 | 8.1 | 8.8 | 3 | 3 |
| list.tag | 200 | 119.6 | 189.4 | 286.4 | 4 | 4 |
| list.assignee | 200 | 27.2 | 39.2 | 42.9 | 7 | 7 |
| detail.hot | 200 | 19 | 33.8 | 35.1 | 13 | 13 |
| detail.typical | 200 | 8.8 | 14.9 | 15.3 | 11.1 | 12 |
| messages.older | 200 | 10 | 16.8 | 18.2 | 5 | 6 |
| messages.after | 200 | 3.5 | 4.6 | 5 | 5 | 5 |
