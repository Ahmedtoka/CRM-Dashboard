<?php

namespace App\Today;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/** Spec §8: the Today pages are cached 60 s per user scope. Each block carries the time it was built. */
final class TodayCache
{
    public const TTL = 60;

    /** @return array{generated_at: string, data: mixed} */
    public function remember(User $u, TodayWindow $w, string $part, Closure $build): array
    {
        return Cache::remember("today:{$part}:{$u->id}:{$w->mode}:{$w->date}", self::TTL,
            fn () => ['generated_at' => now()->toIso8601String(), 'data' => $build()]);
    }
}
