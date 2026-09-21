<?php

namespace App\Http\Controllers\Web\Settings;

use App\Analytics\ActivityLogger;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowDrafts;
use App\Bot\Flows\FlowStepCatalog;
use App\Bot\Flows\FlowValidationException;
use App\Enums\ActorType;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotFlow;
use App\Models\BotFlowVersion;
use App\Models\BotKnowledgeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The flow designer (design doc 2026-09-17-flow-designer §2, Task 2): the
 * flow list, the draft/publish/version-history workflow on one flow and
 * adding a flow to the main menu. Editing a flow's `definition` never
 * touches `bot_flows.definition` directly — everything goes through
 * `App\Bot\Flows\FlowDrafts` (Task 1), which owns drafts, publishing and
 * restoring. The sandbox simulator is a separate controller (Task 3).
 */
class BotFlowController extends Controller
{
    use RespondsWithData;

    private const SCRIPT_PREFIX = 'script.';

    private const MAIN_MENU_KEY = 'main_menu';

    public function index(): Response
    {
        $flows = BotFlow::query()->orderBy('id')->get();

        $mainMenu = $flows->firstWhere('key', self::MAIN_MENU_KEY);
        $mainMenuStepKey = $mainMenu ? FlowDefinition::menuStepKey($mainMenu->definition) : null;
        $mainMenuActions = $mainMenuStepKey !== null
            ? collect($mainMenu->definition['steps'][$mainMenuStepKey]['options'] ?? [])->pluck('action')->all()
            : [];

        return Inertia::render('settings/BotFlows', [
            'flows' => $flows->map(fn (BotFlow $flow) => [
                'id' => $flow->id,
                'key' => $flow->key,
                'title_ar' => $flow->title_ar,
                'is_active' => $flow->is_active,
                'has_draft' => $flow->draft()->exists(),
                'published_version' => $flow->publishedVersion()->value('version'),
                'in_main_menu' => in_array('flow:'.$flow->key, $mainMenuActions, true),
            ])->values(),
            'stepTypes' => FlowStepCatalog::all(),
            'scripts' => BotKnowledgeEntry::query()
                ->where('key', 'like', self::SCRIPT_PREFIX.'%')
                ->orderBy('sort')->orderBy('id')
                ->get(['key', 'title', 'body', 'is_active'])
                ->map(fn (BotKnowledgeEntry $entry) => [
                    'key' => substr($entry->key, strlen(self::SCRIPT_PREFIX)),
                    'title' => $entry->title,
                    // The designer's customer preview shows the body and fades menu buttons to inactive scripts.
                    'body' => (string) $entry->body,
                    'is_active' => (bool) $entry->is_active,
                ])->values(),
            'flowKeys' => $flows->pluck('key')->values(),
        ]);
    }

    public function show(Request $request, BotFlow $flow, FlowDrafts $flowDrafts): HttpResponse
    {
        $draftRow = $flow->draft()->first();
        $definition = $draftRow?->definition ?? $flow->definition;

        $versions = $flow->versions()->orderByDesc('version')->get()->map(fn (BotFlowVersion $v) => [
            'id' => $v->id,
            'version' => $v->version,
            'status' => $v->status,
            'note' => $v->note,
            'created_by' => $v->createdBy?->name,
            'published_at' => $v->published_at?->toISOString(),
            'created_at' => $v->created_at?->toISOString(),
        ])->values();

        return $this->done($request, [
            'flow' => [
                'id' => $flow->id,
                'key' => $flow->key,
                'title_ar' => $flow->title_ar,
                'is_active' => $flow->is_active,
            ],
            'draft' => $draftRow?->definition,
            'published' => $flow->definition,
            'errors' => $flowDrafts->errors($definition),
            'warnings' => [...FlowDefinition::warnings($definition), ...FlowDefinition::referenceWarnings($definition)],
            'versions' => $versions,
            'draft_updated_at' => $draftRow?->updated_at?->toISOString(),
        ]);
    }

