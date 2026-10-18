<script setup lang="ts">
import OutcomePicker from '@/components/crm/outcomes/OutcomePicker.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useFinish } from '@/composables/inbox/useFinish';
import { useI18n } from '@/composables/useI18n';
import { outcomePayload, outcomeReady } from '@/lib/outcomes';
import type { AgentOutcome } from '@/types/crm';
import { CircleHelp } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Q6: her window the silence closed with no outcome. A small chip in the header opens the outcome
 * row; «احفظي النتيجة» posts it to the conversation (`/outcome`) and the chip goes away.
 */
const props = defineProps<{ conversationId: number }>();

const { t } = useI18n();
const cycle = useFinish();
const open = ref(false);
const picked = ref<AgentOutcome | null>(null);
const note = ref('');
const saving = ref(false);
const picker = ref<InstanceType<typeof OutcomePicker> | null>(null);
const ready = computed(() => outcomeReady(picked.value, note.value, null));

watch(open, (isOpen) => {
    if (isOpen) {
        picked.value = null;
        note.value = '';
    }
});

async function save(): Promise<void> {
    if (!ready.value || saving.value) return;
    saving.value = true;
    const ok = await cycle.amend(props.conversationId, outcomePayload(picked.value, note.value, null));
    saving.value = false;
    if (ok) open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.target instanceof HTMLInputElement) return;
    if (picker.value?.handleKey(event.key)) event.preventDefault();
}

defineExpose({ isOpen: (): boolean => open.value });
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger
            class="inline-flex h-6 shrink-0 items-center gap-1 rounded-full bg-warn-soft px-2 text-2xs font-semibold text-foreground transition-colors hover:bg-warn/25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            data-amend-outcome
        >
            <CircleHelp class="size-3 shrink-0 text-warn" aria-hidden="true" />{{ t('finish.amend.button') }}
        </PopoverTrigger>
        <PopoverContent align="start" class="w-80 p-3">
            <div class="space-y-3" @keydown="onKeydown">
                <div>
                    <p class="text-13 font-semibold">{{ t('finish.amend.title') }}</p>
                    <p class="text-xs text-fg-2">{{ t('finish.amend.hint') }}</p>
                </div>
                <OutcomePicker ref="picker" v-model="picked" v-model:note="note" variant="sheet" :label="t('outcomes.title')" />
                <Button type="button" class="h-10 w-full" :disabled="!ready" :loading="saving" data-amend-save @click="save">{{ t('finish.amend.save') }}</Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
