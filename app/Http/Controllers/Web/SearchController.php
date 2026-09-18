<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Search\GlobalSearch;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Global search (spec §5.3, Dashboard Experience Task 13): command-palette
 * data source for the web app, scoped to the session user. Identical to
 * Api\V1\SearchController other than the guard, kept mobile-additive.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['bail', 'required', 'string', 'max:100', $this->minLength()],
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(GlobalSearch::TYPES)],
        ]);

        return response()->json($search->search($request->user(), $data['q'], $data['types'] ?? GlobalSearch::TYPES));
    }

    /**
     * Minimum query length is 2 characters, except a single purely-numeric
     * digit (a 1-character CRM order id), which `GlobalSearch::search()`
     * then only ever uses for an exact, indexed `orders.id` lookup — never a
     * wildcard scan in any group (fix round 1, ruling 1). `'bail'` on the
     * field stops `string` failing then this closure still running against
     * a non-string value (e.g. `q[]=`); `is_string()` here guards the same
     * case defensively even if that ordering ever changes.
     */
    private function minLength(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                $fail('validation.string')->translate(['attribute' => $attribute]);

                return;
            }

            $length = mb_strlen(trim($value));
            $isSingleDigit = $length === 1 && ctype_digit($value);

            if ($length < 2 && ! $isSingleDigit) {
                $fail('validation.min.string')->translate(['attribute' => $attribute, 'min' => 2]);
            }
        };
    }
}
