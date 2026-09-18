<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import FlowCanvas from '@/components/crm/flows/FlowCanvas.vue';
import FlowSandboxChat from '@/components/crm/flows/FlowSandboxChat.vue';
import FlowToolbar, { type DrawerTab } from '@/components/crm/flows/FlowToolbar.vue';
import FlowVersions from '@/components/crm/flows/FlowVersions.vue';
import InspectorDrawer from '@/components/crm/flows/InspectorDrawer.vue';
import SidebarAutoCollapse from '@/components/crm/flows/SidebarAutoCollapse.vue';
import StepEditor from '@/components/crm/flows/StepEditor.vue';
import StepPalette from '@/components/crm/flows/StepPalette.vue';
import StepPreview from '@/components/crm/flows/StepPreview.vue';
import { TooltipProvider } from '@/components/ui/tooltip';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { layout as autoLayout } from '@/lib/flows/autoLayout';
import {
    connect,
    deleteStep,
    describeError,
    disconnect,
    errorsByStep as groupErrors,
    insertStep,
    setPosition,
    stepOfMessage,
    type TerminalLabels,
} from '@/lib/flows/flowGraph';
import type { InlineDraft } from '@/lib/flows/inlineEdit';
import { PALETTE_CARDS } from '@/lib/flows/stepPalette';
import { formatClock, formatDateTime } from '@/lib/format';
import type {
    FlowDefinition,
    FlowListRow,
    FlowScriptOption,
    FlowShowPayload,
    FlowStep,
    FlowVersionRow,
    SandboxSource,
    SourceHandle,
    StepTypeCatalog,
} from '@/types/flows';
import { Head, router } from '@inertiajs/vue3';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/minimap/dist/style.css';
import { useMediaQuery } from '@vueuse/core';
import { AxiosError } from 'axios';
import { CircleAlert, LoaderCircle, PanelBottomOpen, Plus, RotateCcw, SquarePen, TriangleAlert, Undo2, Workflow } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps<{ flows: FlowListRow[]; stepTypes: StepTypeCatalog; scripts: FlowScriptOption[]; flowKeys: string[] }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const isDesktop = useMediaQuery('(min-width: 768px)');

const MAIN_MENU_KEY = 'main_menu';
const MAX_MENU_TITLE = 20;

// ---- flow list -------------------------------------------------------------
const flowRows = ref<FlowListRow[]>(props.flows.map((f) => ({ ...f })));
watch(
    () => props.flows,
    (list) => (flowRows.value = list.map((f) => ({ ...f }))),
);

function patchRow(id: number, patch: Partial<FlowListRow>): void {
    flowRows.value = flowRows.value.map((row) => (row.id === id ? { ...row, ...patch } : row));
}

function reloadFlows(onSuccess?: () => void): void {
    router.reload({ only: ['flows', 'flowKeys'], onSuccess: () => onSuccess?.() });
}

// ---- the open flow ---------------------------------------------------------
const selectedFlowId = ref<number | null>(null);
const flow = ref<FlowShowPayload['flow'] | null>(null);
const def = ref<FlowDefinition | null>(null);
const savedJson = ref('');
const hasDraft = ref(false);
const draftUpdatedAt = ref<string | null>(null);
const lastSavedAt = ref<string | null>(null);
const versions = ref<FlowVersionRow[]>([]);
const errors = ref<string[]>([]);
const warnings = ref<string[]>([]);
const loading = ref(false);
const saving = ref(false);
const stale = ref(false);
/** A restore published an older version while a draft still exists (the draft is not touched by a restore). */
const restoreLeftDraft = ref(false);

const selectedStepId = ref<string | null>(null);
const canvas = ref<InstanceType<typeof FlowCanvas> | null>(null);

// ---- the inspector drawer ----------------------------------------------------
/** The open drawer tab, or null when the canvas has the full width. */
const drawer = ref<DrawerTab | null>(null);
/** The last tab opened, so the drawer keeps its content while it slides shut. */
const drawerTab = ref<DrawerTab>('step');
/** The drawer's width on wide screens (w-[400px], xl:w-[420px]), so a focused step is centred beside it. */
const isXl = useMediaQuery('(min-width: 1280px)');
const drawerInset = computed(() => (drawer.value && isDesktop.value ? (isXl.value ? 420 : 400) : 0));

watch(drawer, (tab) => {
    if (tab) drawerTab.value = tab;
});

function toggleDrawer(tab: DrawerTab): void {
    drawer.value = drawer.value === tab ? null : tab;
}

/** X / Esc: closing the step tab also lets go of the selected step. */
function closeDrawer(): void {
    if (drawer.value === 'step') selectedStepId.value = null;
    drawer.value = null;
}

