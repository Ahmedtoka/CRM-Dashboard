<script setup lang="ts">
import HandlerAvatar from '@/components/crm/HandlerAvatar.vue';
import ResolveMenu from '@/components/crm/outcomes/ResolveMenu.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import PresenceBar from '@/components/crm/PresenceBar.vue';
import CloseWindowMenu from '@/components/crm/queue/CloseWindowMenu.vue';
import QueueBanner from '@/components/crm/queue/QueueBanner.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import ResetDialog from '@/components/crm/thread/ResetDialog.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { skinClasses, type ChatSkin } from '@/composables/inbox/useChatSkin';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { shortcutHint } from '@/composables/useShortcuts';
import { conversationState } from '@/lib/conversationState';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Conversation, ConversationAction, ConversationPriority, Customer, OutcomePayload, Tag, UserRef } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import {
    Bot,
    ChevronLeft,
    Ellipsis,
    Eraser,
    Flag,
    Hand,
    LoaderCircle,
    Lock,
    Megaphone,
    PanelRight,
    RotateCcw,
    Tags,
} from 'lucide-vue-next';
import { computed, inject, nextTick, ref, watch, type Ref } from 'vue';

const props = withDefaults(
    defineProps<{
        conversation: Conversation;
        /** The thread's customer record: the phone under the name. */
        customer?: Customer | null;
        viewers: UserRef[];
        meId: number;
        typing: string[];
        tags: Tag[];
        busyAction: string | null;
        skin?: ChatSkin;
    }>(),
    { skin: 'suite', customer: null },
);
const emit = defineEmits<{
    back: [];
    openCustomer: [];
    action: [name: ConversationAction];
    /** Control room S3: «حل» outside the queue, with the picked outcome. */
    resolve: [payload: OutcomePayload, done: (error: string | null) => void];
    priority: [value: ConversationPriority];
    toggleTag: [id: number];
    claim: [];
}>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

/** The details column / sheet (Task 5): whether it shows, for the toggle's pressed state. */
const details = inject<{ open: Ref<boolean>; active?: Readonly<Ref<boolean>>; toggle: () => void } | null>('inboxDetails', null);
const detailsShown = computed(() => (details?.active ?? details?.open)?.value ?? false);

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const phone = computed(() => props.customer?.phone?.trim() || '');
const selectedTags = computed(() => new Set((props.conversation.tags ?? []).map((tag) => tag.id)));
const busy = computed(() => props.busyAction !== null);
const headerBg = computed(() => skinClasses(props.skin).header);
const iconButton = cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'size-9 shrink-0 rounded-lg');

