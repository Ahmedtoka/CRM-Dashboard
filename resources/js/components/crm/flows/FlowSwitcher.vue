<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import type { FlowListRow } from '@/types/flows';
import { Check, ChevronsUpDown, Plus, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';

/** The flow picker at the start of the toolbar: the open flow's name and state, the other flows, and "فلو جديد". */
const props = defineProps<{ flows: FlowListRow[]; selectedId: number | null }>();

const emit = defineEmits<{ select: [id: number]; create: [] }>();

const { t } = useI18n();

const current = computed(() => props.flows.find((row) => row.id === props.selectedId) ?? null);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="group flex h-10 min-w-0 max-w-[20rem] items-center gap-2.5 rounded-lg px-1.5 text-start outline-none transition-colors hover:bg-sunken focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-sunken"
            :aria-label="t('flows.workspace.switch_flow')"
        >
            <span
                class="relative flex size-8 shrink-0 items-center justify-center rounded-lg"
                :class="current?.is_active ? 'bg-brand-soft text-brand-ink' : 'bg-sunken text-fg-2'"
            >
                <Workflow class="size-4" aria-hidden="true" />
                <span
                    class="absolute -bottom-0.5 -end-0.5 size-2.5 rounded-full ring-2 ring-card"
                    :class="current?.is_active ? 'bg-ok' : 'bg-muted-foreground/60'"
                    aria-hidden="true"
                />
            </span>
            <span class="min-w-0 flex-1 leading-tight">
                <span class="flex items-center gap-1.5">
                    <span class="truncate text-sm font-bold">{{ current?.title_ar ?? t('flows.choose_flow') }}</span>
                    <span v-if="current?.has_draft" class="text-12 shrink-0 rounded-full bg-warn-soft px-1.5 py-px font-semibold text-warn">{{
                        t('flows.has_draft')
                    }}</span>
                </span>
                <span v-if="current" class="text-12 block truncate text-fg-2">
                    {{ current.is_active ? t('flows.active') : t('flows.inactive') }}
                    <template v-if="current.published_version"> · {{ t('flows.version', { n: current.published_version }) }}</template>
                </span>
            </span>
            <ChevronsUpDown class="size-4 shrink-0 text-fg-2" aria-hidden="true" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-80 p-1.5">
            <DropdownMenuLabel class="text-12 px-2 pb-1 pt-0.5 font-medium text-fg-2">{{ t('flows.list') }}</DropdownMenuLabel>
            <div class="scrollbar-thin max-h-[min(60svh,26rem)] overflow-y-auto">
                <DropdownMenuItem
                    v-for="row in flows"
                    :key="row.id"
                    class="cursor-pointer items-start gap-2.5 rounded-md px-2 py-2"
                    :aria-current="row.id === selectedId ? 'true' : undefined"
                    @select="emit('select', row.id)"
                >
                    <span
                        class="mt-1.5 size-2 shrink-0 rounded-full"
                        :class="row.is_active ? 'bg-ok' : 'bg-muted-foreground/50'"
                        :title="row.is_active ? t('flows.active') : t('flows.inactive')"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold" :class="row.is_active ? '' : 'text-fg-2'">{{ row.title_ar }}</span>
                        <span class="text-12 flex items-center gap-1.5 text-fg-2">
                            <span dir="ltr" class="truncate">{{ row.key }}</span>
                            <span class="sr-only">{{ row.is_active ? t('flows.active') : t('flows.inactive') }}</span>
                            <span v-if="row.has_draft" class="shrink-0 rounded-full bg-warn-soft px-1.5 font-semibold text-warn">{{
                                t('flows.has_draft')
                            }}</span>
                        </span>
                    </span>
                    <Check v-if="row.id === selectedId" class="mt-1 text-brand-ink" aria-hidden="true" />
                </DropdownMenuItem>
                <p v-if="!flows.length" class="px-2 py-4 text-center text-xs text-fg-2">{{ t('flows.no_flows') }}</p>
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                class="cursor-pointer gap-2 rounded-md px-2 py-2 font-semibold text-brand-ink focus:text-brand-ink"
                @select="emit('create')"
            >
                <Plus aria-hidden="true" />{{ t('flows.new_flow') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