// ---- sandbox chat ----------------------------------------------------------
const testSource = ref<SandboxSource>('draft');
/** The chat mounts the first time its tab opens and then stays alive, so switching tabs keeps the conversation. */
const testMounted = ref(false);
const sandboxCurrent = ref<{ stepId: string; flowKey: string } | null>(null);

/** The step the test conversation is on, ringed on the canvas while the تجربة tab is open. */
const highlightStepId = computed(() => {
    const current = sandboxCurrent.value;
    if (drawer.value !== 'test' || !current || !flow.value || !def.value) return null;
    return current.flowKey === flow.value.key && def.value.steps[current.stepId] ? current.stepId : null;
});

function onSandboxCurrent(stepId: string | null, flowKey: string | null): void {
    sandboxCurrent.value = stepId && flowKey ? { stepId, flowKey } : null;
    const id = highlightStepId.value;
    // On phones the bottom sheet covers the canvas, so only the ring is shown there.
    if (id && isDesktop.value) nextTick(() => window.setTimeout(() => canvas.value?.focusStep(id), 60));
}

watch(drawer, (tab) => {
    if (tab === 'test') testMounted.value = true;
});

const dirty = computed(() => def.value !== null && JSON.stringify(def.value) !== savedJson.value);
const currentRow = computed(() => flowRows.value.find((row) => row.id === selectedFlowId.value) ?? null);

function confirmLeave(): boolean {
    return !dirty.value || window.confirm(t('flows.unsaved_confirm'));
}

function selectFlow(id: number, force = false): void {
    if (id === selectedFlowId.value && !force) return;
    if (!force && !confirmLeave()) return;
    // Leaving on purpose drops the unsaved copy kept for back/forward navigation.
    if (!force && dirty.value && flow.value) forgetUnsaved(flow.value.id);
    const previousId = selectedFlowId.value;
    const previousUrl = window.location.href;
    selectedFlowId.value = id;
    const key = flowRows.value.find((row) => row.id === id)?.key;
    if (key) {
        const url = new URL(window.location.href);
        url.searchParams.set('flow', key);
        window.history.replaceState(window.history.state, '', url);
    }
    loadFlow(id).then((loaded) => {
        // A failed load keeps the previous flow open, so the selection and ?flow= go back to it.
        if (loaded || selectedFlowId.value !== id) return;
        selectedFlowId.value = previousId;
        window.history.replaceState(window.history.state, '', previousUrl);
    });
}

/** @returns false when the request failed */
async function loadFlow(id: number): Promise<boolean> {
    loading.value = true;
    stale.value = false;
    restoreLeftDraft.value = false;
    recoverable.value = null;
    try {
        const { data } = await api.get<{ data: FlowShowPayload }>(`/settings/bot-flows/${id}`);
        if (selectedFlowId.value !== id) return true;
        const payload = data.data;
        let definition = JSON.parse(JSON.stringify(payload.draft ?? payload.published)) as FlowDefinition;
        // A definition without positions is laid out once on load; that alone is not an unsaved change.
        if (!definition.layout || !Object.keys(definition.layout).length) definition = autoLayout(definition);
        flow.value = payload.flow;
        titleDraft.value = payload.flow.title_ar;
        def.value = definition;
        savedJson.value = JSON.stringify(definition);
        hasDraft.value = payload.draft !== null;
        draftUpdatedAt.value = payload.draft_updated_at;
        lastSavedAt.value = null;
        versions.value = payload.versions;
        errors.value = payload.errors;
        warnings.value = payload.warnings;
        selectedStepId.value = null;
        if (drawer.value === 'step') drawer.value = null;
        patchRow(id, { has_draft: hasDraft.value });
        offerUnsaved(id, savedJson.value);
        canvas.value?.fit();
        return true;
    } catch (e) {
        toast.push(apiErrorMessage(e, t('flows.load_failed')), 'error');
        return false;
    } finally {
        loading.value = false;
    }
}

// ---- editing ---------------------------------------------------------------
function applyDef(next: FlowDefinition, selectId?: string): void {
    def.value = next;
    if (selectId !== undefined) selectedStepId.value = selectId;
    if (selectedStepId.value && !next.steps[selectedStepId.value]) selectedStepId.value = null;
}

/** Picking a step opens its drawer tab; clicking the empty canvas closes it again. */
function selectStep(id: string | null): void {
    selectedStepId.value = id;
    if (id) drawer.value = 'step';
    else if (drawer.value === 'step') drawer.value = null;
}

function focusProblem(stepId: string): void {
    selectStep(stepId);
    nextTick(() => window.setTimeout(() => canvas.value?.focusStep(stepId), 60));
}

function onConnect(source: { stepId: string; handle: SourceHandle }, target: string): void {
    if (!def.value) return;
    const step = def.value.steps[source.stepId];
    if (source.handle.startsWith('option:') && props.stepTypes[step?.type]?.options === 'menu') {
        toast.push(t('flows.menu_option_needs_action'), 'info');
        selectStep(source.stepId);
        return;
    }
    applyDef(connect(def.value, source, target));
}

