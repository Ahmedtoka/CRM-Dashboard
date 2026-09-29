# Handover queue: the 30-second tick

`php artisan queue:tick` is the heartbeat of the customer-service queue. It is registered in
`App\Queue\QueueServiceProvider` to run every 30 seconds and does, in order:

1. opens / closes shifts on time;
2. members: breaks; a rostered moderator who never logged in since she joined ("not arrived") is
   marked offline at once; a moderator whose heartbeat stopped goes offline after 3 minutes and
   her windows are handed on after 5; the leader, supervisors and admins hear once per shift when
   somebody is still not logged in `not_arrived_alert_minutes` (10) into it;
3. customer silence (after the moderator's reply): warning message, then auto-close;
4. moderator reply (the customer waits for the moderator): apology at `agent_apology_seconds`
   (180), hand-off to a free logged-in colleague as `no_reply` at `agent_reassign_first_seconds`
   (300, first reply) or `agent_reassign_seconds` (480, later message), else one alert to the
   leader per waiting period and another try on every tick;
5. confirm sweep: closes whose confirm window passed (safety net for a lost `ConfirmClose` job);
6. the router (only logged-in desks, never the leader for a live customer);
7. countdown («باقي 5 / 3 / 1 دقايق») and apology messages to waiting customers, only while there
   is an estimate (a logged-in desk that may take her);
8. once an hour, deletes decision lines older than 7 days.

A failing step is reported to the log and the next steps still run. With the queue switched off
in the settings the command does nothing.

## Presence and the two clocks (flow revision, 2026-09-29)

- A desk serves only while its moderator is logged in (a heartbeat in the last 2 minutes). The
  board shows a serving desk whose moderator is not logged in in grey, «مش فاتحة».
- The mass-offline safeguard counts only desks that were online since they joined; moderators who
  never logged in are "not arrived", never "gone dark together".
- Two clocks run on an open window, never together: the customer-silence clock (after the
  moderator's last reply) and the moderator-reply clock (`queue_entries.awaiting_reply_since`,
  while the customer waits for her). A `no_reply` hand-off keeps the ticket, puts the customer
  first in the lounge and never gives her back to the same moderator (`excluded_user_id`).
- Until Part 2's points ledger exists, a `no_reply` hand-off is recorded as the close reason plus
  the activity-log line `queue.no_reply` (with `points: -points.no_reply`).

## Server with a one-minute cron (Cloudways)

Cron cannot run anything more often than once a minute. Laravel handles the 30 seconds itself:
`schedule:run` starts at second 0, runs the tick, stays alive until the end of the minute and
runs the tick again at second 30. So:

- Keep the single cron line from `deploy/cloudways/cron.txt`
  (Application Settings -> Cron Job Management):

  ```
  * * * * * cd <app>/public_html/backend && php artisan schedule:run >> /dev/null 2>&1
  ```

- Do not add a second cron line for `queue:tick`, and do not wrap `schedule:run` in `timeout`
  or anything else that kills it before the minute ends (the second tick would be lost).
- The cache store must support locks (`CACHE_STORE=redis` on staging / production, `database`
  locally). The schedule's `withoutOverlapping` / `onOneServer` and the tick's own lock live
  in it.
- Workers: the messages the tick decides are sent by the `outbound` worker, the confirm jobs by
  the `bot` worker. Both Supervisor programs must be running.
- Deploys: `deploy.sh` runs `php artisan schedule:interrupt`, which stops the running
  `schedule:run` from repeating with the old code; the next minute's cron starts the new code.

Alternative (not both): instead of the cron line, a Supervisor program that runs
`php artisan schedule:work` permanently. Use an alphanumeric program name (`crmscheduler`).

### How to check it

- `php artisan schedule:list` shows `queue:tick` with "Repeats every 30 seconds".
- `/up/crm` -> `scheduler_last_run` advances every minute.
- With a customer waiting and a moderator free, she is called within about 30 seconds.

### Limits

- Scheduled commands run one after the other inside `schedule:run`. While a long command of the
  same minute is running (the nightly Shopify reconcile at 03:00, the 02:00 learning run), the
  second tick of that minute is late or skipped. The next minute's cron starts a fresh
  `schedule:run`, so the queue never waits more than about a minute.
- If a tick is killed half-way, its locks expire by themselves after 2 minutes.

## Overlapping ticks

Three layers keep a customer from getting a message twice:

1. the schedule's `withoutOverlapping(2)`;
2. the command's own `queue:tick` cache lock: a second tick (for example one run by hand) leaves
   at once;
3. every message is decided inside a transaction holding the row lock of its queue entry, and
   the "already sent" mark is written in the same transaction.

## Local (XAMPP / Windows)

`start-dev.ps1` does not start the scheduler. Open one more window in `backend\`:

```
php artisan schedule:work
```

or run `php artisan queue:tick` by hand when testing.

## Supervisor names

Cloudways accepts only letters and digits in the queue / connection names, so the long Shopify
jobs use the connection `redislong` and the queue `commercelong` (program `crm-commercelong` in
`deploy/cloudways/supervisor/crm-workers.conf`). The old names `redis-long` / `commerce-long`
are no longer used anywhere in the code.
