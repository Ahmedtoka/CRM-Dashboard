<?php

namespace App\Queue;

/**
 * The numbers the queue shows the customer, worded in Egyptian Arabic with the right agreement
 * (final review of the flow revision): «وقدامك 0» or «حوالي 1 دقايق» never reach her. Every
 * `queue_*` script placeholder that carries a count — `{ahead}`, `{eta_minutes}`, `{minutes}` —
 * is filled from here, so the scripts read the phrase as a whole («… #{ticket}، و{ahead}، …»).
 */
final class QueueWording
{
    /**
     * Who is ahead of her in the lounge: «إنتي أول واحدة في الدور» / «قدامك عميلة واحدة» /
     * «قدامك عميلتين» / «قدامك N عملاء» (3–10) / «قدامك N عميلة» (11 and up).
     */
    public static function ahead(int $count): string
    {
        $n = max(0, $count);

        return match (true) {
            $n === 0 => 'إنتي أول واحدة في الدور',
            $n === 1 => 'قدامك عميلة واحدة',
            $n === 2 => 'قدامك عميلتين',
            $n <= 10 => 'قدامك '.$n.' عملاء',
            default => 'قدامك '.$n.' عميلة',
        };
    }

    /** Minutes: «دقيقة» / «دقيقتين» / «N دقايق» (3–10) / «N دقيقة» (11 and up); never less than one. */
    public static function minutes(int $count): string
    {
        $n = max(1, $count);

        return match (true) {
            $n === 1 => 'دقيقة',
            $n === 2 => 'دقيقتين',
            $n <= 10 => $n.' دقايق',
            default => $n.' دقيقة',
        };
    }
}
