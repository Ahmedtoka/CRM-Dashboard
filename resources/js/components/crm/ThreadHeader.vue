<script setup lang="ts">
import HandlerAvatar from '@/components/crm/HandlerAvatar.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import PresenceBar from '@/components/crm/PresenceBar.vue';
import CloseWindowMenu from '@/components/crm/queue/CloseWindowMenu.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { skinClasses, type ChatSkin } from '@/composables/inbox/useChatSkin';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { useInitials } from '@/composables/useInitials';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { shortcutHint } from '@/composables/useShortcuts';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Conversation, ConversationAction, ConversationPriority, Tag, UserRef } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { Bot, CheckCircle2, ChevronLeft, Eraser, Hand, LoaderCircle, Lock, Megaphone, RotateCcw, ShieldAlert, Star, Tags, UserRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{ conversation: Conversation; viewers: UserRef[]; meId: number; typing: string[]; tags: Tag[]; busyAction: string | null; skin?: ChatSkin }>(),
    { skin: 'suite' },
);
const emit = defineEmits<{
    back: [];
    openCustomer: [];
    action: [name: ConversationAction];
    priority: [value: ConversationPriority];
    toggleTag: [id: number];
    claim: [];
}>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const selectedTags = computed(() => new Set((props.conversation.tags ?? []).map((tag) => tag.id)));
const busy = computed(() => props.busyAction !== null);
const headerBg = computed(() => skinClasses(props.skin).header);
const ghostIcon = cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'rounded-full');

const adTooltip = computed(() => {
    const ad = props.conversation.ad;
    if (!ad) return '';
    return [ad.campaign && `${t('thread.ad_campaign')}: ${ad.campaign}`, ad.adset && `${t('thread.ad_adset')}: ${ad.adset}`, ad.name && `${t('thread.ad_name')}: ${ad.name}`, ad.ref && `ref: ${ad.ref}`]
        .filter(Boolean)
        .join('\n');
});

// Handover queue: an open window is closed with a reason (or handed to the shift leader), and
// returned to the bot through the same reasons, by its moderator or a supervisor / admin only.
// For anybody else «حل» and «رجوع للبوت» are hidden (the server refuses them too); the chat
// stays open to her for reading, replying and notes. With the queue off nothing changes.
const queue = useMyQueueContext();
const page = usePage<SharedData>();
const closeMenu = ref<InstanceType<typeof CloseWindowMenu> | null>(null);
const openWindow = computed(() => (queue?.enabled.value ? props.conversation.queue_entry : null));
const queueWindow = computed(() => {
    const entry = openWindow.value;
    if (!entry) return null;
    const role = page.props.auth.user?.role;

    return entry.assigned_user_id === props.meId || role === 'supervisor' || role === 'admin' ? entry : null;
});
/** Somebody else's open window: she may read and reply, not end it. */
const heldByOther = computed(() => openWindow.value !== null && queueWindow.value === null);
const holderName = computed(() => props.conversation.assignee?.name ?? '');
const windowOwner = computed(() => (queueWindow.value && queueWindow.value.assigned_user_id !== props.meId ? (props.conversation.assignee?.name ?? null) : null));

const tagsOpen = ref(false);
const hint = (id: string) => {
    const key = shortcutHint(id);
    return key ? ` (${key})` : '';
};

function confirmReset(): void {
    if (window.confirm(t('thread.reset_confirm'))) emit('action', 'reset');
}

defineExpose({
    openTags: () => (tagsOpen.value = true),
    /**
     * «حل» / «رجوع للبوت» on a queue window: `menu` when the reasons were opened (her window, or a
     * supervisor), `blocked` when the window is somebody else's, `none` when there is no open
     * window (the plain action applies).
     */
    openCloseWindow: (mode: 'close' | 'bot' = 'close'): 'menu' | 'blocked' | 'none' => {
        if (heldByOther.value) return 'blocked';
        if (!queueWindow.value) return 'none';
        closeMenu.value?.open(mode);

        return 'menu';
    },
    /** Who holds the open window this user may not end ('' when none). */
    holder: (): string => (heldByOther.value ? holderName.value : ''),
    /** The close menu or one of its dialogs is open. */
    closeMenuOpen: (): boolean => closeMenu.value?.isOpen() ?? false,
});
</script>