function onDisconnect(edgeId: string): void {
    if (def.value) applyDef(disconnect(def.value, edgeId));
}

function onMove(positions: { id: string; x: number; y: number }[]): void {
    if (!def.value) return;
    let next = def.value;
    for (const p of positions) next = setPosition(next, p.id, p.x, p.y);
    applyDef(next);
}

function runAutoLayout(): void {
    if (!def.value) return;
    applyDef(autoLayout(def.value));
    canvas.value?.fit();
}

// ---- ready-made steps (design 2026-09-18 §4) --------------------------------
const PALETTE_KEY = 'bot-flows:palette-open';

function readPaletteOpen(): boolean {
    try {
        const stored = window.localStorage.getItem(PALETTE_KEY);
        // The rail starts as icons only, so the canvas keeps the room.
        return stored === '1';
    } catch {
        return false;
    }
}

const paletteOpen = ref(readPaletteOpen());
watch(paletteOpen, (open) => {
    try {
        window.localStorage.setItem(PALETTE_KEY, open ? '1' : '0');
    } catch {
        // Only a convenience: the palette just opens with its default next time.
    }
});

/** A script step starts on the first active script, so it is valid right away. */
function presetFor(type: string): Partial<FlowStep> {
    const preset = { ...(PALETTE_CARDS.find((card) => card.type === type)?.preset ?? {}) };
    if (type === 'script') {
        const script = props.scripts.find((s) => s.is_active !== false) ?? props.scripts[0];
        if (script) preset.script = script.key;
    }
    return preset;
}

/** Adds a ready-made step below the selected one (or where it was dropped) and connects it to the selected step. */
function onAddStep(type: string, position?: { x: number; y: number }): void {
    if (!def.value) return;
    const after = selectedStepId.value && def.value.steps[selectedStepId.value] ? selectedStepId.value : null;
    const { def: added, id, connectedFrom } = insertStep(def.value, type, props.stepTypes, { after, position, preset: presetFor(type) });
    applyDef(added, id);
    selectStep(id);
    if (connectedFrom) toast.push(t('flows.palette.connected', { step: connectedFrom }));
    else if (after) toast.push(t('flows.palette.not_connected', { step: after }), 'info');
    if (!position) nextTick(() => window.setTimeout(() => canvas.value?.focusStep(id), 80));
}

// ---- customer preview (design 2026-09-18 §5) --------------------------------
/** An inline edit on a node that is not saved yet, shown live by the preview. */
const inlineDraft = ref<InlineDraft | null>(null);

const previewedStep = computed<FlowStep | null>(() => {
    const id = selectedStepId.value;
    const step = id && def.value ? def.value.steps[id] : null;
    if (!step) return null;
    const draft = inlineDraft.value;
    if (!draft || draft.stepId !== id) return step;
    const copy: FlowStep = JSON.parse(JSON.stringify(step));
    if (draft.text !== undefined) copy.text = draft.text;
    if (draft.option && copy.options?.[draft.option.index]) copy.options[draft.option.index].title = draft.option.title;
    return copy;
});

function onDeleteStep(): void {
    const id = selectedStepId.value;
    if (!def.value || !id || def.value.start === id) return;
    if (!window.confirm(t('flows.delete_step_confirm', { step: id }))) return;
    applyDef(deleteStep(def.value, id));
    selectStep(null);
}

// ---- errors ----------------------------------------------------------------
const nodeErrors = computed(() => (def.value ? groupErrors(errors.value, def.value) : {}));

const stepWarnings = computed(() => (def.value ? groupErrors(warnings.value, def.value) : {}));
const describe = (message: string): string => describeError(message, t);

const publishBlockedErrors = computed(() => (errors.value.length && !dirty.value ? errors.value.map(describe) : []));

const problems = computed(() =>
    [
        ...errors.value.map((m) => ({ message: m, tone: 'error' as const })),
        ...warnings.value.map((m) => ({ message: m, tone: 'warning' as const })),
    ].map((p) => ({
        ...p,
        text: describe(p.message),
        stepId: def.value ? stepOfMessage(p.message, def.value) : null,
    })),
);

