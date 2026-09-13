<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import PresenceBar from '@/components/crm/PresenceBar.vue';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationAction, ConversationPriority, Tag, UserRef } from '@/types/crm';
import { Bot, CheckCircle2, ChevronLeft, LoaderCircle, RotateCcw, ShieldAlert, Star, Tags, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ conversation: Conversation; viewers: UserRef[]; meId: number; typing: string[]; tags: Tag[]; busyAction: string | null }>();
const emit = defineEmits<{ back: []; openCustomer: []; action: [name: ConversationAction]; priority: [value: ConversationPriority]; toggleTag: [id: number] }>();

const { t } = useI18n();

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const selectedTags = computed(() => new Set((props.conversation.tags ?? []).map((tag) => tag.id)));
const busy = computed(() => props.busyAction !== null);
</script>

<template>
    <header class="flex items-center gap-2 border-b bg-card px-3 py-2 md:px-4">
        <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'size-8 md:hidden')" :aria-label="t('inbox.back')" @click="emit('back')">
            <ChevronLeft class="rtl-flip" />
        </button>

        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 items-center gap-2">
                <h2 class="truncate text-sm font-semibold">{{ name }}</h2>
                <PlatformBadge :platform="conversation.platform" show-label size="xs" />
                <span v-if="conversation.needs_human" class="shrink-0 rounded bg-red-50 px-1.5 py-0.5 text-2xs font-medium text-red-700">
                    {{ t('thread.needs_human') }}
                </span>
                <span
                    v-else
                    class="shrink-0 rounded px-1.5 py-0.5 text-2xs font-medium"
                    :class="conversation.handler === 'bot' ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                >
                    {{ conversation.handler === 'bot' ? `🤖 ${t('thread.handler_bot')}` : t('thread.handler_human') }}
                </span>
                <span v-if="conversation.priority === 'spam'" class="shrink-0 rounded bg-red-50 px-1.5 py-0.5 text-2xs font-medium text-red-700">
                    {{ t('thread.priority_spam') }}
                </span>
                <span v-else-if="conversation.priority === 'low'" class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-2xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {{ t('thread.priority_low') }}
                </span>
                <span
                    v-if="conversation.source === 'comment' || conversation.source === 'ad'"
                    class="hidden shrink-0 rounded bg-muted px-1.5 py-0.5 text-2xs text-muted-foreground lg:inline"
                >
                    {{ t(`thread.source_${conversation.source}`) }}
                </span>
                <span v-if="conversation.status === 'resolved'" class="shrink-0 rounded bg-emerald-50 px-1.5 py-0.5 text-2xs font-medium text-emerald-700">
                    {{ t('inbox.status.resolved') }}
                </span>
            </div>
            <PresenceBar class="mt-0.5" :viewers="viewers" :me-id="meId" :typing="typing" />
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <DropdownMenu>
                <DropdownMenuTrigger :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-8 gap-1.5 px-2')" :aria-label="t('thread.tags')">
                    <Tags />
                    <span v-if="conversation.tags?.length" class="text-xs tabular-nums">{{ conversation.tags.length }}</span>
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
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8 px-2')"
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
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8 px-2')"
                :disabled="busy"
                :aria-label="t('thread.mark_important')"
                @click="emit('priority', 'normal')"
            >
                <LoaderCircle v-if="busyAction === 'priority-normal'" class="animate-spin" />
                <Star v-else />
                <span class="hidden lg:inline">{{ t('thread.mark_important') }}</span>
            </button>

            <button
                v-if="conversation.handler === 'human'"
                type="button"
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8 px-2')"
                :disabled="busy"
                :aria-label="t('thread.return_to_bot')"
                @click="emit('action', 'return-to-bot')"
            >
                <LoaderCircle v-if="busyAction === 'return-to-bot'" class="animate-spin" />
                <Bot v-else />
                <span class="hidden lg:inline">{{ t('thread.return_to_bot') }}</span>
            </button>

            <button
                v-if="conversation.status !== 'resolved'"
                type="button"
                :class="cn(buttonVariants({ size: 'sm' }), 'h-8 px-2.5')"
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
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8 px-2.5')"
                :disabled="busy"
                :aria-label="t('thread.reopen')"
                @click="emit('action', 'reopen')"
            >
                <LoaderCircle v-if="busyAction === 'reopen'" class="animate-spin" />
                <RotateCcw v-else />
                <span class="hidden sm:inline">{{ t('thread.reopen') }}</span>
            </button>

            <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'size-8 xl:hidden')" :aria-label="t('thread.customer')" @click="emit('openCustomer')">
                <UserRound />
            </button>
        </div>
    </header>
</template>
