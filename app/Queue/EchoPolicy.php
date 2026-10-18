<?php

namespace App\Queue;

use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Close cycle Q12 / H-05 (close-cycle-FINAL §8, RC-15): when a message sent from outside the CRM
 * (an echo: Meta Business Suite, the phone app) is a person answering. Only while
 * `crm.queue.echo_as_staff` is on; never an echo from an app in `automated_echo_app_ids`.
 * Instagram echoes carry no app id: they count as a person's once the switch is on.
 */
class EchoPolicy
{
    public static function countsAsStaff(): bool
    {
        return (bool) config('crm.queue.echo_as_staff', false);
    }

    /** This recorded echo is a person's reply. */
    public static function isHuman(Message $m): bool
    {
        if (! self::countsAsStaff() || ! $m->sent_outside_crm || $m->user_id !== null) {
            return false;
        }

        return $m->echo_app_id === null || ! in_array((string) $m->echo_app_id, self::automated(), true);
    }

    /** The same rule as a query condition on `messages`. */
    public static function human(Builder|QueryBuilder $q): void
    {
        $q->where('sent_outside_crm', true)->whereNull('user_id')
            ->where(fn ($q) => $q->whereNull('echo_app_id')->orWhereNotIn('echo_app_id', self::automated() ?: ['']));
    }

    /** @return list<string> */
    private static function automated(): array
    {
        return array_values(array_map('strval', (array) config('crm.queue.automated_echo_app_ids', [])));
    }
}