// ---- save / discard / publish ----------------------------------------------
async function saveDraft(silent = false): Promise<boolean> {
    if (!def.value || !flow.value || saving.value) return false;
    const id = flow.value.id;
    const json = JSON.stringify(def.value);
    saving.value = true;
    try {
        const { data } = await api.put<{ data: { draft_updated_at: string | null; errors: string[]; warnings: string[] } }>(
            `/settings/bot-flows/${id}/draft`,
            {
                definition: def.value,
                base_updated_at: draftUpdatedAt.value,
            },
        );
        savedJson.value = json;
        forgetUnsaved(id);
        hasDraft.value = true;
        draftUpdatedAt.value = data.data.draft_updated_at;
        lastSavedAt.value = new Date().toISOString();
        errors.value = data.data.errors;
        warnings.value = data.data.warnings;
        patchRow(id, { has_draft: true });
        if (!silent) toast.push(t('flows.saved'));
        return true;
    } catch (e) {
        if (e instanceof AxiosError && e.response?.status === 409) {
            stale.value = true;
            toast.push(t('flows.stale_draft'), 'error');
        } else {
            toast.push(apiErrorMessage(e, t('common.error')), 'error');
        }
        return false;
    } finally {
        saving.value = false;
    }
}

async function discardDraft(): Promise<void> {
    if (!flow.value) return;
    if (!hasDraft.value) {
        // Nothing on the server yet: just drop the local edits.
        if (dirty.value && window.confirm(t('flows.discard_confirm'))) {
            forgetUnsaved(flow.value.id);
            loadFlow(flow.value.id);
        }
        return;
    }
    if (!window.confirm(t('flows.discard_confirm'))) return;
    try {
        await api.delete(`/settings/bot-flows/${flow.value.id}/draft`);
        forgetUnsaved(flow.value.id);
        toast.push(t('flows.discarded'));
        savedJson.value = JSON.stringify(def.value);
        await loadFlow(flow.value.id);
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

function reloadStale(): void {
    if (!flow.value) return;
    // The banner says the local edits are lost, so their stored copy goes too.
    forgetUnsaved(flow.value.id);
    loadFlow(flow.value.id);
}

const publishOpen = ref(false);
const publishNote = ref('');
const publishing = ref(false);

const canPublish = computed(() => !!def.value && !stale.value && (dirty.value || hasDraft.value) && !(errors.value.length && !dirty.value));

function openPublish(): void {
    if (!canPublish.value) return;
    publishNote.value = '';
    publishOpen.value = true;
}

async function publish(): Promise<void> {
    if (!flow.value || publishing.value) return;
    const id = flow.value.id;
    publishing.value = true;
    try {
        if (dirty.value && !(await saveDraft(true))) return;
        await api.post(`/settings/bot-flows/${id}/publish`, { note: publishNote.value.trim() || null });
        publishOpen.value = false;
        forgetUnsaved(id);
        toast.push(t('flows.published'));
        reloadFlows();
        await loadFlow(id);
    } catch (e) {
        if (e instanceof AxiosError && e.response?.status === 422) {
            const body = e.response.data as { message?: string; errors?: string[] };
            if (Array.isArray(body.errors) && body.errors.length) errors.value = body.errors;
            publishOpen.value = false;
            toast.push(body.message ?? t('common.error'), 'error');
        } else {
            toast.push(apiErrorMessage(e, t('common.error')), 'error');
        }
    } finally {
        publishing.value = false;
    }
}

/** After a restore: the published definition and the versions list changed on the server. */
async function onVersionsChanged(): Promise<void> {
    if (!flow.value) return;
    const id = flow.value.id;
    reloadFlows();
    if (!dirty.value) {
        if ((await loadFlow(id)) && flow.value?.id === id && hasDraft.value) restoreLeftDraft.value = true;
        return;
    }
    // Local edits are kept; only the versions list is refreshed.
    try {
        const { data } = await api.get<{ data: FlowShowPayload }>(`/settings/bot-flows/${id}`);
        if (flow.value?.id !== id) return;
        versions.value = data.data.versions;
        if (data.data.draft !== null) restoreLeftDraft.value = true;
    } catch (e) {
        toast.push(apiErrorMessage(e, t('flows.load_failed')), 'error');
    }
}

// ---- title / active --------------------------------------------------------
const titleDraft = ref('');
const renameOpen = ref(false);
const renaming = ref(false);

function openRename(): void {
    titleDraft.value = flow.value?.title_ar ?? '';
    renameOpen.value = true;
}

async function submitRename(): Promise<void> {
    if (renaming.value) return;
    renaming.value = true;
    try {
        await saveTitle();
        renameOpen.value = false;
    } finally {
        renaming.value = false;
    }
}

async function patchFlow(payload: { title_ar?: string; is_active?: boolean }): Promise<boolean> {
    if (!flow.value) return false;
    try {
        const { data } = await api.patch<{ data: { flow: FlowShowPayload['flow'] } }>(`/settings/bot-flows/${flow.value.id}`, payload);
        flow.value = { ...flow.value, title_ar: data.data.flow.title_ar, is_active: data.data.flow.is_active };
        patchRow(flow.value.id, { title_ar: flow.value.title_ar, is_active: flow.value.is_active });
        return true;
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
        return false;
    }
}

async function saveTitle(): Promise<void> {
    const title = titleDraft.value.trim();
    if (!flow.value || !title || title === flow.value.title_ar) {
        titleDraft.value = flow.value?.title_ar ?? '';
        return;
    }
    if (await patchFlow({ title_ar: title })) toast.push(t('flows.title_saved'));
    else titleDraft.value = flow.value.title_ar;
}

const isActive = computed({
    get: () => flow.value?.is_active ?? false,
    set: (value: boolean) => {
        patchFlow({ is_active: value });
    },
});

// ---- create ----------------------------------------------------------------
const createOpen = ref(false);
const creating = ref(false);
const createError = ref<string | null>(null);
const createForm = reactive({ key: '', title_ar: '', copy_from: '' as number | '' });

function openCreate(): void {
    Object.assign(createForm, { key: '', title_ar: '', copy_from: '' });
    createError.value = null;
    createOpen.value = true;
}

async function create(): Promise<void> {
    if (creating.value) return;
    if (!confirmLeave()) return;
    creating.value = true;
    createError.value = null;
    try {
        const { data } = await api.post<{ data: { flow: { id: number } } }>('/settings/bot-flows', {
            key: createForm.key.trim(),
            title_ar: createForm.title_ar.trim(),
            copy_from: createForm.copy_from === '' ? null : createForm.copy_from,
        });
        createOpen.value = false;
        toast.push(t('flows.created'));
        const newId = data.data.flow.id;
        savedJson.value = def.value ? JSON.stringify(def.value) : '';
        reloadFlows(() => selectFlow(newId, true));
    } catch (e) {
        createError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        creating.value = false;
    }
}

// ---- add to main menu ------------------------------------------------------
const menuOpen = ref(false);
const menuTitle = ref('');
const addingToMenu = ref(false);
const menuError = ref<string | null>(null);
const canAddToMenu = computed(() => !!flow.value && flow.value.key !== MAIN_MENU_KEY && !currentRow.value?.in_main_menu);

function openMenu(): void {
    menuTitle.value = (flow.value?.title_ar ?? '').slice(0, MAX_MENU_TITLE);
    menuError.value = null;
    menuOpen.value = true;
}

async function addToMenu(): Promise<void> {
    if (!flow.value || addingToMenu.value) return;
    addingToMenu.value = true;
    menuError.value = null;
    try {
        await api.post(`/settings/bot-flows/${flow.value.id}/main-menu`, { title: menuTitle.value.trim() });
        menuOpen.value = false;
        toast.push(t('flows.added_to_menu'));
        const main = flowRows.value.find((row) => row.key === MAIN_MENU_KEY);
        if (main) patchRow(main.id, { has_draft: true });
    } catch (e) {
        menuError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        addingToMenu.value = false;
    }
}

// ---- labels ----------------------------------------------------------------
const flowTitle = (key: string): string => flowRows.value.find((row) => row.key === key)?.title_ar ?? key;
const scriptTitle = (key: string): string => props.scripts.find((s) => s.key === key)?.title ?? key;

const terminalLabels = computed<TerminalLabels>(() => ({
    flow: (key) => t('flows.terminal.flow', { name: flowTitle(key) }),
    menu: (key) => t('flows.terminal.menu', { name: flowTitle(key) }),
    script: (key) => t('flows.terminal.script', { name: scriptTitle(key) }),
    handover: t('flows.terminal.handover'),
    end: t('flows.end'),
    branch: (condition) => t('flows.branch_if', { condition }),
}));

/** "آخر حفظ …" on the save-state tooltip. */
const savedLabel = computed(() => {
    if (lastSavedAt.value) return t('flows.saved_at', { time: formatClock(lastSavedAt.value, locale.value) });
    if (draftUpdatedAt.value) return t('flows.saved_at', { time: formatDateTime(draftUpdatedAt.value, locale.value) });
    return null;
});

// ---- unsaved edits kept across back/forward navigation ----------------------
const UNSAVED_PREFIX = 'bot-flows:draft:';
const UNSAVED_DEBOUNCE_MS = 400;

interface UnsavedCopy {
    definition: FlowDefinition;
    base_updated_at: string | null;
}

/** A stored unsaved copy that differs from what was just loaded, waiting for restore / ignore. */
const recoverable = ref<UnsavedCopy | null>(null);

function readUnsaved(id: number): UnsavedCopy | null {
    try {
        const raw = window.sessionStorage.getItem(UNSAVED_PREFIX + id);
        const parsed = raw ? (JSON.parse(raw) as Partial<UnsavedCopy> | null) : null;
        const definition = parsed?.definition;
        if (!definition || typeof definition !== 'object' || typeof definition.steps !== 'object' || definition.steps === null) return null;
        return { definition, base_updated_at: parsed?.base_updated_at ?? null };
    } catch {
        return null;
    }
}

function writeUnsaved(id: number, json: string | null): void {
    try {
        if (json === null) window.sessionStorage.removeItem(UNSAVED_PREFIX + id);
        else window.sessionStorage.setItem(UNSAVED_PREFIX + id, json);
    } catch {
        // Storage can be unavailable (private mode, quota); the copy is only a convenience.
    }
}

let unsavedTimer: number | undefined;

function forgetUnsaved(id: number): void {
    window.clearTimeout(unsavedTimer);
    if (flow.value?.id === id) recoverable.value = null;
    writeUnsaved(id, null);
}

function offerUnsaved(id: number, loadedJson: string): void {
    const stored = readUnsaved(id);
    if (!stored) return;
    if (JSON.stringify(stored.definition) === loadedJson) writeUnsaved(id, null);
    else recoverable.value = stored;
}

function recoverUnsaved(): void {
    const copy = recoverable.value;
    if (!copy || !def.value) return;
    recoverable.value = null;
    // Saving checks against the draft the edits were based on, so a newer draft still reports a conflict.
    draftUpdatedAt.value = copy.base_updated_at;
    applyDef(copy.definition);
}

function ignoreUnsaved(): void {
    if (flow.value) forgetUnsaved(flow.value.id);
}

// Every edit (debounced) keeps a copy of the dirty definition per flow; a clean one removes it.
watch([def, savedJson], () => {
    window.clearTimeout(unsavedTimer);
    const id = flow.value?.id;
    if (id === undefined || !def.value || recoverable.value) return;
    const json = dirty.value ? JSON.stringify({ definition: def.value, base_updated_at: draftUpdatedAt.value }) : null;
    unsavedTimer = window.setTimeout(() => writeUnsaved(id, json), UNSAVED_DEBOUNCE_MS);
});

// ---- unsaved-changes guard -------------------------------------------------
function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (!dirty.value) return;
    event.preventDefault();
    event.returnValue = '';
}

let removeRouterGuard: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    removeRouterGuard = router.on('before', (event) => {
        // Partial reloads of this page (flow list refresh, `only: [...]`) keep the editor state. Any other visit,
        // including a full visit to this same URL from the sidebar or breadcrumb, remounts the page and asks first.
        const visit = event.detail.visit;
        if (!dirty.value || (visit.only.length > 0 && visit.url.pathname === window.location.pathname)) return;
        if (!window.confirm(t('flows.unsaved_confirm'))) event.preventDefault();
        else if (flow.value) forgetUnsaved(flow.value.id);
    });

    const wanted = new URLSearchParams(window.location.search).get('flow');
    const initial = flowRows.value.find((row) => row.key === wanted) ?? flowRows.value[0];
    if (initial) selectFlow(initial.id, true);
});

