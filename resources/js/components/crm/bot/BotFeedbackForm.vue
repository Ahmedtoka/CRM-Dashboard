<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { FEEDBACK_REASONS, type FeedbackReason, type MyFeedback } from '@/types/botBrain';
import { Check } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * «رد البوت ده غلط» (b2): reason chips, a note when «تاني» is picked, and an optional correct
 * reply. Sending again updates the same note.
 */
const props = defineProps<{ messageId: number; existing: MyFeedback | null }>();
const emit = defineEmits<{ saved: [feedback: MyFeedback] }>();

const api = useApi();
const { t } = useI18n();
const toast = useToast();

const reasons = ref<FeedbackReason[]>([]);
const note = ref('');
const correct = ref('');
const sending = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.existing,
    (f) => {
        reasons.value = f ? [...f.reasons] : [];
        note.value = f?.note ?? '';
        correct.value = f?.correct_reply ?? '';
    },
    { immediate: true },
);

const toggle = (r: FeedbackReason) => {
    reasons.value = reasons.value.includes(r) ? reasons.value.filter((x) => x !== r) : [...reasons.value, r];
    error.value = null;
};

const showNote = computed(() => reasons.value.includes('other') || note.value !== '');

async function send(): Promise<void> {
    if (!reasons.value.length) {
        error.value = t('bot_brain.feedback.pick_reason');
        return;
    }
    sending.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ data: MyFeedback }>(`/inbox/messages/${props.messageId}/bot-feedback`, {
            reasons: reasons.value,
            note: note.value || null,
            correct_reply: correct.value || null,
        });
        toast.push(t('bot_brain.feedback.sent'));
        emit('saved', data.data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('bot_brain.feedback.failed'));
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <form class="space-y-3" data-testid="bot-feedback-form" @submit.prevent="send">
        <p class="text-13 text-fg-2">{{ existing ? t('bot_brain.feedback.already') : t('bot_brain.feedback.hint') }}</p>
        <fieldset>
            <legend class="sr-only">{{ t('bot_brain.feedback.reasons_label') }}</legend>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="r in FEEDBACK_REASONS"
                    :key="r"
                    type="button"
                    class="inline-flex h-8 items-center gap-1 rounded-full border px-3 text-13 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    :class="
                        reasons.includes(r)
                            ? 'border-brand bg-brand-soft font-semibold text-brand-ink'
                            : 'border-line-strong bg-card text-foreground hover:bg-surface-2'
                    "
                    :aria-pressed="reasons.includes(r)"
                    :data-reason="r"
                    @click="toggle(r)"
                >
                    <Check v-if="reasons.includes(r)" class="size-3.5" aria-hidden="true" />
                    {{ t(`bot_brain.feedback.reasons.${r}`) }}
                </button>
            </div>
        </fieldset>
        <label v-if="showNote" class="block space-y-1">
            <span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.note') }}</span>
            <input
                v-model="note"
                maxlength="500"
                class="h-9 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                dir="auto"
            />
        </label>
        <label class="block space-y-1">
            <span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.correct') }}</span>
            <textarea
                v-model="correct"
                rows="3"
                maxlength="2000"
                :placeholder="t('bot_brain.feedback.correct_placeholder')"
                class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm leading-relaxed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                dir="auto"
            />
        </label>
        <p v-if="error" class="text-13 text-danger" role="alert">{{ error }}</p>
        <div class="sticky bottom-0 -mx-1 bg-card px-1 pb-1 pt-2">
            <Button type="submit" class="h-11 w-full" :disabled="sending">{{
                existing ? t('bot_brain.feedback.update') : t('bot_brain.feedback.send')
            }}</Button>
        </div>
    </form>
</template>
