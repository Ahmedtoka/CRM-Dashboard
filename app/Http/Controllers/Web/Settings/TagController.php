<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TagController extends Controller
{
    use RespondsWithData;

    public function index(): Response
    {
        return Inertia::render('settings/Tags', [
            'tags' => Tag::withCount('conversations')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $tag = Tag::create($this->validated($request));

        return $this->done($request, $tag, 201);
    }

    public function update(Request $request, Tag $tag): HttpResponse
    {
        $tag->update($this->validated($request, $tag));

        return $this->done($request, $tag);
    }

    public function destroy(Request $request, Tag $tag): HttpResponse
    {
        $tag->delete();

        return $this->done($request, ['id' => $tag->id]);
    }

    private function validated(Request $request, ?Tag $tag = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('tags', 'name')->ignore($tag?->id)],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
