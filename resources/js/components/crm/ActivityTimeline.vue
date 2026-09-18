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
    <ol class="divide-y divide-border rounded-lg bg-card shadow-card">
        <li v-if="!logs.length" class="px-3 py-8 text-center text-xs text-muted-foreground">{{ empty ?? t('activity.ui.empty') }}</li>
        <li v-for="log in logs" :key="log.id" class="flex items-start gap-2.5 px-3 py-2 text-xs">
            <span
                class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                :style="log.user?.color ? { backgroundColor: `${log.user.color}22`, color: log.user.color } : undefined"
                aria-hidden="true"
            >
                <component :is="icon(log)" class="size-3.5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-foreground" dir="auto">{{ activitySentence(log, locale, page.props.platforms ?? []) }}</p>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-2xs text-muted-foreground">
                    <time class="tabular-nums" :datetime="log.created_at ?? undefined">{{ formatDateTime(log.created_at, locale) }}</time>
                    <PlatformBadge v-if="log.platform" :platform="log.platform" size="xs" />
                    <Link v-if="log.conversation_id" :href="`/inbox?c=${log.conversation_id}`" class="text-primary hover:underline">{{ t('ui.open_conversation') }}</Link>
                    <Link v-if="activityOrderId(log)" :href="`/orders/${activityOrderId(log)}`" class="text-primary hover:underline">{{ t('activity.ui.open_order') }}</Link>
                </p>
            </div>
        </li>
    </ol>
</template>
