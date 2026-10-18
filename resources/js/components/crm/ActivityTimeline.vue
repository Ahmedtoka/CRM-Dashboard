<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { useI18n } from '@/composables/useI18n';
import { activityOrderId, activitySentence } from '@/lib/activity';
import { formatDateTime } from '@/lib/format';
import type { SharedData } from '@/types';
import type { ActivityLogItem } from '@/types/admin';
import { Link, usePage } from '@inertiajs/vue3';
import { Bot, Cog, User } from 'lucide-vue-next';

defineProps<{ logs: ActivityLogItem[]; empty?: string }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

const icon = (log: ActivityLogItem) => (log.actor_type === 'bot' ? Bot : log.user ? User : Cog);
</script>

<template>
    <ol class="divide-y divide-line rounded-lg border border-line bg-card" data-activity-timeline>
        <li v-if="!logs.length" class="px-4 py-8 text-center text-13 text-fg-2">{{ empty ?? t('activity.ui.empty') }}</li>
        <li v-for="log in logs" :key="log.id" class="flex items-start gap-3 px-4 py-3">
            <span
                class="grid size-[30px] shrink-0 place-items-center rounded-md bg-brand-soft text-brand-ink"
                :style="log.user?.color ? { backgroundColor: `${log.user.color}22`, color: log.user.color } : undefined"
                aria-hidden="true"
            >
                <component :is="icon(log)" class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[13.5px] leading-relaxed text-foreground">{{ activitySentence(log, locale, page.props.platforms ?? []) }}</p>
                <p
                    v-if="log.platform || log.conversation_id || activityOrderId(log)"
                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-2"
                >
                    <PlatformBadge v-if="log.platform" :platform="log.platform" size="xs" />
                    <Link v-if="log.conversation_id" :href="`/inbox?c=${log.conversation_id}`" class="font-semibold text-brand-ink hover:underline">{{
                        t('ui.open_conversation')
                    }}</Link>
                    <Link
                        v-if="activityOrderId(log)"
                        :href="`/orders/${activityOrderId(log)}`"
                        class="font-semibold text-brand-ink hover:underline"
                        >{{ t('activity.ui.open_order') }}</Link
                    >
                </p>
            </div>
            <time class="shrink-0 whitespace-nowrap pt-0.5 text-xs tabular-nums text-fg-3" :datetime="log.created_at ?? undefined">{{
                formatDateTime(log.created_at, locale)
            }}</time>
        </li>
    </ol>
</template>
