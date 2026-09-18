<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Support\StarterExamples;
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
            // Names the empty state's one-click starter set would add.
            'starterExamples' => array_keys(StarterExamples::TAGS),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $tag = Tag::create($this->validated($request));

        return $this->done($request, $tag, 201);
    }

    /** Empty-state "add ready-made examples" (supervisor+, idempotent). */
    public function examples(Request $request, StarterExamples $examples): HttpResponse
    {
        $created = $examples->addTags();

        return $this->done($request, ['created' => $created], $created > 0 ? 201 : 200);
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
