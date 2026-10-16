<?php

namespace App\Http\Controllers\Web;

use App\Confirmations\CallOutcome;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Ui\ConfirmationBoard;
use App\Http\Controllers\Controller;
use App\Http\Support\DateRange;
use App\Models\OrderConfirmation;
use App\Today\TodayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «تأكيدات الأوردرات» (spec 2026-10-16, W4): one Cairo day of WhatsApp order confirmations — counters, tabs
 * and the staff call list. Who sees a row follows the order (OrderPolicy::view).
 */
class OrderConfirmationController extends Controller
{
    public function index(Request $request, ConfirmationBoard $board, ConfirmationSettings $settings): Response
    {
        $today = CarbonImmutable::now(TodayWindow::TZ)->toDateString();
        $date = $this->validDate($request->query('date')) ?? $today;
        $tab = array_key_exists((string) $request->query('tab'), ConfirmationBoard::TABS) ? (string) $request->query('tab') : 'all';

        $visible = $board->visibleTo($request->user());
        $day = $board->between(clone $visible, DateRange::startOfCairoDay($date), DateRange::endOfCairoDay($date));
        $allDays = in_array($tab, ConfirmationBoard::ALL_DAYS_TABS, true);

        $counts = $board->counts($day);
        foreach (ConfirmationBoard::ALL_DAYS_TABS as $key) {
            $counts['tabs'][$key] = $board->inTab(clone $visible, $key)->count();
        }

        /** @var LengthAwarePaginator $page */
        $page = $board->inTab(clone ($allDays ? $visible : $day), $tab)
            ->with(['order.customer:id,name', 'caller:id,name'])
            ->orderByDesc('order_confirmations.id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Orders/Confirmations', [
            'date' => $date,
            'today' => $today,
            'tab' => $tab,
            'counts' => $counts,
            'allDays' => $allDays,
            'rows' => [
                'data' => collect($page->items())->map(fn (OrderConfirmation $c) => $board->row($c))->all(),
                'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
                'meta' => [
                    'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'from' => $page->firstItem(),
                    'to' => $page->lastItem(), 'total' => $page->total(), 'per_page' => $page->perPage(),
                ],
            ],
            'enabled' => $settings->enabled(),
            'canEditSettings' => $request->user()->isAdmin(),
        ]);
    }

    /**
     * Staff record the customer's answer: how the call went, or what she said in her own words
     * (ConfirmationBoard::RECORDABLE statuses only).
     */
    public function call(Request $request, OrderConfirmation $confirmation, ConfirmationBoard $board): JsonResponse
    {
        Gate::authorize('recordCall', $confirmation);

        $data = $request->validate(['outcome' => ['required', 'string', Rule::in(OrderConfirmation::CALL_OUTCOMES)]]);

        if (! ConfirmationBoard::canRecord($confirmation)) {
            return response()->json(['message' => __('errors.order_confirmations.not_on_call_list')], 422);
        }

        $done = app(CallOutcome::class)->record($confirmation, $data['outcome'], $request->user());

        return response()->json(['data' => $board->row($done->fresh(['order.customer', 'caller']) ?? $done)]);
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, TodayWindow::TZ);
        } catch (\Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable && $parsed->toDateString() === $value ? $value : null;
    }
}