<template>
    <header class="flex h-14 items-center gap-2 border-b px-4 shadow-card" :class="headerBg">
        <button type="button" :class="cn(ghostIcon, 'size-8 md:hidden')" :aria-label="t('inbox.back')" @click="emit('back')">
            <ChevronLeft class="rtl-flip" />
        </button>

        <span class="relative hidden size-9 shrink-0 items-center justify-center rounded-full bg-elevated text-xs font-semibold text-muted-foreground sm:flex" aria-hidden="true">
            {{ getInitials(name) }}
            <span class="absolute -bottom-1 -end-1">
                <PlatformBadge :platform="conversation.platform" size="xs" />
            </span>
        </span>

        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 items-center gap-2">
                <h2 class="truncate text-sm font-bold">{{ name }}</h2>
                <PlatformBadge :platform="conversation.platform" show-label size="xs" />
                <!-- A run of a public team test link: the reply is read by a teammate, not a customer. -->
                <span
                    v-if="conversation.is_test"
                    class="inline-flex h-5 shrink-0 items-center rounded-full bg-violet-500/12 px-2 text-2xs font-semibold text-violet-600 dark:text-violet-300"
                >
                    {{ t('inbox.test_badge') }}
                </span>
                <StatusChip v-if="conversation.priority_level === 'high'" :label="t('inbox.priority_level.high')" tone="negative" />
                <StatusChip v-else-if="conversation.priority_level === 'medium'" :label="t('inbox.priority_level.medium')" tone="warning" />
                <span
                    v-if="conversation.handover_topic || conversation.handover_category_label"
                    class="hidden h-5 min-w-0 max-w-[12rem] shrink items-center rounded-full border border-border px-2 text-2xs text-muted-foreground md:inline-flex"
                    :title="
                        conversation.handover_topic
                            ? t('inbox.topic', { topic: conversation.handover_topic })
                            : t('inbox.category', { label: conversation.handover_category_label ?? '' })
                    "
                    dir="auto"
                >
                    <span class="truncate">{{ conversation.handover_topic || conversation.handover_category_label }}</span>
                </span>
                <StatusChip v-if="conversation.needs_human" :label="t('thread.needs_human')" tone="negative" />
                <StatusChip
                    v-else
                    :label="conversation.handler === 'bot' ? t('thread.handler_bot') : t('thread.handler_human')"
                    :icon="conversation.handler === 'bot' ? Bot : undefined"
                    :tone="conversation.handler === 'bot' ? 'info' : 'neutral'"
                />
                <StatusChip v-if="conversation.priority === 'spam'" :label="t('thread.priority_spam')" tone="negative" />
                <StatusChip v-else-if="conversation.priority === 'low'" :label="t('thread.priority_low')" tone="neutral" />
                <!-- Which ad she came from (2026-09-25): the ad's title, the campaign on hover. -->
                <span
                    v-if="conversation.ad"
                    class="hidden max-w-56 shrink-0 truncate rounded-full bg-amber-500/15 px-2 py-0.5 text-2xs text-amber-800 lg:inline dark:text-amber-200"
                    :title="adTooltip"
                    dir="auto"
                >
                    <Megaphone class="me-1 inline size-3 align-[-2px]" aria-hidden="true" />{{ conversation.ad.title || conversation.ad.name || (conversation.ad.ref ? t('thread.source_link') : t('thread.source_ad')) }}
                </span>
                <span
                    v-else-if="conversation.source === 'comment' || conversation.source === 'ad'"
                    class="hidden shrink-0 rounded-full bg-elevated px-2 py-0.5 text-2xs text-muted-foreground lg:inline"
                >
                    {{ t(`thread.source_${conversation.source}`) }}
                </span>
                <StatusChip v-if="conversation.status === 'resolved'" :label="t('inbox.status.resolved')" tone="positive" />
            </div>
            <div class="mt-0.5 flex items-center gap-2">
                <PresenceBar :viewers="viewers" :me-id="meId" :typing="typing" />
                <HandlerAvatar :handling="conversation.handling" :viewers="viewers" :me-id="meId" size="sm" />
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <button
                v-if="conversation.handling?.id !== meId && conversation.status !== 'resolved' && conversation.can.reply"
                type="button"
                :title="t('handling.claim')"
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-9 gap-1.5 rounded-full px-2.5')"
                :disabled="busy"
                :aria-label="t('handling.claim')"
                @click="emit('claim')"
            >
                <LoaderCircle v-if="busyAction === 'claim'" class="animate-spin" />
                <Hand v-else />
                <span class="hidden lg:inline">{{ t('handling.claim') }}</span>
            </button>

            <DropdownMenu v-model:open="tagsOpen">
                <DropdownMenuTrigger
                    :title="`${t('thread.tags')}${hint('inbox.tags')}`"
                    :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-9 gap-1.5 rounded-full px-2')"
                    :aria-label="t('thread.tags')"
                >
                    <Tags />
                    <span v-if="conversation.tags?.length" class="text-xs tabular-nums">{{ formatCount(conversation.tags.length, locale) }}</span>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                    <DropdownMenuLabel class="text-xs">{{ t('thread.tags') }}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <p v-if="!tags.length" class="px-2 py-1.5 text-xs text-muted-foreground">{{ t('thread.no_tags') }}</p>
                    <DropdownMenuCheckboxItem
                        v-for="tag in tags"
                        :key="tag.id"
                        :checked="selectedTags.has(tag.id)"
                        class="text-xs"
                        @select.prevent="emit('toggleTag', tag.id)"
                    >
                        <span class="me-2 size-2 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }" />{{ tag.name }}
                    </DropdownMenuCheckboxItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <button
                v-if="conversation.priority === 'spam'"
                type="button"
                :title="t('thread.mark_not_spam')"
                :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-9 rounded-full px-2')"
                :disabled="busy"
                :aria-label="t('thread.mark_not_spam')"
                @click="emit('priority', 'normal')"
            >
                <LoaderCircle v-if="busyAction === 'priority-normal'" class="animate-spin" />
                <ShieldAlert v-else />
                <span class="hidden lg:inline">{{ t('thread.mark_not_spam') }}</span>
            </button>
            <button
                v-else-if="conversation.priority === 'low'"
                type="button"
                :title="t('thread.mark_important')"
                :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-9 rounded-full px-2')"
                :disabled="busy"
                :aria-label="t('thread.mark_important')"
                @click="emit('priority', 'normal')"
            >
                <LoaderCircle v-if="busyAction === 'priority-normal'" class="animate-spin" />
                <Star v-else />
                <span class="hidden lg:inline">{{ t('thread.mark_important') }}</span>
            </button>

            <button
                v-if="conversation.handler === 'human' && !heldByOther"
                type="button"
                :title="`${t('thread.return_to_bot')}${hint('inbox.bot')}`"
                :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-9 rounded-full px-2')"
                :disabled="busy"
                :aria-label="t('thread.return_to_bot')"
                @click="emit('action', 'return-to-bot')"
            >
                <LoaderCircle v-if="busyAction === 'return-to-bot'" class="animate-spin" />
                <Bot v-else />
                <span class="hidden lg:inline">{{ t('thread.return_to_bot') }}</span>
            </button>

            <button
                v-if="conversation.can.reset"
                type="button"
                :title="t('thread.reset')"
                :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-9 rounded-full px-2 text-destructive hover:text-destructive')"
                :disabled="busy"
                :aria-label="t('thread.reset')"
                @click="confirmReset"
            >
                <LoaderCircle v-if="busyAction === 'reset'" class="animate-spin" />
                <Eraser v-else />
                <span class="hidden lg:inline">{{ t('thread.reset') }}</span>
            </button>

            <CloseWindowMenu
                v-if="queueWindow"
                ref="closeMenu"
                :entry="queueWindow"
                :owner-name="windowOwner"
                :disabled="busy"
                :hint="hint('inbox.resolve')"
                @closed-for-bot="emit('action', 'return-to-bot')"
            />
            <span
                v-else-if="heldByOther"
                class="inline-flex h-9 max-w-[12rem] items-center gap-1 rounded-full bg-elevated px-2.5 text-xs text-muted-foreground"
                :title="t('queue.held_by_hint')"
                data-held-by
            >
                <Lock class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="truncate" dir="auto">{{ t('queue.held_by', { name: holderName }) }}</span>
            </span>
            <button
                v-else-if="conversation.status !== 'resolved'"
                type="button"
                :title="`${t('thread.resolve')}${hint('inbox.resolve')}`"
                :class="cn(buttonVariants({ size: 'sm' }), 'h-9 rounded-full px-2.5')"
                :disabled="busy"
                :aria-label="t('thread.resolve')"
                @click="emit('action', 'resolve')"
            >
                <LoaderCircle v-if="busyAction === 'resolve'" class="animate-spin" />
                <CheckCircle2 v-else />
                <span class="hidden sm:inline">{{ t('thread.resolve') }}</span>
            </button>
            <button
                v-else
                type="button"
                :title="`${t('thread.reopen')}${hint('inbox.reopen')}`"
                :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-9 rounded-full px-2.5')"
                :disabled="busy"
                :aria-label="t('thread.reopen')"
                @click="emit('action', 'reopen')"
            >
                <LoaderCircle v-if="busyAction === 'reopen'" class="animate-spin" />
                <RotateCcw v-else />
                <span class="hidden sm:inline">{{ t('thread.reopen') }}</span>
            </button>

            <button type="button" :title="t('thread.customer')" :class="cn(ghostIcon, 'size-9 xl:hidden')" :aria-label="t('thread.customer')" @click="emit('openCustomer')">
                <UserRound />
            </button>
        </div>
    </header>
</template>
