<?php

namespace App\Http\Controllers\Web\Settings;

use App\Enums\Platform;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\QuickReply;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class QuickReplyController extends Controller
{
    use RespondsWithData;

    public function index(): Response
    {
        return Inertia::render('settings/QuickReplies', [
            'quickReplies' => QuickReply::with('creator:id,name')->orderBy('shortcut')->get(),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $reply = QuickReply::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return $this->done($request, $reply, 201);
    }

    public function update(Request $request, QuickReply $quickReply): HttpResponse
    {
        $quickReply->update($this->validated($request));

        return $this->done($request, $quickReply);
    }

    public function destroy(Request $request, QuickReply $quickReply): HttpResponse
    {
        $quickReply->delete();

        return $this->done($request, ['id' => $quickReply->id]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'shortcut' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => [Rule::enum(Platform::class)],
        ]);
    }
}
