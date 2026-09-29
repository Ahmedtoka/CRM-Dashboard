# Handover queue: the 30-second tick

`php artisan queue:tick` is the heartbeat of the customer-service queue. It is registered in
`App\Queue\QueueServiceProvider` to run every 30 seconds and does, in order:

1. opens / closes shifts on time;
2. members: breaks, offline moderators, hand-off of their windows;
3. customer silence: warning message, then auto-close;
4. confirm sweep: closes whose confirm window passed (safety net for a lost `ConfirmClose` job);
5. countdown («باقي 5 / 3 / 1 دقايق») and apology messages to waiting customers;
6. the router;
7. once an hour, deletes decision lines older than 7 days.

A failing step is reported to the log and the next steps still run. With the queue switched off
in the settings the command does nothing.

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
