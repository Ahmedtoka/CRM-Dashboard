<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\QuickReplyCategory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Saved-reply categories (spec §2.3) — supervisor+ only (`role:supervisor`
 * route middleware); a deleted category's replies keep `category_id = null`.
 */
class QuickReplyCategoryController extends Controller
{
    use RespondsWithData;

    public function store(Request $request): HttpResponse
    {
        $category = QuickReplyCategory::create($this->validated($request));

        return $this->done($request, $category, 201);
    }

    public function update(Request $request, QuickReplyCategory $category): HttpResponse
    {
        $category->update($this->validated($request));

        return $this->done($request, $category);
    }

    public function destroy(Request $request, QuickReplyCategory $category): HttpResponse
    {
        $category->delete();

        return $this->done($request, ['id' => $category->id]);
    }

    /**
     * @return array{name: string, sort: int}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        return ['name' => $data['name'], 'sort' => $data['sort'] ?? 100];
    }
}
