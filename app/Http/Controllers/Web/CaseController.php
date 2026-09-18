<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportCaseResource;
use App\Http\Support\DateRange;
use App\Http\Support\ModeratorScope;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support cases dashboard (spec §4): a case list with filters/status tabs and a
 * status/assignment PATCH. Moderators only see cases on the platforms they can
 * access, same as the inbox and the orders list.
 */
class CaseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $cases = SupportCaseResource::collection(
            $this->query($request, $filters)->with(['customer', 'assignedTo', 'order.items.variant'])->paginate(25)->withQueryString()
        );

        return Inertia::render('Cases', [
            'cases' => $cases,
            'filters' => array_merge(['type' => null, 'status' => null, 'q' => null, 'from' => null, 'to' => null], $filters),
            'counts' => $this->counts($request, $filters),
            // Options for the drawer's "assigned to" select.
            'team' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * `SupportCaseResource` has its own `data` field (the flow payload), which defeats
     * Laravel's automatic `{"data": ...}` envelope (it only wraps when the resolved
     * array has no `data` key of its own) — so single-case responses wrap explicitly
     * here to still match every other endpoint's `{"data": {...}}` shape.
     */
    public function show(Request $request, SupportCase $supportCase): JsonResponse
    {
        Gate::authorize('view', $supportCase);

        return response()->json(['data' => (new SupportCaseResource($supportCase->loadMissing(['customer', 'assignedTo', 'order.items.variant'])))->resolve($request)]);
    }

    public function update(Request $request, SupportCase $supportCase): JsonResponse
    {
        Gate::authorize('update', $supportCase);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportCase::STATUSES)],
            'assigned_to_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        if (array_key_exists('status', $data)) {
            $supportCase->status = $data['status'];

            if ($data['status'] === 'closed') {
                $supportCase->closed_at = now();
                $supportCase->closed_by_id = $request->user()->id;
            } else {
                $supportCase->closed_at = null;
                $supportCase->closed_by_id = null;
            }
        }

        if (array_key_exists('assigned_to_id', $data)) {
            $supportCase->assigned_to_id = $data['assigned_to_id'];
        }

        $supportCase->save();

        return response()->json(['data' => (new SupportCaseResource($supportCase->fresh(['customer', 'assignedTo', 'order.items.variant'])))->resolve($request)]);
    }

    /**
     * @return array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string}
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'type' => ['nullable', Rule::in(SupportCase::TYPES)],
            'status' => ['nullable', Rule::in(SupportCase::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    /**
     * @param  array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Builder<SupportCase>
     */
    private function query(Request $request, array $filters, bool $withStatus = true): Builder
    {
        return SupportCase::query()
            ->tap(fn (Builder $q) => ModeratorScope::cases($q, $request->user()))
            ->when($withStatus, fn ($q) => $q->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v)))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', DateRange::startOfCairoDay($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', DateRange::endOfCairoDay($v)))
            ->when(trim((string) ($filters['q'] ?? '')), fn ($q, $term) => $q->where(fn (Builder $w) => $w
                ->where('order_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->orderByDesc('id');
    }

    /**
     * Status tab counts (spec: "status tabs with counts") — computed with every filter
     * except `status` itself, so switching tabs never changes the other counts.
     *
     * @param  array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array<string, int>
     */
    private function counts(Request $request, array $filters): array
    {
        $rows = $this->query($request, $filters, withStatus: false)
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
