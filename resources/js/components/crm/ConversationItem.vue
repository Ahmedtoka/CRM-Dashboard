<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import type { RowState } from '@/lib/conversationState';
import { formatCount } from '@/lib/format';
import type { Conversation } from '@/types/crm';
import { computed } from 'vue';

/**
 * One inbox row, exactly 72 px (the list virtualises on that height): avatar + platform,
 * name · tag dots · time, then the one state badge · preview · unread pill (spec §1.2).
 */
const props = withDefaults(defineProps<{ conversation: Conversation; state: RowState | null; active?: boolean }>(), { active: false });
const emit = defineEmits<{ select: [id: number]; contextmenu: [id: number, event: MouseEvent] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const unread = computed(() => props.conversation.unread_count > 0);
// A moderator's reply is the preview: «إنتي: » in front (the bot's and the system's lines carry no prefix).
const ours = computed(() => !!props.conversation.last_message_preview && props.conversation.last_message_sender === 'user');

const tags = computed(() => props.conversation.tags ?? []);
const tagTitle = computed(() => tags.value.map((tag) => tag.name).join('، '));
const dotColor = (color: string | null) => (color && /^#[0-9a-f]{6}$/i.test(color) ? color : '#64748b');
</script>

<template>
    <button
        type="button"
        :data-conversation-id="conversation.id"
        :aria-current="active ? 'true' : undefined"
        class="flex h-[72px] w-full items-center gap-3 px-3 text-start outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        :class="active ? 'bg-surface-accent' : 'hover:bg-muted'"
        @click="emit('select', conversation.id)"
        @contextmenu.prevent="emit('contextmenu', conversation.id, $event)"
    >
        <span class="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-elevated text-xs font-semibold text-muted-foreground">
            {{ getInitials(name) }}
            <span class="absolute -bottom-1 -end-1">
                <PlatformBadge :platform="conversation.platform" size="xs" />
            </span>
        </span>

        <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="flex min-w-0 items-center gap-1.5">
                <span class="min-w-0 truncate text-sm" :class="unread ? 'font-bold text-foreground' : 'font-medium text-foreground/90'" dir="auto">{{ name }}</span>
                <span v-if="tags.length" class="flex shrink-0 items-center gap-0.5" :title="tagTitle" role="img" :aria-label="tagTitle">
                    <span v-for="tag in tags.slice(0, 2)" :key="tag.id" class="size-2 rounded-full" :style="{ backgroundColor: dotColor(tag.color) }" />
                    <span v-if="tags.length > 2" class="text-2xs leading-none text-muted-foreground tabular-nums">+{{ formatCount(tags.length - 2, locale) }}</span>
                </span>
                <RelativeTime
                    :iso="conversation.last_message_at"
                    mode="stamp"
                    class="ms-auto shrink-0 text-2xs"
                    :class="unread ? 'font-semibold text-primary' : 'text-muted-foreground'"
                />
            </span>

            <span class="flex min-w-0 items-center gap-1.5">
                <span
                    v-if="state?.key === 'test'"
                    class="inline-flex h-5 shrink-0 items-center rounded-full bg-violet-500/12 px-2 text-2xs font-medium text-violet-700 dark:bg-violet-400/20 dark:text-violet-200"
                >
                    {{ state.label }}
                </span>
                <StatusChip v-else-if="state" :label="state.label" :tone="state.tone" />
                <span class="min-w-0 flex-1 truncate text-xs" :class="unread ? 'font-semibold text-foreground' : 'text-muted-foreground'">
                    <span v-if="ours" class="text-muted-foreground">{{ t('inbox.you_prefix') }}</span>
                    <span dir="auto">{{ conversation.last_message_preview || '—' }}</span>
                </span>
                <span
                    v-if="unread"
                    class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-2xs font-semibold text-primary-foreground tabular-nums"
                    :aria-label="t('inbox.unread', { n: conversation.unread_count })"
                >
                    {{ formatCount(conversation.unread_count, locale) }}
                </span>
            </span>
        </span>
    </button>
</template>
