<script setup lang="ts">
import CommentReplyForm from '@/components/crm/CommentReplyForm.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { SharedData } from '@/types';
import type { Capabilities, CommentItem } from '@/types/admin';
import { Link, usePage } from '@inertiajs/vue3';
import { Bot, EyeOff, Lock, MessageSquareReply, MessagesSquare } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ comment: CommentItem; capabilities: Capabilities; busy?: 'reply' | 'hide' | 'private-reply' }>();
const emit = defineEmits<{ action: [action: 'reply' | 'hide' | 'private-reply', text: string | undefined, done: () => void] }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const PRIVATE_REPLY_DAYS = 7;

const open = ref<'reply' | 'private-reply' | null>(null);
const caps = computed(() => (props.comment.post?.platform ? props.capabilities[props.comment.post.platform] : undefined));

const intentTone = { buy: 'positive', question: 'info', complaint: 'negative', spam: 'warning', other: 'neutral' } as const;
const statusTone = { new: 'warning', replied: 'positive', hidden: 'neutral', ignored: 'neutral' } as const;

// Why "Private reply" is unavailable (null = allowed). Mirrors CommentActions::privateReply.
const privateBlock = computed<string | null>(() => {
    if (props.comment.private_reply_sent_at) return t('comments.private_disabled.sent');
    if (!caps.value?.private_reply) return t('comments.private_disabled.unsupported');
    const created = Date.parse(props.comment.created_at ?? '');
    if (!Number.isNaN(created) && Date.now() - created > PRIVATE_REPLY_DAYS * 86400000) return t('comments.private_disabled.expired');
    return null;
});

const hideBlock = computed<string | null>(() => (caps.value?.hide_comment ? null : t('comments.hide_unsupported')));

const repliedBy = computed(() => {
    const c = props.comment;
    if (!c.public_reply) return null;
    if (c.replied_by_type === 'bot') return t('comments.by_bot');
    // Broadcast patches may not carry `replied_by`; fall back to the viewer's own name, then "staff".
    const me = page.props.auth.user;
    const name = c.replied_by?.name ?? (c.replied_by_id === me.id ? me.name : null);
    return name ? t('comments.by_user', { name }) : t('comments.by_staff');
});

function submit(action: 'reply' | 'private-reply', text: string): void {
    emit('action', action, text, () => (open.value = null));
}

const btn = 'inline-flex h-7 items-center gap-1 rounded-md border bg-background px-2 text-xs text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <article class="px-3 py-2.5" :aria-busy="!!busy">
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
            <span class="font-medium text-foreground">{{ comment.customer?.name ?? t('comments.customer_unknown') }}</span>
            <time class="text-2xs tabular-nums text-muted-foreground" :datetime="comment.created_at ?? undefined">{{ formatDateTime(comment.created_at, locale) }}</time>
            <StatusChip v-if="comment.intent" :label="t(`comments.intent.${comment.intent}`)" :tone="intentTone[comment.intent]" />
            <StatusChip v-if="comment.status" :label="t(`comments.status.${comment.status}`)" :tone="statusTone[comment.status]" />
        </div>
        <p class="mt-1 whitespace-pre-line break-words text-sm" dir="auto" :class="{ 'text-muted-foreground line-through': comment.status === 'hidden' }">{{ comment.body }}</p>

        <div v-if="repliedBy" class="mt-1.5 rounded-md border-s-2 bg-muted/50 px-2 py-1 text-xs" :class="comment.replied_by_type === 'bot' ? 'border-indigo-400' : 'border-emerald-500'">
            <p class="flex items-center gap-1 text-2xs text-muted-foreground">
                <Bot v-if="comment.replied_by_type === 'bot'" class="size-3" aria-hidden="true" />
                {{ repliedBy }} · <span class="tabular-nums">{{ formatDateTime(comment.public_replied_at, locale) }}</span>
            </p>
            <p class="break-words" dir="auto">{{ comment.public_reply }}</p>
        </div>
        <p v-if="comment.private_reply_sent_at" class="mt-1 flex items-center gap-1 text-2xs text-muted-foreground">
            <Lock class="size-3" aria-hidden="true" />{{ t('comments.private_sent_at', { time: formatDateTime(comment.private_reply_sent_at, locale) }) }}
        </p>

        <TooltipProvider :delay-duration="150">
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <button type="button" :class="btn" :aria-expanded="open === 'reply'" :disabled="comment.status === 'hidden'" @click="open = open === 'reply' ? null : 'reply'">
                    <MessageSquareReply class="size-3.5" aria-hidden="true" />{{ t('comments.reply') }}
                </button>

                <Tooltip :disabled="!hideBlock">
                    <TooltipTrigger as-child>
                        <span :tabindex="hideBlock ? 0 : undefined">
                            <button type="button" :class="btn" :disabled="!!hideBlock || comment.status === 'hidden' || busy === 'hide'" @click="emit('action', 'hide', undefined, () => undefined)">
                                <EyeOff class="size-3.5" aria-hidden="true" />{{ t('comments.hide') }}
                            </button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent v-if="hideBlock">{{ hideBlock }}</TooltipContent>
                </Tooltip>

                <Tooltip :disabled="!privateBlock">
                    <TooltipTrigger as-child>
                        <span :tabindex="privateBlock ? 0 : undefined" :aria-label="privateBlock ?? undefined">
                            <button
                                type="button"
                                :class="btn"
                                :disabled="!!privateBlock"
                                :aria-expanded="open === 'private-reply'"
                                :title="privateBlock ?? undefined"
                                :aria-describedby="privateBlock ? `private-reply-reason-${comment.id}` : undefined"
                                @click="open = open === 'private-reply' ? null : 'private-reply'"
                            >
                                <Lock class="size-3.5" aria-hidden="true" />{{ t('comments.private_reply') }}
                            </button>
                            <span v-if="privateBlock" :id="`private-reply-reason-${comment.id}`" class="sr-only">{{ privateBlock }}</span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent v-if="privateBlock">{{ privateBlock }}</TooltipContent>
                </Tooltip>

                <Link v-if="comment.conversation_id" :href="`/inbox?c=${comment.conversation_id}`" class="ms-auto inline-flex items-center gap-1 text-xs text-primary hover:underline">
                    <MessagesSquare class="size-3.5" aria-hidden="true" />{{ t('ui.open_conversation') }}
                </Link>
            </div>
        </TooltipProvider>

        <CommentReplyForm
            v-if="open === 'reply'"
            :placeholder="t('comments.reply_placeholder')"
            :submit-label="t('comments.send_reply')"
            :busy="busy === 'reply'"
            @submit="submit('reply', $event)"
            @cancel="open = null"
        />
        <CommentReplyForm
            v-if="open === 'private-reply' && !privateBlock"
            :placeholder="t('comments.private_placeholder')"
            :submit-label="t('comments.private_send')"
            :busy="busy === 'private-reply'"
            @submit="submit('private-reply', $event)"
            @cancel="open = null"
        />
    </article>
</template>