onBeforeUnmount(() => {
    window.clearTimeout(unsavedTimer);
    window.removeEventListener('beforeunload', onBeforeUnload);
    removeRouterGuard?.();
});

const breadcrumbs = computed(() => [
    { title: t('settings.bot.title'), href: '/settings/bot' },
    { title: t('flows.title'), href: '/settings/bot-flows' },
]);

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <Head :title="t('flows.title')" />

    <AppLayout :breadcrumbs="breadcrumbs" fill>
        <SidebarAutoCollapse />
        <TooltipProvider :delay-duration="150">
            <div class="flex min-h-0 flex-1 flex-col overflow-hidden bg-background">
                <FlowToolbar
                    v-model:active="isActive"
                    :flows="flowRows"
                    :selected-id="selectedFlowId"
                    :ready="!!flow && !!def"
                    :active-disabled="flow?.key === MAIN_MENU_KEY"
                    :dirty="dirty"
                    :saving="saving"
                    :has-draft="hasDraft"
                    :stale="stale"
                    :saved-label="savedLabel"
                    :problems="problems"
                    :error-count="errors.length"
                    :can-publish="canPublish"
                    :publishing="publishing"
                    :publish-blocked-errors="publishBlockedErrors"
                    :can-add-to-menu="canAddToMenu"
                    :drawer="drawer"
                    @select="selectFlow"
                    @create="openCreate"
                    @save="saveDraft()"
                    @discard="discardDraft"
                    @publish="openPublish"
                    @auto-layout="runAutoLayout"
                    @add-to-menu="openMenu"
                    @rename="openRename"
                    @toggle-drawer="toggleDrawer"
                    @focus-problem="focusProblem"
                />

                <!-- Banners that need a decision before editing goes on. -->
                <div v-if="stale || recoverable || (restoreLeftDraft && hasDraft)" class="space-y-px border-b border-border">
                    <div v-if="stale" role="alert" class="flex flex-wrap items-center gap-2 bg-destructive/10 px-4 py-2 text-xs text-destructive">
                        <CircleAlert class="size-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1">{{ t('flows.stale_draft') }}</span>
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1 rounded-md bg-destructive px-3 font-semibold text-destructive-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="reloadStale"
                        >
                            <RotateCcw class="size-3.5" aria-hidden="true" />{{ t('flows.reload') }}
                        </button>
                    </div>

                    <div
                        v-if="recoverable"
                        role="alert"
                        class="flex flex-wrap items-center gap-2 bg-amber-500/15 px-4 py-2 text-xs text-amber-900 dark:text-amber-100"
                    >
                        <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1">{{ t('flows.recover_prompt') }}</span>
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1 rounded-md bg-amber-600 px-3 font-semibold text-white outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="recoverUnsaved"
                        >
                            <RotateCcw class="size-3.5" aria-hidden="true" />{{ t('flows.recover') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-8 items-center rounded-md border border-amber-600/40 px-3 font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="ignoreUnsaved"
                        >
                            {{ t('flows.recover_ignore') }}
                        </button>
                    </div>

                    <div
                        v-if="restoreLeftDraft && hasDraft"
                        role="alert"
                        class="flex flex-wrap items-center gap-2 bg-amber-500/15 px-4 py-2 text-xs text-amber-900 dark:text-amber-100"
                    >
                        <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1">{{ t('flows.restore_left_draft') }}</span>
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1 rounded-md bg-amber-600 px-3 font-semibold text-white outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="discardDraft"
                        >
                            <Undo2 class="size-3.5" aria-hidden="true" />{{ t('flows.discard_draft') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-8 items-center rounded-md border border-amber-600/40 px-3 font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="restoreLeftDraft = false"
                        >
                            {{ t('flows.recover_ignore') }}
                        </button>
                    </div>
                </div>

                <!-- Workspace: the step rail on the start edge, the canvas, and the drawer sliding over it from the end. -->
                <div class="relative flex min-h-0 flex-1 overflow-hidden">
                    <StepPalette
                        v-if="def && flow"
                        v-model:open="paletteOpen"
                        :catalog="stepTypes"
                        :selected-step-id="selectedStepId"
                        :disabled="loading"
                        @add="onAddStep"
                    />

                    <main class="relative min-w-0 flex-1">
                        <EmptyState
                            v-if="!flowRows.length"
                            :icon="Workflow"
                            :title="t('flows.no_flows')"
                            :body="t('flows.no_flows_body')"
                            class="h-full"
                        >
                            <button
                                type="button"
                                class="mt-2 inline-flex h-10 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-semibold text-primary-foreground outline-none hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                @click="openCreate"
                            >
                                <Plus class="size-4" aria-hidden="true" />{{ t('flows.new_flow') }}
                            </button>
                        </EmptyState>

                        <FlowCanvas
                            v-else-if="def && flow"
                            ref="canvas"
                            :flow-key="flow.key"
                            :def="def"
                            :catalog="stepTypes"
                            :errors-by-step="nodeErrors"
                            :labels="terminalLabels"
                            :highlight-id="highlightStepId"
                            :end-inset="drawerInset"
                            @select="selectStep"
                            @connect="onConnect"
                            @disconnect="onDisconnect"
                            @move="onMove"
                            @change="applyDef"
                            @draft="inlineDraft = $event"
                            @drop-step="onAddStep"
                            @auto-layout="runAutoLayout"
                        />

                        <div
                            v-if="loading"
                            class="absolute inset-0 z-20 flex items-center justify-center bg-background/60 backdrop-blur-[1px]"
                            aria-busy="true"
                        >
                            <span class="flex items-center gap-2 rounded-full bg-card px-4 py-2 text-sm shadow-card">
                                <LoaderCircle class="size-4 animate-spin text-primary" aria-hidden="true" />{{ t('common.loading') }}
                            </span>
                        </div>

                        <button
                            v-if="!isDesktop && def && drawer === null"
                            type="button"
                            class="absolute bottom-4 end-4 z-20 inline-flex h-11 items-center gap-1.5 rounded-full bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-lg outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            @click="drawer = drawerTab"
                        >
                            <PanelBottomOpen class="size-4" aria-hidden="true" />{{ t('flows.open_panel') }}
                        </button>
                    </main>

                    <InspectorDrawer v-if="flow" v-model:tab="drawer" :shown="drawerTab" :mobile="!isDesktop" @close="closeDrawer">
                        <template v-if="drawerTab === 'step'">
                            <div v-if="def && previewedStep" class="px-4 pt-4">
                                <StepPreview :step="previewedStep" :def="def" :scripts="scripts" :flows="flowRows" />
                            </div>
                            <StepEditor
                                v-if="def && selectedStepId && def.steps[selectedStepId]"
                                :def="def"
                                :step-id="selectedStepId"
                                :catalog="stepTypes"
                                :flows="flowRows"
                                :scripts="scripts"
                                :flow-key="flow.key"
                                :errors="(nodeErrors[selectedStepId] ?? []).map(describe)"
                                :warnings="(stepWarnings[selectedStepId] ?? []).map(describe)"
                                @change="applyDef"
                                @delete="onDeleteStep"
                            />
                            <div v-else-if="def" class="space-y-3 p-4">
                                <EmptyState :icon="SquarePen" :title="t('flows.select_step')" :body="t('flows.select_step_hint')" class="p-4" />
                                <p v-if="!problems.length" class="text-center text-xs text-muted-foreground">{{ t('flows.no_problems') }}</p>
                                <ul v-else class="space-y-1.5">
                                    <li v-for="(problem, i) in problems" :key="i">
                                        <button
                                            type="button"
                                            class="flex w-full gap-1.5 rounded-md px-2.5 py-1.5 text-start text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default"
                                            :class="
                                                problem.tone === 'error'
                                                    ? 'bg-destructive/10 text-destructive'
                                                    : 'bg-amber-500/10 text-amber-800 dark:text-amber-200'
                                            "
                                            :disabled="!problem.stepId"
                                            @click="problem.stepId && focusProblem(problem.stepId)"
                                        >
                                            <component
                                                :is="problem.tone === 'error' ? CircleAlert : TriangleAlert"
                                                class="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            {{ problem.text }}
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </template>
                        <FlowVersions
                            v-else-if="drawerTab === 'versions'"
                            :flow-id="flow.id"
                            :versions="versions"
                            :can-publish="canPublish && !publishing"
                            @changed="onVersionsChanged"
                            @publish="openPublish"
                        />
                        <FlowSandboxChat
                            v-if="testMounted"
                            v-show="drawerTab === 'test'"
                            v-model:source="testSource"
                            class="min-h-0 flex-1"
                            :flow-id="flow.id"
                            :dirty="dirty"
                            @current="onSandboxCurrent"
                        />
                    </InspectorDrawer>
                </div>
            </div>
        </TooltipProvider>

        <FormDialog
            v-model:open="createOpen"
            :title="t('flows.new_flow')"
            :description="t('flows.new_flow_description')"
            :busy="creating"
            :error="createError"
            :submit-label="t('flows.new_flow')"
            @submit="create"
        >
            <label class="block">
                <span class="mb-1 block text-xs font-medium">{{ t('flows.flow_title') }}</span>
                <input v-model="createForm.title_ar" :class="input" required maxlength="100" dir="auto" />
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium">{{ t('flows.key') }}</span>
                <input v-model="createForm.key" :class="input" required dir="ltr" pattern="[a-z][a-z0-9_]{2,40}" maxlength="41" />
                <span class="mt-1 block text-2xs text-muted-foreground">{{ t('flows.key_hint') }}</span>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium">{{ t('flows.copy_from') }}</span>
                <select v-model="createForm.copy_from" :class="input">
                    <option value="">{{ t('flows.copy_none') }}</option>
                    <option v-for="row in flowRows" :key="row.id" :value="row.id">{{ row.title_ar }}</option>
                </select>
            </label>
        </FormDialog>

        <FormDialog
            v-model:open="publishOpen"
            :title="t('flows.publish_title')"
            :description="t('flows.publish_description')"
            :busy="publishing"
            :submit-label="t('flows.publish')"
            @submit="publish"
        >
            <label class="block">
                <span class="mb-1 block text-xs font-medium">{{ t('flows.publish_note') }}</span>
                <textarea
                    v-model="publishNote"
                    rows="3"
                    maxlength="200"
                    dir="auto"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
            </label>
        </FormDialog>

        <FormDialog
            v-model:open="renameOpen"
            :title="t('flows.workspace.rename')"
            :busy="renaming"
            :submit-label="t('common.save')"
            @submit="submitRename"
        >
            <label class="block">
                <span class="mb-1 block text-xs font-medium">{{ t('flows.flow_title') }}</span>
                <input v-model="titleDraft" :class="input" required maxlength="100" dir="auto" />
            </label>
        </FormDialog>

        <FormDialog
            v-model:open="menuOpen"
            :title="t('flows.add_to_menu')"
            :description="t('flows.add_to_menu_description')"
            :busy="addingToMenu"
            :error="menuError"
            :submit-label="t('flows.add_to_menu')"
            @submit="addToMenu"
        >
            <label class="block">
                <span class="mb-1 flex items-center justify-between text-xs font-medium">
                    {{ t('flows.menu_title') }}
                    <span class="tabular-nums text-muted-foreground" dir="ltr">{{ menuTitle.length }}/{{ MAX_MENU_TITLE }}</span>
                </span>
                <input v-model="menuTitle" :class="input" required :maxlength="MAX_MENU_TITLE" dir="auto" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
