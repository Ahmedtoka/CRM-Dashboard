<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\SupportCaseResource;
use App\Http\Support\DateRange;
use App\Http\Support\ModeratorScope;
use App\Http\Support\SortParam;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Cases\CaseResolution;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Support cases (spec §4), shared by the web Cases page (`Web\CaseController`, which renders
 * its list with Inertia) and the mobile app (`Api\V1\CaseController`, JSON list). Moderators
 * only see cases on the platforms they can access, same as the inbox and the orders list.
 */
trait CaseEndpoints
{
    /** What the list, show and update responses load (Queue Part 2 adds the opener and the queue entry). */
    private const CASE_RELATIONS = ['customer', 'assignedTo', 'openedBy:id,name', 'queueEntry:id,assigned_user_id,is_test', 'order.items.variant'];

    /**
     * `SupportCaseResource` has its own `data` field (the flow payload), which defeats
     * Laravel's automatic `{"data": ...}` envelope (it only wraps when the resolved
     * array has no `data` key of its own) — so single-case responses wrap explicitly
     * here to still match every other endpoint's `{"data": {...}}` shape.
     */
    public function show(Request $request, SupportCase $supportCase): JsonResponse
    {
        Gate::authorize('view', $supportCase);

        return response()->json(['data' => (new SupportCaseResource($supportCase->loadMissing(self::CASE_RELATIONS)))->resolve($request)]);
    }

    public function update(Request $request, SupportCase $supportCase): JsonResponse
    {
        Gate::authorize('update', $supportCase);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportCase::STATUSES)],
            // Review 1 M11: only who the drawer offers (active inbox staff) and who can open this case.
            'assigned_to_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($supportCase) {
                $assignee = User::query()->whereKey($value)->where('is_active', true)->inboxStaff()->first();
                $platform = $supportCase->conversation?->platform;

                if ($assignee === null || $platform === null || ! $assignee->canAccessPlatform($platform)) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
        ]);

        // Queue Part 2: `resolved` pays the opener and tells the customer; leaving it takes the points back.
        if (array_key_exists('status', $data)) {
            $supportCase = app(CaseResolution::class)->setStatus($supportCase, $data['status'], $request->user());
        }

        if (array_key_exists('assigned_to_id', $data)) {
            $supportCase->assigned_to_id = $data['assigned_to_id'];
            $supportCase->save();
        }

        return response()->json(['data' => (new SupportCaseResource($supportCase->fresh(self::CASE_RELATIONS)))->resolve($request)]);
    }

    /**
     * The list both clients show: the validated filters, the sort, one page of 25 and the
     * status tab counts.
     *
     * @return array{filters: array<string, mixed>, sort: ?SortParam, cases: LengthAwarePaginator, counts: array<string, int>}
     */
    protected function casesPage(Request $request): array
    {
        $filters = $this->caseFilters($request);

        $query = $this->caseQuery($request, $filters)->with(self::CASE_RELATIONS);
        $sort = SortParam::parse($request->query('sort'), ['date' => 'created_at', 'id' => 'id']);
        $sort?->apply($query);

        return [
            'filters' => $filters,
            'sort' => $sort,
            'cases' => $query->paginate(25)->withQueryString(),
            'counts' => $this->caseCounts($request, $filters),
        ];
    }

    /**
     * @return array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string, overdue?: ?bool}
     */
    private function caseFilters(Request $request): array
    {
        return $request->validate([
            'type' => ['nullable', Rule::in(SupportCase::TYPES)],
            'status' => ['nullable', Rule::in(SupportCase::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'overdue' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string, overdue?: ?bool}  $filters
     * @return Builder<SupportCase>
     */
    private function caseQuery(Request $request, array $filters, bool $withStatus = true): Builder
    {
        return SupportCase::query()
            ->tap(fn (Builder $q) => ModeratorScope::cases($q, $request->user()))
            ->when($withStatus, fn ($q) => $q->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v)))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', DateRange::startOfCairoDay($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', DateRange::endOfCairoDay($v)))
            // «النهارده» urgent strip (control room S4): open cases whose SLA time passed.
            ->when(! empty($filters['overdue']), fn ($q) => $q->whereIn('status', SupportCase::OPEN_STATUSES)->whereNotNull('sla_due_at')->where('sla_due_at', '<', now()))
            ->when(trim((string) ($filters['q'] ?? '')), fn ($q, $term) => $q->where(fn (Builder $w) => $w
                ->where('order_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->orderByDesc('id');
    }

    /**
     * Status tab counts (spec: "status tabs with counts") — computed with every filter
     * except `status` itself, so switching tabs never changes the other counts.
     *
     * @param  array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string, overdue?: ?bool}  $filters
     * @return array<string, int>
     */
    private function caseCounts(Request $request, array $filters): array
    {
        $rows = $this->caseQuery($request, $filters, withStatus: false)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['all' => 0];
        foreach (SupportCase::STATUSES as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
            $counts['all'] += $counts[$status];
        }

        return $counts;
    }
}
