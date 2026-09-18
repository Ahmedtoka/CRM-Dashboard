<?php

namespace App\Analytics;

use App\Enums\Platform;
use App\Http\Support\DateRange;
use App\Models\QuickReply;
use App\Models\QuickReplyUsage;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Saved replies usage report (Dashboard Experience spec §2.3, §6). All aggregation
 * happens in SQL — no per-row queries — so a supervisor can run this over any range
 * without scanning usages row by row.
 */
final class QuickReplyReport
{
    /**
     * @return list<array{id:int, shortcut:string, title:string, scope:string, category:?string, uses:int, users:int, platforms:array<string,int>, last_used_at:?string}>
     */
    public function top(DateRange $range, ?Platform $platform, int $limit = 20): array
    {
        $base = fn () => QuickReplyUsage::query()->whereBetween('used_at', [$range->from, $range->to])
            ->when($platform, fn ($q) => $q->where('platform', $platform->value));

        $totals = $base()->selectRaw('quick_reply_id, count(*) as uses, count(distinct user_id) as users')
            ->groupBy('quick_reply_id')->orderByDesc('uses')->orderBy('quick_reply_id')->limit($limit)->get();
        $ids = $totals->pluck('quick_reply_id')->all();

        $platforms = $base()->whereIn('quick_reply_id', $ids)->selectRaw('quick_reply_id, platform, count(*) as uses')
            ->groupBy('quick_reply_id', 'platform')->get()->groupBy('quick_reply_id');
        $replies = QuickReply::with('category:id,name')->whereIn('id', $ids)->get()->keyBy('id');

        return $totals->filter(fn ($row) => $replies->has($row->quick_reply_id))->map(function ($row) use ($platforms, $replies) {
            $reply = $replies[$row->quick_reply_id];
            $byPlatform = collect($platforms->get($row->quick_reply_id, []))
                ->mapWithKeys(fn ($p) => [($p->platform instanceof Platform ? $p->platform->value : (string) $p->platform) => (int) $p->uses])
                ->sortKeys()->all();

            return [
                'id' => $reply->id, 'shortcut' => $reply->shortcut, 'title' => $reply->title, 'scope' => $reply->scope->value,
                'category' => $reply->category?->name, 'uses' => (int) $row->uses, 'users' => (int) $row->users,
                'platforms' => $byPlatform, 'last_used_at' => $reply->last_used_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return list<array{user:array{id:int,name:string,color:?string}, uses:int, replies:int}>
     */
    public function perAgent(DateRange $range, ?Platform $platform): array
    {
        $rows = QuickReplyUsage::query()->whereBetween('used_at', [$range->from, $range->to])->whereNotNull('user_id')
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->selectRaw('user_id, count(*) as uses, count(distinct quick_reply_id) as replies')
            ->groupBy('user_id')->orderByDesc('uses')->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'color'])->keyBy('id');

        return $rows->filter(fn ($r) => $users->has($r->user_id))->map(fn ($r) => [
            'user' => ['id' => $users[$r->user_id]->id, 'name' => $users[$r->user_id]->name, 'color' => $users[$r->user_id]->color],
            'uses' => (int) $r->uses, 'replies' => (int) $r->replies,
        ])->values()->all();
    }

    /**
     * @return list<array{id:int, shortcut:string, title:string, scope:string, last_used_at:?string}>
     */
    public function unused(int $days = 30): array
    {
        return QuickReply::query()
            ->whereDoesntHave('usages', fn ($q) => $q->where('used_at', '>=', now()->subDays($days)))
            ->orderBy('shortcut')->orderBy('id')->get()
            ->map(fn (QuickReply $r) => ['id' => $r->id, 'shortcut' => $r->shortcut, 'title' => $r->title, 'scope' => $r->scope->value, 'last_used_at' => $r->last_used_at?->toIso8601String()])
            ->all();
    }

    /**
     * @return \Generator<int, list<string>>
     */
    public function csvRows(DateRange $range, ?Platform $platform): \Generator
    {
        yield ['shortcut', 'title', 'scope', 'category', 'uses', 'users', 'platforms', 'last_used_at'];
        $tz = (string) config('crm.timezone_display', 'Africa/Cairo');
        foreach ($this->top($range, $platform, 1000) as $row) {
            yield [
                $this->guardCsvCell($row['shortcut']),
                $this->guardCsvCell($row['title']),
                $this->guardCsvCell($row['scope']),
                $this->guardCsvCell((string) $row['category']),
                (string) $row['uses'],
                (string) $row['users'],
                $this->guardCsvCell(collect($row['platforms'])->map(fn ($n, $p) => "{$p}:{$n}")->implode('|')),
                $row['last_used_at'] ? CarbonImmutable::parse($row['last_used_at'])->setTimezone($tz)->format('Y-m-d H:i') : '',
            ];
        }
    }

    /**
     * CSV injection guard: a cell that a spreadsheet would interpret as a formula
     * (leading =, +, -, or @) is prefixed with a leading apostrophe.
     */
    private function guardCsvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
