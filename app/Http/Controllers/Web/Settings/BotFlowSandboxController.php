<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Http\Controllers\Controller;
use App\Models\BotFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The flow designer's sandbox chat (design doc 2026-09-17-flow-designer §3,
 * Task 3): one simulated customer turn against a flow's draft or published
 * definition. Nothing is sent or saved — see App\Bot\Flows\Sandbox\FlowSandbox.
 */
class BotFlowSandboxController extends Controller
{
    public function __invoke(Request $request, BotFlow $flow, FlowSandbox $sandbox): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(FlowSandbox::SOURCES)],
            'state' => ['nullable', 'array'],
            'input' => ['present', 'array'],
            'input.text' => ['nullable', 'string', 'max:1000'],
            'input.payload' => ['nullable', 'string', 'max:191'],
            'input.photo' => ['sometimes', 'boolean'],
            // Bilingual bot (design 2026-09-21 §1): preview the flow as an English customer.
            'input.language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        try {
            $result = $sandbox->run(
                $flow,
                $data['source'],
                $data['state'] ?? null,
                [
                    'text' => $data['input']['text'] ?? null,
                    'payload' => $data['input']['payload'] ?? null,
                    'photo' => (bool) ($data['input']['photo'] ?? false),
                    'language' => $data['input']['language'] ?? null,
                ],
                $request->user(),
            );
        } catch (Throwable $e) {
            // A broken state or draft must not surface as a 500 in the designer (spec §3).
            report($e);

            return response()->json(['message' => __('errors.flows.sandbox_failed')], 422);
        }

        return response()->json($result);
    }
}