    public function store(Request $request, FlowDrafts $flowDrafts, ActivityLogger $logger): HttpResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,40}$/', 'unique:bot_flows,key'],
            'title_ar' => ['required', 'string', 'max:100'],
            'copy_from' => ['nullable', 'integer', 'exists:bot_flows,id'],
        ]);

        $copyFrom = isset($data['copy_from']) ? BotFlow::findOrFail($data['copy_from']) : null;

        $flow = $flowDrafts->create($data['key'], $data['title_ar'], $copyFrom, $request->user());

        $logger->log(ActorType::User, $request->user(), ActivityLogger::BOT_FLOW_CREATED, $flow, null, ['key' => $flow->key]);

        return $this->done($request, ['flow' => $flow], 201);
    }

    public function update(Request $request, BotFlow $flow, ActivityLogger $logger): HttpResponse
    {
        $data = $request->validate([
            'title_ar' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_active'] ?? true) === false && $flow->key === self::MAIN_MENU_KEY) {
            return response()->json(['message' => __('errors.flows.main_menu_must_stay_active')], 422);
        }

        $flow->update($data);

        $logger->log(ActorType::User, $request->user(), ActivityLogger::BOT_FLOW_UPDATED, $flow, null, ['key' => $flow->key]);

        return $this->done($request, ['flow' => $flow->fresh()]);
    }

    public function saveDraft(Request $request, BotFlow $flow, FlowDrafts $flowDrafts, ActivityLogger $logger): HttpResponse
    {
        $data = $request->validate([
            'definition' => ['required', 'array'],
            'base_updated_at' => ['nullable', 'date'],
        ]);

        $existingDraft = $flow->draft()->first();

        // No base means the client opened the flow while it had no draft; if one exists now,
        // someone else created it in the meantime, so that is a conflict too.
        if ($existingDraft && (empty($data['base_updated_at'])
            || $existingDraft->updated_at->gt(Carbon::parse($data['base_updated_at'])))) {
            return response()->json(['message' => __('errors.flows.draft_conflict')], 409);
        }

        try {
            $draft = $flowDrafts->saveDraft($flow, $data['definition'], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $logger->log(ActorType::User, $request->user(), ActivityLogger::BOT_FLOW_DRAFT_SAVED, $flow, null, ['key' => $flow->key]);

        return $this->done($request, [
            'draft_updated_at' => $draft->updated_at?->toISOString(),
            'errors' => $flowDrafts->errors($draft->definition),
            'warnings' => [...FlowDefinition::warnings($draft->definition), ...FlowDefinition::referenceWarnings($draft->definition)],
        ]);
    }

    public function discardDraft(BotFlow $flow, FlowDrafts $flowDrafts): HttpResponse
    {
        $flowDrafts->discardDraft($flow);

        return response()->noContent();
    }

    public function publish(Request $request, BotFlow $flow, FlowDrafts $flowDrafts, ActivityLogger $logger): HttpResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $version = $flowDrafts->publish($flow, $request->user(), $data['note'] ?? null);
        } catch (FlowValidationException $e) {
            return response()->json(['message' => __('errors.flows.publish_has_errors'), 'errors' => $e->errors], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        }

        $logger->log(ActorType::User, $request->user(), ActivityLogger::BOT_FLOW_PUBLISHED, $flow, null, ['key' => $flow->key, 'version' => $version->version]);

        return $this->done($request, ['version' => $version]);
    }

    public function restore(Request $request, BotFlowVersion $version, FlowDrafts $flowDrafts, ActivityLogger $logger): HttpResponse
    {
        $flow = $version->flow;

        try {
            $restored = $flowDrafts->restore($version, $request->user());
        } catch (FlowValidationException $e) {
            return response()->json(['message' => __('errors.flows.restore_has_errors'), 'errors' => $e->errors], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        }

        $logger->log(ActorType::User, $request->user(), ActivityLogger::BOT_FLOW_RESTORED, $flow, null, ['key' => $flow->key, 'version' => $restored->version]);

        return $this->done($request, ['version' => $restored]);
    }

    /** Appends `{title, action: "flow:<key>", synonyms: []}` to the main menu's draft options (design doc §2). */
    public function addToMainMenu(Request $request, BotFlow $flow, FlowDrafts $flowDrafts, ActivityLogger $logger): HttpResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:20'],
        ]);

        $mainMenu = BotFlow::query()->where('key', self::MAIN_MENU_KEY)->firstOrFail();
        $action = 'flow:'.$flow->key;

        try {
            $draft = $flowDrafts->appendMenuOption($mainMenu, $data['title'], $action, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $logger->log(ActorType::User, $request->user(), ActivityLogger::BOT_FLOW_DRAFT_SAVED, $mainMenu, null, ['key' => $mainMenu->key]);

        return $this->done($request, ['draft_updated_at' => $draft->updated_at?->toISOString()]);
    }
}
