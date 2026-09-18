<script setup lang="ts">
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import type { FlowListRow } from '@/types/flows';
import {
    CircleAlert,
    CircleCheck,
    Ellipsis,
    FlaskConical,
    History,
    LayoutDashboard,
    ListPlus,
    LoaderCircle,
    PencilLine,
    Save,
    Send,
    TriangleAlert,
    Undo2,
} from 'lucide-vue-next';
import { computed } from 'vue';
import FlowSwitcher from './FlowSwitcher.vue';

export interface ToolbarProblem {
    tone: 'error' | 'warning';
    text: string;
    stepId: string | null;
}

export type DrawerTab = 'step' | 'test' | 'versions';

/**
 * The designer's one toolbar: flow switcher + active toggle and the save state at the start,
 * then problems, تجربة / النسخ, the ⋯ menu, حفظ المسودة (only with unsaved edits) and the one primary نشر.
 */
const props = defineProps<{
    flows: FlowListRow[];
    selectedId: number | null;
    /** false before a flow has loaded: only the switcher shows */
    ready: boolean;
    activeDisabled: boolean;
    dirty: boolean;
    saving: boolean;
    hasDraft: boolean;
    stale: boolean;
    /** "آخر حفظ …" for the status tooltip */
    savedLabel: string | null;
    problems: ToolbarProblem[];
    errorCount: number;
    canPublish: boolean;
    publishing: boolean;
    /** why نشر is off, shown on its tooltip */
    publishBlockedErrors: string[];
    canAddToMenu: boolean;
    drawer: DrawerTab | null;
}>();

const active = defineModel<boolean>('active', { required: true });

const emit = defineEmits<{
    select: [id: number];
    create: [];
    save: [];
    discard: [];
    publish: [];
    autoLayout: [];
    addToMenu: [];
    rename: [];
    toggleDrawer: [tab: DrawerTab];
    focusProblem: [stepId: string];
}>();

const { t } = useI18n();

const status = computed(() => {
    if (props.saving) return { tone: 'busy', text: t('flows.workspace.saving') } as const;
    if (props.dirty) return { tone: 'dirty', text: t('flows.unsaved') } as const;
    if (props.hasDraft) return { tone: 'draft', text: t('flows.workspace.draft_unpublished') } as const;
    return { tone: 'clean', text: t('flows.no_changes') } as const;
});

const warningCount = computed(() => props.problems.length - props.errorCount);

const iconBtn =
    'inline-flex h-9 items-center justify-center gap-1.5 rounded-md px-2.5 text-sm font-medium text-foreground/80 outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50';
</script>

