<script setup lang="ts">
import BotFeedbackForm from '@/components/crm/bot/BotFeedbackForm.vue';
import BotTraceView from '@/components/crm/bot/BotTraceView.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import type { BotTrace, MyFeedback } from '@/types/botBrain';
import { Link } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { Flag, Info, SearchX } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';

/**
 * The two actions on a bot bubble (b2): «ليه البوت رد كده؟» (the turn's trace) and «رد البوت ده غلط»
 * (feedback). Both open one sheet; the flag scrolls it to the feedback form.
 */
const props = defineProps<{ messageId: number }>();

const api = useApi();
const { t, dir } = useI18n();
const phone = useMediaQuery('(max-width: 759px)');

const open = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);
const trace = ref<BotTrace | null>(null);
const feedback = ref<MyFeedback | null>(null);
const loaded = ref(false);
const feedbackEl = ref<HTMLElement | null>(null);

async function show(focusFeedback: boolean): Promise<void> {
    open.value = true;
    if (!loaded.value) {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await api.get<{ data: BotTrace | null; feedback: MyFeedback | null }>(`/inbox/messages/${props.messageId}/bot-trace`);
            trace.value = data.data;
            feedback.value = data.feedback;
            loaded.value = true;
        } catch (e) {
            error.value = apiErrorMessage(e, t('bot_brain.trace.failed'));
        } finally {
            loading.value = false;
        }
    }
    if (focusFeedback) {
        await nextTick();
        feedbackEl.value?.scrollIntoView?.({ block: 'start' });
    }
}
</script>

<template>
    <span class="ms-auto inline-flex items-center gap-0.5" data-testid="bot-reply-actions">
        <button
            type="button"
            class="inline-flex size-6 items-center justify-center rounded text-fg-3 hover:bg-sunken hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="t('bot_brain.trace.open')"
            :title="t('bot_brain.trace.open')"
            data-action="trace"
            @click="show(false)"
        >
            <Info class="size-3.5" aria-hidden="true" />
        </button>
        <button
            type="button"
            class="inline-flex size-6 items-center justify-center rounded hover:bg-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :class="feedback ? 'text-danger' : 'text-fg-3 hover:text-foreground'"
            :aria-label="t('bot_brain.feedback.button')"
            :title="t('bot_brain.feedback.button')"
            data-action="feedback"
            @click="show(true)"
        >
            <Flag class="size-3.5" aria-hidden="true" />
        </button>

        <Sheet v-model:open="open">
            <SheetContent
                :side="phone ? 'bottom' : dir === 'rtl' ? 'left' : 'right'"
                class="flex w-full flex-col gap-0 p-0"
                :class="phone ? 'max-h-[90dvh] rounded-t-xl' : 'sm:max-w-[440px]'"
            >
                <SheetHeader class="space-y-1 border-b border-line px-5 pb-3.5 pt-4 text-start">
                    <SheetTitle class="text-lg font-bold">{{ t('bot_brain.trace.title') }}</SheetTitle>
                    <SheetDescription class="text-13 text-fg-2">
                        <Link href="/bot/brain" class="underline-offset-2 hover:underline">{{ t('bot_brain.trace.about_brain') }}</Link>
                    </SheetDescription>
                </SheetHeader>

                <div class="flex-1 space-y-6 overflow-y-auto px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    <div v-if="loading" class="space-y-3" aria-busy="true" :aria-label="t('ui.loading')">
                        <Skeleton class="h-10 w-full" />
                        <Skeleton class="h-16 w-full" />
                        <Skeleton class="h-24 w-full" />
                    </div>
                    <EmptyState v-else-if="error" variant="error" :title="error" />
                    <template v-else-if="loaded">
                        <BotTraceView v-if="trace" :trace="trace" />
                        <EmptyState v-else :icon="SearchX" :title="t('bot_brain.trace.none')" />

                        <section ref="feedbackEl" class="space-y-2 border-t border-line pt-4">
                            <h3 class="text-base font-bold">{{ t('bot_brain.feedback.title') }}</h3>
                            <BotFeedbackForm :message-id="messageId" :existing="feedback" @saved="feedback = $event" />
                        </section>
                    </template>
                </div>
            </SheetContent>
        </Sheet>
    </span>
</template>
