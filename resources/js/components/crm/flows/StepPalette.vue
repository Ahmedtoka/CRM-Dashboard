<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import { PALETTE_CARDS, PALETTE_DRAG_TYPE } from '@/lib/flows/stepPalette';
import { stepColor, stepIcon } from '@/lib/flows/stepVisuals';
import type { StepTypeCatalog } from '@/types/flows';
import { PanelRightClose, PanelRightOpen } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Ready-made steps (design 2026-09-18 §4), docked as a rail on the canvas's start edge: icons only
 * when collapsed (the name shows on hover and on keyboard focus), names and descriptions when expanded.
 * Drag a step onto the canvas, or click it to add it below the selected step.
 * Icons and colours come from FlowStepCatalog, like the nodes.
 */
const props = defineProps<{ catalog: StepTypeCatalog; selectedStepId: string | null; disabled?: boolean }>();
const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{ add: [type: string] }>();

const { t, dir } = useI18n();

/** Tooltips open toward the canvas, away from the edge the rail is docked on. */
const side = computed(() => (dir.value === 'rtl' ? 'left' : 'right'));

const cards = computed(() => PALETTE_CARDS.filter((card) => props.catalog[card.type]));

const hint = computed(() => (props.selectedStepId ? t('flows.palette.hint_selected', { step: props.selectedStepId }) : t('flows.palette.hint')));

function onDragStart(event: DragEvent, type: string): void {
    if (!event.dataTransfer) return;
    event.dataTransfer.setData(PALETTE_DRAG_TYPE, type);
    // Firefox only starts a drag when some plain data is set.
    event.dataTransfer.setData('text/plain', type);
    event.dataTransfer.effectAllowed = 'copy';
}
</script>

<template>
    <aside
        class="relative z-10 flex shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200 ease-out motion-reduce:transition-none"
        :class="open ? 'w-60' : 'w-14'"
        :aria-label="t('flows.palette.title')"
    >
        <div class="flex h-11 items-center gap-1.5 border-b border-border px-2" :class="open ? '' : 'justify-center'">
            <span v-if="open" class="flex-1 truncate ps-1 text-xs font-semibold">{{ t('flows.palette.title') }}</span>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-label="open ? t('flows.palette.collapse') : t('flows.workspace.palette_expand')"
                        :aria-expanded="open"
                        @click="open = !open"
                    >
                        <!-- The rail sits on the start edge, so "close" points toward it in both directions. -->
                        <component :is="open ? PanelRightOpen : PanelRightClose" class="size-4 ltr:-scale-x-100" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent :side="side" class="text-xs">{{
                    open ? t('flows.palette.collapse') : t('flows.workspace.palette_expand')
                }}</TooltipContent>
            </Tooltip>
        </div>

        <p v-if="open" class="border-b border-border px-3 py-2 text-2xs leading-4 text-muted-foreground">{{ hint }}</p>

        <ul
            class="scrollbar-thin min-h-0 flex-1 space-y-0.5 overflow-y-auto overflow-x-hidden p-1.5"
            :class="disabled ? 'pointer-events-none opacity-50' : ''"
        >
            <li v-for="card in cards" :key="card.key">
                <Tooltip :disabled="open">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            draggable="true"
                            class="group flex w-full cursor-grab items-center gap-2.5 rounded-lg p-1.5 text-start outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
                            :class="open ? '' : 'justify-center'"
                            :aria-label="open ? undefined : t(`flows.palette.cards.${card.key}.name`)"
                            :disabled="disabled"
                            @dragstart="onDragStart($event, card.type)"
                            @click="emit('add', card.type)"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                :class="stepColor(catalog[card.type].color).tile"
                            >
                                <component :is="stepIcon(catalog[card.type].icon)" class="size-4" aria-hidden="true" />
                            </span>
                            <span v-if="open" class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-semibold">{{ t(`flows.palette.cards.${card.key}.name`) }}</span>
                                <span class="block truncate text-2xs text-muted-foreground">{{ t(`flows.palette.cards.${card.key}.desc`) }}</span>
                            </span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent :side="side" class="max-w-56 text-xs">
                        <p class="font-semibold">{{ t(`flows.palette.cards.${card.key}.name`) }}</p>
                        <p class="text-muted-foreground">{{ t(`flows.palette.cards.${card.key}.desc`) }}</p>
                    </TooltipContent>
                </Tooltip>
            </li>
        </ul>
    </aside>
</template>