<template>
    <header class="flex min-h-14 flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-border bg-card px-2 py-2 md:flex-nowrap md:px-3">
        <!-- Start: which flow, is it live, is it saved. -->
        <div class="flex min-w-0 basis-full items-center gap-2 md:basis-auto">
            <FlowSwitcher :flows="flows" :selected-id="selectedId" @select="emit('select', $event)" @create="emit('create')" />

            <template v-if="ready">
                <span class="h-6 w-px shrink-0 bg-border" aria-hidden="true" />
                <span class="flex shrink-0 items-center gap-2 px-1.5 py-1 text-xs font-medium">
                    <ToggleSwitch v-model="active" :label="t('flows.active_toggle')" :disabled="activeDisabled" />
                    <span class="hidden sm:inline" :class="active ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground'">
                        {{ active ? t('flows.active') : t('flows.inactive') }}
                    </span>
                </span>
            </template>
        </div>

        <template v-if="ready">
            <Tooltip>
                <TooltipTrigger as-child>
                    <p
                        class="hidden min-w-0 shrink items-center gap-1.5 truncate rounded-full px-2.5 py-1 text-xs lg:flex"
                        :class="{
                            'text-muted-foreground': status.tone === 'clean' || status.tone === 'busy',
                            'bg-amber-500/10 font-medium text-amber-800 dark:text-amber-200': status.tone === 'dirty',
                            'bg-surface-accent font-medium text-primary': status.tone === 'draft',
                        }"
                        tabindex="0"
                        aria-live="polite"
                    >
                        <LoaderCircle v-if="status.tone === 'busy'" class="size-3.5 shrink-0 animate-spin" aria-hidden="true" />
                        <CircleCheck v-else-if="status.tone === 'clean'" class="size-3.5 shrink-0 text-emerald-600" aria-hidden="true" />
                        <span
                            v-else
                            class="size-2 shrink-0 rounded-full"
                            :class="status.tone === 'dirty' ? 'bg-amber-500' : 'bg-primary'"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ status.text }}</span>
                    </p>
                </TooltipTrigger>
                <TooltipContent v-if="savedLabel" side="bottom" class="text-xs">{{ savedLabel }}</TooltipContent>
            </Tooltip>

            <div class="ms-auto flex shrink-0 items-center gap-1">
                <!-- Problems: one badge, the list opens from it and each row jumps to its step. -->
                <DropdownMenu v-if="problems.length">
                    <DropdownMenuTrigger
                        class="inline-flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-semibold outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring"
                        :class="
                            errorCount
                                ? 'bg-destructive/10 text-destructive hover:bg-destructive/15'
                                : 'bg-amber-500/15 text-amber-800 hover:bg-amber-500/25 dark:text-amber-200'
                        "
                        :title="t('flows.workspace.problems_title')"
                    >
                        <template v-if="errorCount">
                            <CircleAlert class="size-3.5" aria-hidden="true" />{{ t('flows.errors_count', { n: errorCount }) }}
                        </template>
                        <template v-if="warningCount">
                            <TriangleAlert class="size-3.5" :class="errorCount ? 'ms-1 text-amber-600' : ''" aria-hidden="true" />
                            <span :class="errorCount ? 'text-amber-700 dark:text-amber-300' : ''">{{
                                t('flows.warnings_count', { n: warningCount })
                            }}</span>
                        </template>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-80 p-1.5">
                        <DropdownMenuLabel class="px-2 pb-1 pt-0.5 text-xs font-semibold">{{
                            t('flows.workspace.problems_title')
                        }}</DropdownMenuLabel>
                        <div class="scrollbar-thin max-h-[min(60svh,24rem)] space-y-1 overflow-y-auto">
                            <DropdownMenuItem
                                v-for="(problem, i) in problems"
                                :key="i"
                                class="cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-xs"
                                :class="
                                    problem.tone === 'error'
                                        ? 'text-destructive focus:bg-destructive/10 focus:text-destructive'
                                        : 'text-amber-800 focus:bg-amber-500/10 focus:text-amber-900 dark:text-amber-200 dark:focus:text-amber-100'
                                "
                                :disabled="!problem.stepId"
                                @select="problem.stepId && emit('focusProblem', problem.stepId)"
                            >
                                <component :is="problem.tone === 'error' ? CircleAlert : TriangleAlert" class="mt-0.5 !size-3.5" aria-hidden="true" />
                                <span class="min-w-0 flex-1 leading-5">{{ problem.text }}</span>
                                <span v-if="problem.stepId" class="shrink-0 rounded bg-muted px-1 text-2xs text-muted-foreground" dir="ltr">{{
                                    problem.stepId
                                }}</span>
                            </DropdownMenuItem>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <span v-if="problems.length" class="mx-1 h-6 w-px bg-border" aria-hidden="true" />

                <button
                    v-for="tab in [
                        { id: 'test', label: t('flows.test'), icon: FlaskConical },
                        { id: 'versions', label: t('flows.versions'), icon: History },
                    ] as const"
                    :key="tab.id"
                    type="button"
                    :class="[iconBtn, drawer === tab.id ? 'bg-surface-accent text-primary hover:bg-surface-accent hover:text-primary' : '']"
                    :aria-pressed="drawer === tab.id"
                    :title="tab.label"
                    @click="emit('toggleDrawer', tab.id)"
                >
                    <component :is="tab.icon" class="size-4" aria-hidden="true" />
                    <span class="hidden xl:inline">{{ tab.label }}</span>
                    <span class="sr-only xl:hidden">{{ tab.label }}</span>
                </button>

                <DropdownMenu>
                    <DropdownMenuTrigger
                        :class="[iconBtn, 'data-[state=open]:bg-muted']"
                        :aria-label="t('flows.workspace.more_actions')"
                        :title="t('flows.workspace.more_actions')"
                    >
                        <Ellipsis class="size-4" aria-hidden="true" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <DropdownMenuItem class="cursor-pointer" @select="emit('rename')">
                            <PencilLine aria-hidden="true" />{{ t('flows.workspace.rename') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem class="cursor-pointer" @select="emit('autoLayout')">
                            <LayoutDashboard aria-hidden="true" />{{ t('flows.auto_layout') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem class="cursor-pointer" :disabled="!canAddToMenu" @select="emit('addToMenu')">
                            <ListPlus aria-hidden="true" />{{ t('flows.add_to_menu') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="cursor-pointer text-destructive focus:bg-destructive/10 focus:text-destructive"
                            :disabled="!hasDraft && !dirty"
                            @select="emit('discard')"
                        >
                            <Undo2 aria-hidden="true" />{{ t('flows.discard_draft') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <button
                    v-if="dirty || saving"
                    type="button"
                    class="ms-1 inline-flex h-9 items-center gap-1.5 rounded-md border border-input bg-card px-3 text-sm font-medium outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
                    :disabled="saving || stale"
                    @click="emit('save')"
                >
                    <LoaderCircle v-if="saving" class="size-4 animate-spin" aria-hidden="true" />
                    <Save v-else class="size-4" aria-hidden="true" />
                    <span class="hidden sm:inline">{{ t('flows.save_draft') }}</span>
                    <span class="sr-only sm:hidden">{{ t('flows.save_draft') }}</span>
                </button>

                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="ms-1 inline-flex" :tabindex="canPublish ? undefined : 0">
                            <button
                                type="button"
                                class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-card outline-none transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:pointer-events-none disabled:opacity-50"
                                :disabled="!canPublish || publishing"
                                @click="emit('publish')"
                            >
                                <LoaderCircle v-if="publishing" class="size-4 animate-spin" aria-hidden="true" />
                                <Send v-else class="size-4 rtl:-scale-x-100" aria-hidden="true" />{{ t('flows.publish') }}
                            </button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent v-if="!canPublish" side="bottom" align="end" class="max-w-xs text-xs">
                        <template v-if="publishBlockedErrors.length">
                            <p class="mb-1 font-semibold">{{ t('flows.publish_blocked') }}</p>
                            <ul class="list-disc space-y-0.5 ps-4">
                                <li v-for="(message, i) in publishBlockedErrors.slice(0, 6)" :key="i">{{ message }}</li>
                                <li v-if="publishBlockedErrors.length > 6">…</li>
                            </ul>
                        </template>
                        <p v-else-if="stale">{{ t('flows.stale_draft') }}</p>
                        <p v-else>{{ t('flows.nothing_to_publish') }}</p>
                    </TooltipContent>
                </Tooltip>
            </div>
        </template>
    </header>
</template>
