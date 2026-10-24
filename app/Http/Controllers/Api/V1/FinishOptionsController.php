<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Inbox\Outcomes\Outcome;
use App\Models\SupportCase;
use App\Queue\ConversationCycle;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/finish/options` (mobile r2, docs/round-2026-10-24/finish-api.md §1): what the
 * «خلصت» sheet offers, read from the server so the app never hard-codes the outcome list. The keys
 * are `Outcome::agentChoices()` (whatever the deployed enum allows in `finish`), the Arabic labels
 * `Outcome::label()`, the group is the sheet's section (sale / service / other). Additive-only:
 * an older app ignores it, an older server answers 404 and the app falls back to its built-in list.
 */
class FinishOptionsController extends Controller
{
    /** The sheet's three sections, by outcome key (keys the enum does not have yet are simply absent). */
    public const GROUPS = [
        'ordered' => 'sale', 'price' => 'sale', 'size_out' => 'sale', 'shipping' => 'sale', 'browsing' => 'sale',
        'order_inquiry' => 'service', 'return_case' => 'service', 'exchange_case' => 'service', 'complaint_case' => 'service',
        'order_change' => 'service', 'service' => 'service',
        'no_answer' => 'other', 'spam' => 'other', 'other' => 'other',
    ];

    public const GROUP_ORDER = ['sale', 'service', 'other'];

    /** Outcomes whose default close sends no closing message (finish-api.md §3.2). */
    public const SILENT = ['no_answer', 'spam'];

    public function __invoke(): JsonResponse
    {
        $outcomes = collect(Outcome::agentChoices())
            ->map(fn (Outcome $o) => [
                'key' => $o->value,
                'label' => $o->label(),
                'group' => self::GROUPS[$o->value] ?? 'other',
                'lost_sale' => $o->isLostSale(),
                'note_required' => $o === Outcome::Other,
                'silent' => in_array($o->value, self::SILENT, true),
            ])
            ->sortBy(fn (array $row, int $i) => array_search($row['group'], self::GROUP_ORDER, true) * 1000 + $i)
            ->values()
            ->all();

        return response()->json(['data' => [
            'outcomes' => $outcomes,
            'groups' => collect(self::GROUP_ORDER)->map(fn (string $g) => ['key' => $g, 'label' => self::groupLabel($g)])->values()->all(),
            'after' => [
                ['key' => 'thanks', 'label' => 'شكر + تقييم'],
                ['key' => 'bot', 'label' => 'البوت يكمّل معاها'],
            ],
            'case_types' => collect(SupportCase::TYPE_LABELS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'note_max' => 120,
            'undo_seconds' => ConversationCycle::UNDO_SECONDS,
        ]]);
    }

    private static function groupLabel(string $group): string
    {
        return match ($group) {
            'sale' => 'بيع',
            'service' => 'خدمة',
            default => 'تانية',
        };
    }
}
