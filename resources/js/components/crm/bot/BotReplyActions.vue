<script setup lang="ts">
import BotFeedbackForm from '@/components/crm/bot/BotFeedbackForm.vue';
import BotTraceView from '@/components/crm/bot/BotTraceView.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import type { BotTrace, MyFeedback } from '@/types/botBrain';
import { Link, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { ChevronLeft, Flag, Info, SearchX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * The two actions on a bot bubble (b2): «ليه البوت رد كده؟» (the turn's trace) and «رد البوت ده غلط»
 * (feedback). One sheet: from the info icon the trace comes first; from the flag the form comes
 * first and the trace waits behind «ليه رد كده؟».
 */
const props = defineProps<{ messageId: number }>();

const api = useApi();
const { t, dir } = useI18n();
const phone = useMediaQuery('(max-width: 759px)');
const page = usePage<{ auth?: { user?: { role?: string } } }>();
/** Tokens and dollars are for managers; everyone sees what happened. */
const manager = computed(() => ['admin', 'supervisor'].includes(page.props.auth?.user?.role ?? ''));

const open = ref(false);
const mode = ref<'trace' | 'feedback'>('trace');
const loading = ref(false);
const error = ref<string | null>(null);
const trace = ref<BotTrace | null>(null);
const feedback = ref<MyFeedback | null>(null);
const loaded = ref(false);

async function show(which: 'trace' | 'feedback'): Promise<void> {
    mode.value = which;
    open.value = true;
    if (loaded.value) return;
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

// A 24px icon with a 40px hit area (DESIGN §2.3 touch targets) via an invisible inset.
const iconButton =
    'relative inline-flex size-6 items-center justify-center rounded before:absolute before:-inset-2 before:content-[""] hover:bg-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <span class="ms-auto inline-flex items-center gap-2" data-testid="bot-reply-actions">
        <button
            type="button"
            :class="[iconButton, 'text-fg-3 hover:text-foreground']"
            :aria-label="t('bot_brain.trace.open')"
            :title="t('bot_brain.trace.open')"
            data-action="trace"
            @click="show('trace')"
        >
            <Info class="size-3.5" aria-hidden="true" />
        </button>
        <button
            type="button"
            :class="[iconButton, feedback ? 'text-brand' : 'text-fg-3 hover:text-foreground']"
            :aria-label="feedback ? t('bot_brain.feedback.already') : t('bot_brain.feedback.button')"
            :aria-pressed="!!feedback"
            :title="feedback ? t('bot_brain.feedback.already') : t('bot_brain.feedback.button')"
            data-action="feedback"
            @click="show('feedback')"
        >
            <Flag class="size-3.5" :class="feedback && 'fill-current'" aria-hidden="true" />
        </button>

        <Sheet v-model:open="open">
            <SheetContent
                :side="phone ? 'bottom' : dir === 'rtl' ? 'left' : 'right'"
                class="flex w-full flex-col gap-0 p-0"
                :class="phone ? 'max-h-[90dvh] rounded-t-xl' : 'sm:max-w-[440px]'"
            >
                <SheetHeader class="space-y-1 border-b border-line px-5 pb-3.5 pt-4 text-start">
                    <SheetTitle class="text-lg font-bold">{{
                        mode === 'feedback' ? t('bot_brain.feedback.title') : t('bot_brain.trace.title')
                    }}</SheetTitle>
                    <SheetDescription class="text-13">
                        <Link href="/bot/brain" class="inline-flex items-center gap-0.5 text-brand underline-offset-2 hover:underline"
                            >{{ t('bot_brain.trace.about_brain') }}<ChevronLeft class="size-3.5 ltr:rotate-180" aria-hidden="true"
                        /></Link>
                    </SheetDescription>
                </SheetHeader>

                <div class="flex-1 space-y-6 overflow-y-auto px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    <div v-if="loading" class="space-y-3" aria-busy="true" :aria-label="t('ui.loading')">
                        <Skeleton class="h-10 w-full" />
                        <Skeleton class="h-16 w-full" />
                        <Skeleton class="h-24 w-full" />
                    </div>
                    <EmptyState v-else-if="error" variant="error" :title="error" />
                    <template v-else-if="loaded && mode === 'trace'">
                        <BotTraceView v-if="trace" :trace="trace" :show-cost="manager" />
                        <EmptyState v-else :icon="SearchX" :title="t('bot_brain.trace.none')" />
                        <section class="space-y-2 border-t border-line pt-4">
                            <h3 class="text-base font-bold">{{ t('bot_brain.feedback.title') }}</h3>
                            <BotFeedbackForm :message-id="messageId" :existing="feedback" @saved="feedback = $event" />
                        </section>
                    </template>
                    <template v-else-if="loaded">
                        <BotFeedbackForm :message-id="messageId" :existing="feedback" @saved="feedback = $event" />
                        <details v-if="trace" class="rounded-lg border border-line" data-testid="trace-disclosure">
                            <summary class="cursor-pointer px-3 py-2.5 text-13 font-semibold text-brand">
                                {{ t('bot_brain.brain.console.why') }}
                            </summary>
                            <div class="border-t border-line p-3">
                                <BotTraceView :trace="trace" :show-cost="manager" />
                            </div>
                        </details>
                    </template>
                </div>
            </SheetContent>
        </Sheet>
    </span>
</template>
