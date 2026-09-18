<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The branch directory (Task 2) that App\Bot\Flows\BranchFinder lists to a
 * customer by area. `area_key` should match one of BranchFinder's known
 * keys for the finder to group and match it; this controller does not
 * enforce that so the owner can stage a new area before wiring its aliases.
 */
class BranchController extends Controller
{
    use RespondsWithData;

    public function index(): Response
    {
        return Inertia::render('settings/Branches', [
            'branches' => Branch::query()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $branch = Branch::create($this->validated($request, false));

        return $this->done($request, $branch, 201);
    }

    public function update(Request $request, Branch $branch): HttpResponse
    {
        $branch->update($this->validated($request, true));

        return $this->done($request, $branch->fresh());
    }

    public function destroy(Request $request, Branch $branch): HttpResponse
    {
        $branch->delete();

        return $this->done($request, ['id' => $branch->id]);
    }

    private function validated(Request $request, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'governorate' => [$required, 'string', 'max:60'],
            'area_key' => [$required, 'string', 'max:40'],
            'area_ar' => [$required, 'string', 'max:60'],
            'area_en' => ['nullable', 'string', 'max:60'],
            'name' => [$required, 'string', 'max:120'],
            'address' => [$required, 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'hours' => ['nullable', 'string', 'max:200'],
            'aliases' => ['sometimes', 'array'],
            'aliases.*' => ['string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer'],
        ]);
    }
}