const adTooltip = computed(() => {
    const ad = props.conversation.ad;
    if (!ad) return '';
    return [
        ad.campaign && `${t('thread.ad_campaign')}: ${ad.campaign}`,
        ad.adset && `${t('thread.ad_adset')}: ${ad.adset}`,
        ad.name && `${t('thread.ad_name')}: ${ad.name}`,
        ad.ref && `ref: ${ad.ref}`,
    ]
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
const resolveMenu = ref<InstanceType<typeof ResolveMenu> | null>(null);
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
const windowOwner = computed(() =>
    queueWindow.value && queueWindow.value.assigned_user_id !== props.meId ? (props.conversation.assignee?.name ?? null) : null,
);

/**
 * Row 2's state chip. Test / spam / low have chips of their own here, so the state underneath
 * them still shows (the list row shows only the one badge).
 */
const state = computed(() => {
    const s = conversationState({ ...props.conversation, is_test: false, priority: 'normal' }, t, new Map());
    if (s) return s;

    return props.conversation.handler === 'human' ? { key: 'with', label: t('thread.handler_human'), tone: 'neutral' as const } : null;
});
const topic = computed(() => props.conversation.handover_topic || props.conversation.handover_category_label || '');

const canClaim = computed(
    () => props.conversation.handling?.id !== props.meId && props.conversation.status !== 'resolved' && props.conversation.can.reply,
);
const canReturnToBot = computed(() => props.conversation.handler === 'human' && !heldByOther.value);

const moreOpen = ref(false);
const tagsOpen = ref(false);
const resetOpen = ref(false);
const headerEl = ref<HTMLElement | null>(null);
/** The reset dialog closed: focus back on the «⋯» that led to it. */
function focusMore(): void {
    headerEl.value?.querySelector<HTMLElement>('[data-more-menu]')?.focus();
}
// A submenu left open must not pop open again with the next «⋯».
watch(moreOpen, (open) => {
    if (!open) tagsOpen.value = false;
});
const priorities: ConversationPriority[] = ['normal', 'low', 'spam'];

const hint = (id: string) => {
    const key = shortcutHint(id);
    return key ? ` (${key})` : '';
};

function setPriority(value: string): void {
    if (value !== props.conversation.priority) emit('priority', value as ConversationPriority);
}

defineExpose({
    /** `t`: the «⋯» menu opens on its tags submenu. */
    openTags: () => {
        moreOpen.value = true;
        // The submenu can only open once the menu's content is mounted.
        void nextTick(() => requestAnimationFrame(() => (tagsOpen.value = true)));
    },
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
    /** «حل» outside the queue: opens the outcome menu; false when there is nothing to resolve. */
    openResolve: (): boolean => {
        if (!resolveMenu.value) return false;
        resolveMenu.value.open();

        return true;
    },
    /** The close menu (or one of its dialogs) or the resolve menu is open. */
    closeMenuOpen: (): boolean => closeMenu.value?.isOpen() || resolveMenu.value?.isOpen() || false,
});
</script>

<template>
    <header ref="headerEl" class="shrink-0 border-b shadow-card" :class="headerBg" data-thread-header>
        <!-- Row 1: who she is, and what to do. The name truncates; the actions never do. -->
        <div class="flex h-14 min-w-0 items-center gap-2 pe-2 ps-2 md:pe-3 md:ps-4" data-header-row1>
            <button type="button" :class="cn(iconButton, 'md:hidden')" :aria-label="t('inbox.back')" :title="t('inbox.back')" @click="emit('back')">
                <ChevronLeft class="rtl-flip" />
            </button>

            <span
                class="relative hidden size-9 shrink-0 items-center justify-center rounded-full bg-elevated text-xs font-semibold text-muted-foreground sm:flex"
                aria-hidden="true"
            >
                {{ getInitials(name) }}
            </span>

            <div class="min-w-0 flex-1">
                <h2 class="truncate text-sm font-semibold leading-5" dir="auto" :title="name" data-header-name>{{ name }}</h2>
                <p class="flex min-w-0 items-center gap-1.5 text-2xs leading-4 text-muted-foreground">
                    <PlatformBadge :platform="conversation.platform" size="xs" :show-label="!phone" />
                    <span v-if="phone" class="truncate tabular-nums" dir="ltr">{{ phone }}</span>
                </p>
            </div>

            <div class="hidden min-w-0 max-w-[10rem] sm:flex">
                <PresenceBar :viewers="viewers" :me-id="meId" :typing="typing" />
            </div>

            <!-- The primary action: «خلصت» (with its reasons menu) on her queue window, else resolve / reopen as always. -->
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
                class="inline-flex h-9 min-w-0 shrink-0 items-center gap-1 rounded-lg bg-elevated px-2.5 text-xs text-muted-foreground sm:max-w-[12rem] sm:shrink"
                :title="`${t('queue.held_by', { name: holderName })} · ${t('queue.held_by_hint')}`"
                data-held-by
            >
                <Lock class="size-3.5 shrink-0" aria-hidden="true" />
                <!-- On a phone only the lock: the name keeps the room (the row-2 chip says who has her). -->
                <span class="hidden truncate sm:inline" dir="auto">{{ t('queue.held_by', { name: holderName }) }}</span>
                <span class="sr-only sm:hidden">{{ t('queue.held_by', { name: holderName }) }}</span>
            </span>
            <ResolveMenu
                v-else-if="conversation.status !== 'resolved'"
                ref="resolveMenu"
                :disabled="busy"
                :busy="busyAction === 'resolve'"
                :hint="hint('inbox.resolve')"
                @resolve="(payload, done) => emit('resolve', payload, done)"
            />
            <Button
                v-else
                variant="outline"
                size="sm"
                class="h-9 shrink-0 gap-1.5 rounded-lg px-2.5 sm:px-3"
                type="button"
                :title="`${t('thread.header.reopen')}${hint('inbox.reopen')}`"
                :disabled="busy"
                :aria-label="t('thread.header.reopen')"
                data-primary-action
                @click="emit('action', 'reopen')"
                :loading="busyAction === 'reopen'"
            >
                <RotateCcw aria-hidden="true" />
                <span class="hidden sm:inline">{{ t('thread.header.reopen') }}</span>
            </Button>

            <button
                type="button"
                :class="cn(iconButton, detailsShown && 'bg-accent text-accent-foreground')"
                :aria-label="t('thread.header.details')"
                :aria-pressed="detailsShown"
                :title="`${t('thread.header.details')}${hint('inbox.details')}`"
                data-details-toggle
                @click="emit('openCustomer')"
            >
                <PanelRight class="rtl-flip" aria-hidden="true" />
            </button>

            <DropdownMenu v-model:open="moreOpen">
                <DropdownMenuTrigger :class="iconButton" :aria-label="t('thread.header.more')" :title="t('thread.header.more')" data-more-menu>
                    <LoaderCircle v-if="busy && busyAction !== 'resolve' && busyAction !== 'reopen'" class="animate-spin" aria-hidden="true" />
                    <Ellipsis v-else aria-hidden="true" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuItem v-if="canClaim" :disabled="busy" data-menu="claim" @select="emit('claim')">
                        <Hand aria-hidden="true" />{{ t('handling.claim') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="canReturnToBot" :disabled="busy" data-menu="return-to-bot" @select="emit('action', 'return-to-bot')">
                        <Bot aria-hidden="true" />{{ t('thread.return_to_bot') }}
                    </DropdownMenuItem>

                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger data-menu="priority" class="gap-2">
                            <Flag class="size-4" aria-hidden="true" />{{ t('thread.header.priority') }}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent class="w-44">
                            <DropdownMenuRadioGroup :model-value="conversation.priority" @update:model-value="setPriority">
                                <DropdownMenuRadioItem v-for="level in priorities" :key="level" :value="level" :disabled="busy" class="text-sm">
                                    {{ t(`thread.header.priority_${level}`) }}
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>

                    <DropdownMenuSub v-model:open="tagsOpen">
                        <DropdownMenuSubTrigger data-menu="tags" class="gap-2">
                            <Tags class="size-4" aria-hidden="true" />{{ t('thread.tags') }}
                            <span v-if="conversation.tags?.length" class="text-2xs tabular-nums text-muted-foreground">{{
                                formatCount(conversation.tags.length, locale)
                            }}</span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent class="max-h-72 w-52 overflow-y-auto">
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
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>

                    <template v-if="conversation.can.reset">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-destructive focus:text-destructive"
                            :disabled="busy"
                            data-menu="reset"
                            @select="resetOpen = true"
                        >
                            <Eraser aria-hidden="true" />{{ t('thread.reset') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Row 2: one line of state, scrolled sideways when it does not fit; it never wraps over row 1. -->
        <div
            class="scrollbar-none flex h-9 min-w-0 items-center gap-1.5 overflow-x-auto whitespace-nowrap border-t border-border/60 px-3 md:px-4"
            role="group"
            :aria-label="t('thread.header.chips')"
            data-header-row2
        >
            <StatusChip v-if="state" :label="state.label" :tone="state.tone" :icon="state.key === 'bot' ? Bot : undefined" data-state-chip />
            <StatusChip v-if="conversation.is_test" :label="t('inbox.test_badge')" tone="neutral" />
            <StatusChip v-if="conversation.is_load_test" :label="t('inbox.load_test_badge')" tone="warning" data-load-test-chip />
            <QueueBanner :conversation="conversation" :me-id="meId" part="chips" />
            <StatusChip v-if="conversation.priority_level === 'high'" :label="t('inbox.priority_level.high')" tone="negative" />
            <StatusChip v-else-if="conversation.priority_level === 'medium'" :label="t('inbox.priority_level.medium')" tone="warning" />
            <StatusChip v-if="conversation.priority === 'spam'" :label="t('thread.priority_spam')" tone="negative" />
            <StatusChip v-else-if="conversation.priority === 'low'" :label="t('thread.priority_low')" tone="neutral" />
            <span
                v-if="topic"
                class="inline-flex h-5 max-w-[14rem] shrink-0 items-center rounded-full border border-border px-2 text-2xs text-muted-foreground"
                :title="conversation.handover_topic ? t('inbox.topic', { topic }) : t('inbox.category', { label: topic })"
                dir="auto"
            >
                <span class="truncate">{{ topic }}</span>
            </span>
            <span
                v-if="conversation.ad"
                class="inline-flex h-5 max-w-56 shrink-0 items-center gap-1 rounded-full bg-amber-500/15 px-2 text-2xs text-amber-800 dark:text-amber-200"
                :title="adTooltip"
                dir="auto"
            >
                <Megaphone class="size-3 shrink-0" aria-hidden="true" />
                <span class="truncate">{{
                    conversation.ad.title || conversation.ad.name || (conversation.ad.ref ? t('thread.source_link') : t('thread.source_ad'))
                }}</span>
            </span>
            <span
                v-else-if="conversation.source === 'comment' || conversation.source === 'ad'"
                class="inline-flex h-5 shrink-0 items-center rounded-full bg-muted px-2 text-2xs text-muted-foreground"
            >
                {{ t(`thread.source_${conversation.source}`) }}
            </span>
            <span
                v-for="tag in conversation.tags ?? []"
                :key="tag.id"
                class="inline-flex h-5 shrink-0 items-center gap-1 rounded-full border border-border bg-card px-2 text-2xs text-foreground"
                dir="auto"
            >
                <span class="size-1.5 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }" aria-hidden="true" />{{ tag.name }}
            </span>
            <span class="ms-auto flex shrink-0 items-center ps-2">
                <HandlerAvatar :handling="conversation.handling" :viewers="viewers" :me-id="meId" size="sm" />
            </span>
        </div>
        <QueueBanner :conversation="conversation" :me-id="meId" part="bar" />

        <ResetDialog v-model:open="resetOpen" :busy="busyAction === 'reset'" @confirm="emit('action', 'reset')" @close-focus="focusMore" />
    </header>
</template>
