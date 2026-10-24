<?php

use App\Inbox\Outcomes\Outcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «خلصت» restructured (docs/round-2026-10-24/finish-api.md §5): the outcome says what happened
 * in the chat, and the close records what the sheet suggested and which case it is about.
 *
 * - `suggested_outcome`: the outcome the sheet showed preselected (null: nothing suggested);
 *   `suggestion_followed`: she confirmed it (true) or changed it (false; null without a suggestion).
 * - `support_case_id`: the case this outcome is about (opened by the close, or linked).
 * - `outcome_v1`: the pre-restructure key of rows that existed before this migration, so old
 *   values stay readable. The old `service` becomes the case outcome of the case opened in its
 *   episode, else `order_inquiry`; every other key is unchanged (finish-api.md §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_outcomes', function (Blueprint $t) {
            if (! Schema::hasColumn('conversation_outcomes', 'suggested_outcome')) {
                $t->string('suggested_outcome', 16)->nullable()->after('source');
                $t->boolean('suggestion_followed')->nullable()->after('suggested_outcome');
                $t->foreignId('support_case_id')->nullable()->after('order_id')->constrained('support_cases')->nullOnDelete();
                $t->string('outcome_v1', 16)->nullable()->after('outcome');
            }
        });

        DB::table('conversation_outcomes')->whereNull('outcome_v1')->update(['outcome_v1' => DB::raw('outcome')]);

        $rows = DB::table('conversation_outcomes')->where('outcome', Outcome::Service->value)
            ->get(['id', 'conversation_id', 'started_at', 'set_at', 'ended_at']);

        foreach ($rows as $row) {
            $from = $row->started_at ?? $row->set_at;
            $case = DB::table('support_cases')->where('conversation_id', $row->conversation_id)
                ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($row->ended_at !== null, fn ($q) => $q->where('created_at', '<=', $row->ended_at))
                ->orderByDesc('id')->first(['id', 'type']);
            $outcome = ($case !== null ? Outcome::fromCaseType((string) $case->type) : null) ?? Outcome::OrderInquiry;

            DB::table('conversation_outcomes')->where('id', $row->id)
                ->update(['outcome' => $outcome->value, 'support_case_id' => $case?->id]);
        }
    }

    public function down(): void
    {
        DB::table('conversation_outcomes')->whereNotNull('outcome_v1')->update(['outcome' => DB::raw('outcome_v1')]);

        Schema::table('conversation_outcomes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('support_case_id');
            $t->dropColumn(['suggested_outcome', 'suggestion_followed', 'outcome_v1']);
        });
    }
};
