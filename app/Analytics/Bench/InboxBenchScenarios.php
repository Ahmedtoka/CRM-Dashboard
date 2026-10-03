<?php

namespace App\Analytics\Bench;

/**
 * The inbox requests the UI overhaul is measured on (spec §1.3). Scenarios for filters that a later
 * task adds are listed now: before that task they answer 422 and the report shows it.
 */
class InboxBenchScenarios
{
    /**
     * @param  array{hot_id:int, typical_id:int, tag_id:?int, moderator_id:?int, mid_message_id:?int, recent_message_id:?int}  $ctx
     * @return list<array{name:string, uri:string}>
     */
    public static function for(array $ctx): array
    {
        $l = '/inbox/conversations';
        $rows = [
            ['list.default', $l],
            ['list.status_open', "{$l}?status=open"],
            ['list.waiting', "{$l}?filter=waiting"],
            ['list.queue_all', "{$l}?filter=queue_all"],
            ['list.platform', "{$l}?platform=whatsapp"],
            ['list.mine', "{$l}?filter=mine"],
            ['list.search_name', "{$l}?q=".rawurlencode('Customer 1234')],
            ['list.search_phone', "{$l}?q=0100012"],
            // An Arabic substring found only inside words («عبدالله»): exercises the LIKE fallback (Task 4b).
            ['list.search_substring', "{$l}?q=".rawurlencode('الله')],
            // Added by Task 4 (422 before it):
            ['list.state_bot', "{$l}?status=bot"],
            ['list.state_with_moderator', "{$l}?status=with_moderator"],
            ['list.queue_waiting', "{$l}?queue=waiting"],
        ];
        if ($ctx['tag_id']) {
            $rows[] = ['list.tag', "{$l}?tag={$ctx['tag_id']}"];
        }
        if ($ctx['moderator_id']) {
            $rows[] = ['list.assignee', "{$l}?assignee={$ctx['moderator_id']}"];
        }
        $rows[] = ['detail.hot', "{$l}/{$ctx['hot_id']}"];
        $rows[] = ['detail.typical', "{$l}/{$ctx['typical_id']}"];
        if ($ctx['mid_message_id']) {
            $rows[] = ['messages.older', "{$l}/{$ctx['hot_id']}/messages?before_id={$ctx['mid_message_id']}"];
        }
        if ($ctx['recent_message_id']) {
            $rows[] = ['messages.after', "{$l}/{$ctx['hot_id']}/messages?after_id={$ctx['recent_message_id']}"];
        }

        return array_map(fn ($r) => ['name' => $r[0], 'uri' => $r[1]], $rows);
    }
}
