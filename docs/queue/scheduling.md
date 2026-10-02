# Handover queue: the 30-second tick

`php artisan queue:tick` is the heartbeat of the customer-service queue. It is registered in
`App\Queue\QueueServiceProvider` to run every 30 seconds and does, in order:

1. opens / closes shifts on time, by the clock, with nobody on them (no roster, no «ابدأ اليوم»);
   the close checks out whoever is still in (`auto_out`);
2. members (checked in with «بدأت شغل»): a moderator whose heartbeat stopped goes offline after 3
   minutes, her windows are handed on after 5 and she is checked out after 10 (`auto_out`); a
   break never ends by itself — the leader hears once when it runs past `break_minutes`
   (`queue.break_overrun`); a pending break or check-out whose windows are all closed is settled;
3. customer silence (after the moderator's reply): warning message, then auto-close;
4. moderator reply (the customer waits for the moderator): apology at `agent_apology_seconds`
   (180), hand-off to a free logged-in colleague as `no_reply` at `agent_reassign_first_seconds`
   (300, first reply) or `agent_reassign_seconds` (480, later message), else one alert to the
   leader per waiting period and another try on every tick. An escalation entry at the leader
   gets the apology but is never handed off: the admins are alerted instead
   (`queue.reply_overdue_leader`). While the assignee is not logged in there is no `no_reply`
   hand-off, no penalty and no leader alert: her windows follow the offline path (step 2, 5
   minutes) instead;
5. confirm sweep: closes whose confirm window passed (safety net for a lost `ConfirmClose` job);
6. the router (only logged-in desks, never the leader for a live customer);
7. countdown («باقي 5 / 3 / 1 دقايق») and apology messages to waiting customers, only while there
   is an estimate (a logged-in desk that may take her);
8. once an hour, deletes decision lines older than 7 days.

A failing step is reported to the log and the next steps still run. With the queue switched off
in the settings the command does nothing.

## Other scheduled and delayed work (UI overhaul, 2026-10-01)

- **Rating question (`App\Queue\Jobs\RequestRating`).** Dispatched by the final close of an
  inquiry / problem window (`WindowLifecycle`), delayed by `review_delay_seconds` (default 60 s),
  on the `outbound` queue like every customer message. It is a delayed job, not a scheduled
  command: it needs the `outbound` worker, not the cron. A lost job means no rating for that close
  (no safety net).
- **`shopify:refresh-orders`** (`ShopifyServiceProvider`): every 10 minutes, `withoutOverlapping`,
  `onOneServer`. It queues at most 60 open Shopify orders per run (`--limit=60`, those not read for
  `--older-than=10` minutes, oldest sync first) in jobs of 25 (`RefreshShopifyOrders`) on the
  `commercelong` queue (`redislong` connection under Redis). It skips when the store is not
  connected or is the demo store. A one-off first fill after the deploy:
  `php artisan shopify:refresh-orders --limit=250`.
- **`media:thumbnails`**: a one-time backfill, not scheduled. Run it once after the deploy that
  adds `message_attachments.thumb_path`; it queues `MakeThumbnail` for stored images / stickers
  without a thumbnail (`--sync` runs inline). Safe to re-run: it only picks rows without one.
- **The cron line** all of the above (and the queue tick) depend on, in the Cloudways cron panel:

  ```
  * * * * * cd /home/master/applications/ryznnsupxm/public_html && php artisan schedule:run >> /dev/null 2>&1
  ```

  (`public_html` is the Laravel root deployed from `backend/`; one line only, see below.)

## Presence and the two clocks (flow revision, 2026-09-29)

- A desk serves only while its moderator is logged in (a heartbeat in the last 2 minutes). The
  board shows a serving desk whose moderator is not logged in in grey, «مش فاتحة».
- The mass-offline safeguard counts only desks that were online since they joined. The old
  "not arrived" state (a rostered moderator who never logged in, and its leader alert) is gone:
  a moderator is on a shift only once she has checked herself in (see Attendance below), so
  there is nobody to wait for.
- Two clocks run on an open window, never together: the customer-silence clock (after the
  moderator's last reply) and the moderator-reply clock (`queue_entries.awaiting_reply_since`,
  while the customer waits for her). A `no_reply` hand-off keeps the ticket, puts the customer
  first in the lounge and never gives her back to the same moderator (`excluded_user_id`).
- Until Part 2's points ledger exists, a `no_reply` hand-off is recorded as the close reason plus
  the activity-log line `queue.no_reply` (with `points: -points.no_reply`).

## The waiting customer's position reply (flow revision §3)

- When a customer writes while her entry is `waiting`, she gets her position (`queue_position_update`:
  her ticket, how many are ahead, and the estimate when there is one). At most once per
  `waiting_update_seconds` (120) per entry (`queue_entries.position_update_sent_at`); the enqueue
  message does not count, and messages in between get no reply.
- Overnight entries are excluded: they keep the one night message.
- While the queue holds her (queue on, entry `waiting`, `called` or `active`) the bot's own
  reassurance stays silent, so she never gets two answers. With the queue off nothing changes.

## Attendance: self check-in (2026-09-29)

- Shifts open and close by the clock from Settings → Queue (defaults صباحي 10:00–18:00, مسائي
  18:00–00:00). Nobody starts the day and nobody picks a roster; the leader is the template's.
- A moderator (active, with at least one platform) presses «بدأت شغل» in her inbox strip while a
  shift runs; outside the hours the button is disabled with «الشيفت بيبدأ {time}». Then
  «استراحة» (at once, or after her open windows), «رجعت», «خروج» (at once, or «بتقفل» until her
  last window closes; «رجّعي شبابيكي للصالة» sends her windows back to the top of the lounge
  through the transfer path, no penalty). Logging out of the CRM is «خروج».
- Automatic check-out: the shift's close, and 10 minutes without a heartbeat (her windows were
  already handed on after 5). A break never ends by itself; a moderator on a break is not
  checked out for being offline (the overrun alert and the shift's close cover her).
- The board draws only checked-in desks plus the leader's desk (grey until she checks in). The
  leader or a supervisor can send someone on a break or check her out on her behalf; nobody is
  added from the board.
- Every step is logged in `queue_attendance_events` (`in | break | back | out | auto_out`,
  `by_user_id` when somebody did it for her). The member panel shows today's first in, last out,
  time worked, break time, breaks and overruns (`App\Queue\Attendance::figuresFor()`); nothing is
  paid or penalised from them.
- With nobody checked in during a shift the bot keeps answering; a customer who asks for a human
  gets `queue_enqueued_no_eta` and waits in the lounge; the first moderator who checks in
  receives the lounge in order.

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
