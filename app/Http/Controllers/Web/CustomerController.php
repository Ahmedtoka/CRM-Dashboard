<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\CustomerResource;
use App\Http\Support\ModeratorScope;
use App\Inbox\ConversationQuery;
use App\Inbox\CustomerMerger;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $term = trim((string) ($filters['q'] ?? ''));

        $customers = Customer::query()
            ->with(['identities' => fn ($q) => ModeratorScope::identities($q, $user)])
            ->tap(fn (Builder $q) => ModeratorScope::customers($q, $user))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderByDesc('last_contact_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => CustomerResource::collection($customers),
            'filters' => ['q' => $filters['q'] ?? null],
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        $customer->load(ModeratorScope::customerRelations($request->user()));

        $conversations = ConversationQuery::withListColumns(ConversationQuery::visibleTo($request->user()))
            ->where('conversations.customer_id', $customer->id)
            ->orderByDesc('conversations.last_message_at')
            ->limit(20)
            ->get();

        return Inertia::render('Customers/Show', [
            'customer' => (new CustomerResource($customer))->resolve($request),
            'conversations' => ConversationResource::collection($conversations)->resolve($request),
            'canMerge' => $request->user()->isSupervisorOrAbove(),
        ]);
    }

    /**
     * Other customers whose phone normalizes to the same number.
     */
    public function mergeSuggestions(Request $request, Customer $customer): AnonymousResourceCollection
    {
        Gate::authorize('view', $customer);

        $normalized = self::normalizePhone($customer->phone);

        if ($normalized === null) {
            return CustomerResource::collection(collect());
        }

        // Stored phones keep their original formatting ("+20 100 123 4567"), so a SQL LIKE
        // cannot match reliably; compare normalized values over a lazy id-ordered scan.
        $user = $request->user();

        $ids = Customer::query()
            ->tap(fn (Builder $q) => ModeratorScope::customers($q, $user))
            ->whereKeyNot($customer->id)
            ->whereNotNull('phone')
            ->select(['id', 'phone'])
            ->lazyById(500)
            ->filter(fn (Customer $c) => self::normalizePhone($c->phone) === $normalized)
            ->take(50)
            ->pluck('id')
            ->all();

        return CustomerResource::collection(
            Customer::with(['identities' => fn ($q) => ModeratorScope::identities($q, $user)])->whereKey($ids)->orderBy('id')->get()
        );
    }

    /**
     * Moves identities, conversations, orders and comments of `other_id` into this customer,
     * then deletes the other customer.
     */
    public function merge(Request $request, Customer $customer, CustomerMerger $merger): CustomerResource
    {
        Gate::authorize('merge', $customer);

        $data = $request->validate(['other_id' => ['required', 'integer', 'exists:customers,id']]);

        if ((int) $data['other_id'] === (int) $customer->id) {
            throw ValidationException::withMessages(['other_id' => __('errors.customers.cannot_merge_into_itself')]);
        }

        $merger->merge($customer, Customer::findOrFail($data['other_id']), $request->user());

        return new CustomerResource($customer->fresh(['identities']));
    }

    /**
     * Digits only (Arabic-Indic digits converted), Egyptian +20 / 0020 prefix folded to a leading 0.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', strtr($phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '20') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return strlen($digits) >= 8 ? $digits : null;
    }
}
