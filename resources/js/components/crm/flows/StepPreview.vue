<script setup lang="ts">
import MessageCards from '@/components/crm/MessageCards.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import { MAX_QUICK_REPLIES } from '@/lib/flows/flowGraph';
import { previewStep } from '@/lib/flows/stepPreview';
import type { FlowDefinition, FlowListRow, FlowScriptOption, FlowStep } from '@/types/flows';
import { CircleStop, Eye, Headset, TriangleAlert, Truck } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import MessengerBubble, { MESSENGER_CHIP_CLASS } from './MessengerBubble.vue';

/**
 * "شكلها عند العميل" (design 2026-09-18 §5): the selected step as the customer sees it in
 * Messenger. `step` already carries any inline edit in progress, so it updates as the owner types.
 */
const props = defineProps<{
    step: FlowStep;
    def: FlowDefinition;
    scripts: FlowScriptOption[];
    flows: FlowListRow[];
}>();

const { t } = useI18n();

const model = computed(() => previewStep(props.step, props.def, props.scripts, props.flows));
const overLimit = computed(() => model.value.chips.filter((c) => c.overLimit).length);

const NOTE_ICONS: Record<string, Component> = { status: Truck, handover: Headset, end: CircleStop, branches_list: Eye };

function chipTooltip(chip: { full: string; truncated: boolean; hidden: string | null; overLimit: boolean }): string | null {
    if (chip.hidden) return t('flows.preview.hidden', { reason: t(`flows.preview.reason_${chip.hidden}`) });
    if (chip.overLimit) return t('flows.preview.over_limit', { n: MAX_QUICK_REPLIES });
    if (chip.truncated) return t('flows.preview.truncated', { title: chip.full });
    return null;
}
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border" :aria-label="t('flows.preview.title')">
        <h3 class="flex items-center gap-1.5 border-b border-border bg-muted/50 px-3 py-1.5 text-2xs font-semibold text-muted-foreground">
            <Eye class="size-3.5" aria-hidden="true" />{{ t('flows.preview.title') }}
        </h3>

        <div class="space-y-2 bg-muted/30 px-3 py-3" aria-live="polite">
            <MessengerBubble v-if="model.text || model.chips.length" :text="model.text">
                <div v-if="model.chips.length" class="flex flex-wrap gap-1.5">
                    <template v-for="(chip, i) in model.chips" :key="i">
                        <Tooltip v-if="chipTooltip(chip)">
                            <TooltipTrigger as-child>
                                <span
                                    tabindex="0"
                                    dir="auto"
                                    :class="[
                                        MESSENGER_CHIP_CLASS,
                                        'cursor-default',
                                        chip.hidden || chip.overLimit ? 'border-dashed opacity-45 line-through decoration-1' : '',
                                    ]"
                                    >{{ chip.title || '—' }}</span
                                >
                            </TooltipTrigger>
                            <TooltipContent side="bottom" class="max-w-xs text-xs">{{ chipTooltip(chip) }}</TooltipContent>
                        </Tooltip>
                        <span v-else dir="auto" :class="[MESSENGER_CHIP_CLASS, 'cursor-default']">{{ chip.title || '—' }}</span>
                    </template>
                </div>
            </MessengerBubble>

            <!-- The contact step's other message: asked instead when her name and mobile are not known. -->
            <MessengerBubble v-if="model.alternative" :text="model.alternative" />

            <!-- Sample branch cards, as Messenger shows them; other channels get their own form. -->
            <template v-if="model.cards">
                <MessengerBubble>
                    <MessageCards :cards="model.cards" variant="messenger" class="max-w-full" />
                </MessengerBubble>
                <p class="text-center text-2xs text-muted-foreground">{{ t('flows.preview.cards_hint') }}</p>
            </template>

            <p v-if="model.scriptMissing" class="flex items-start gap-1.5 rounded-md bg-amber-500/10 px-2.5 py-1.5 text-2xs text-amber-800 dark:text-amber-200">
                <TriangleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ t('flows.preview.script_missing') }}
            </p>

            <p v-if="overLimit" class="text-2xs text-amber-700 dark:text-amber-300">
                {{ t('flows.preview.over_limit_count', { n: overLimit, max: MAX_QUICK_REPLIES }) }}
            </p>

            <MessengerBubble v-if="model.expects" side="customer" dashed :text="t(`flows.preview.expects_${model.expects}`)" />

            <div v-if="model.note" class="flex justify-center py-0.5">
                <span class="inline-flex max-w-[92%] items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-center text-2xs font-medium text-muted-foreground">
                    <component :is="NOTE_ICONS[model.note]" class="size-3.5 shrink-0" aria-hidden="true" />{{ t(`flows.preview.note_${model.note}`) }}
                </span>
            </div>

            <p v-if="!model.text && !model.chips.length && !model.note && !model.scriptMissing" class="text-center text-2xs italic text-muted-foreground">
                {{ t('flows.preview.empty') }}
            </p>
        </div>
    </section>
</template>
