<?php

namespace App\Http\Controllers\Concerns;

use App\Analytics\MetricsService;
use App\Analytics\PresenceTracker;
use App\Enums\Platform;
use App\Http\Resources\UserResource;
use App\Http\Support\DateRange;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Report payloads (MetricsService arrays + the resolved range) shared by the web
 * report pages and API v1.
 */
trait ReportEndpoints
{
    protected function teamReport(Request $request, MetricsService $metrics, PresenceTracker $presence): array
    {
        $range = DateRange::fromRequest($request);
        $platform = $this->reportPlatform($request);

        return [
            'range' => $range->toArray(),
            'platform' => $platform?->value,
            'metrics' => $metrics->teamMetrics($range->from, $range->to, $platform),
            'leaderboard' => $metrics->leaderboard($range->from, $range->to),
            'heatmap' => $metrics->hourlyHeatmap(null, $range->from, $range->to),
            'online_user_ids' => $presence->onlineUserIds(),
        ];
    }

    protected function userReport(Request $request, MetricsService $metrics, User $user): array
    {
        $range = DateRange::fromRequest($request);
        $platform = $this->reportPlatform($request);

        return [
            'range' => $range->toArray(),
            'platform' => $platform?->value,
            'user' => (new UserResource($user))->resolve($request),
            'metrics' => $metrics->userMetrics($user, $range->from, $range->to, $platform),
            'heatmap' => $metrics->hourlyHeatmap($user, $range->from, $range->to),
        ];
    }

    protected function botReport(Request $request, MetricsService $metrics): array
    {
        $range = DateRange::fromRequest($request);
        $platform = $this->reportPlatform($request);

        return [
            'range' => $range->toArray(),
            'platform' => $platform?->value,
            'metrics' => $metrics->botMetrics($range->from, $range->to, $platform),
        ];
    }

    protected function reportPlatform(Request $request): ?Platform
    {
        $request->validate(['platform' => ['nullable', Rule::enum(Platform::class)]]);

        return Platform::tryFrom((string) $request->input('platform'));
    }
}
