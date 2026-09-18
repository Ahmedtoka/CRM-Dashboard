<script setup lang="ts">
import CaseCard from '@/components/crm/cases/CaseCard.vue';
import ConversationMediaGrid from '@/components/crm/media/ConversationMediaGrid.vue';
import MentionTextarea from '@/components/crm/MentionTextarea.vue';
import OrderCard from '@/components/crm/OrderCard.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { shortcutHint } from '@/composables/useShortcuts';
import { formatNumber } from '@/i18n';
import { formatDateTime, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Customer, Note, Order, Participant, SupportCase, UserRef } from '@/types/crm';
import { LoaderCircle, Mail, MapPin, Phone, Plus, ShoppingBag } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    customer: Customer | null;
    notes: Note[];
    participants: Participant[];
    /** Latest support cases recorded for this conversation (spec §4), newest first. */
    cases?: SupportCase[];
    addingNote: boolean;
    conversationId: number | null;
    /** @mentions autocomplete data source (Task 15). */
    mentionable: UserRef[];
    /** The signed-in user's id — excluded from the note box's @mention suggestions. */
    meId: number;
}>();
const emit = defineEmits<{
    addNote: [body: string, done: () => void, mentions: number[]];
    createOrder: [];
    editOrder: [order: Order];
    copyStatus: [text: string];
    caseUpdated: [supportCase: SupportCase];
}>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const tab = ref<'details' | 'media'>('details');
const noteBody = ref('');
const noteMentions = ref<number[]>([]);
const noteInput = ref<InstanceType<typeof MentionTextarea> | null>(null);
const name = computed(() => props.customer?.name || '—');
const place = computed(() => [props.customer?.city, props.customer?.address].filter(Boolean).join(' · '));

// Fix round 1, minor (b): refocus the note box once a note finishes saving (or
// once it's re-enabled for any other reason) — a `disabled` textarea loses
// focus natively.
watch(
    () => props.addingNote,
    (busy) => {
        if (!busy) nextTick(() => noteInput.value?.focus());
    },
);

function submitNote(): void {
    const body = noteBody.value.trim();
    if (!body || props.addingNote) return;
    const mentions = [...noteMentions.value];
    emit('addNote', body, () => {
        noteBody.value = '';
        noteMentions.value = [];
    }, mentions);
}

const heading = 'mb-2 text-sm font-bold';
</script>

<template>
    <aside class="flex min-h-0 flex-col bg-card" :aria-label="t('thread.customer')">
        <div role="tablist" class="flex shrink-0 border-b">
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'details'"
                class="flex-1 border-b-2 px-3 py-2 text-xs font-medium"
                :class="tab === 'details' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="tab = 'details'"
            >
                {{ t('media.tab_details') }}
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'media'"
                class="flex-1 border-b-2 px-3 py-2 text-xs font-medium"
                :class="tab === 'media' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="tab = 'media'"
            >
                {{ t('media.tab_media') }}
            </button>
        </div>

        <ConversationMediaGrid v-if="tab === 'media' && conversationId" class="min-h-0 flex-1 overflow-y-auto" :conversation-id="conversationId" />

        <div v-else class="scrollbar-thin min-h-0 flex-1 space-y-3 overflow-y-auto bg-background p-3">
            <Card class="space-y-3 p-4">
                <section class="flex items-start gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-elevated text-sm font-semibold text-muted-foreground">
                        {{ getInitials(name) }}
                    </span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex flex-wrap items-center gap-1.5 truncate text-sm font-bold">
                            {{ name }}
                            <span
                                v-for="badge in customer?.badges ?? []"
                                :key="badge"
                                class="rounded-full px-1.5 py-0.5 text-2xs font-medium"
                                :class="badge === 'has_return' ? 'bg-destructive/10 text-destructive' : badge === 'repeat' ? 'bg-success/15 text-success' : 'bg-surface-accent text-primary'"
                            >
                                {{ t(`customer.badges.${badge}`) }}
                            </span>
                        </p>
                        <a v-if="customer?.phone" :href="`tel:${customer.phone}`" class="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground">
                            <Phone class="size-3.5 shrink-0" aria-hidden="true" /><span dir="ltr">{{ customer.phone }}</span>
                        </a>
                        <p v-if="customer?.email" class="flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                            <Mail class="size-3.5 shrink-0" aria-hidden="true" /><span dir="ltr" class="truncate">{{ customer.email }}</span>
                        </p>
                        <p v-if="place" class="flex items-start gap-1.5 text-xs text-muted-foreground">
                            <MapPin class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /><span>{{ place }}</span>
                        </p>
                    </div>
                </section>

                <div class="grid grid-cols-2 gap-2">
                    <StatCard :label="t('customer.orders_count')" :value="formatNumber(locale, customer?.orders_count ?? 0)" />
                    <StatCard :label="t('customer.total_spent')" :value="formatMoney(customer?.total_spent ?? 0, locale)" />
                </div>

                <button
                    type="button"
                    :class="cn(buttonVariants({ size: 'sm' }), 'w-full')"
                    :disabled="!customer"
                    :title="`${t('customer.create_order')}${shortcutHint('inbox.order') ? ` (${shortcutHint('inbox.order')})` : ''}`"
                    @click="emit('createOrder')"
                >
                    <ShoppingBag />{{ t('customer.create_order') }}
                </button>
            </Card>

            <Card v-if="customer?.identities?.length" class="p-4">
                <h3 :class="heading">{{ t('customer.identities') }}</h3>
                <ul class="space-y-1.5">
                    <li v-for="identity in customer.identities" :key="identity.id" class="flex items-center gap-2 text-xs">
                        <PlatformBadge :platform="identity.platform" show-label size="xs" />
                        <span class="truncate text-muted-foreground" dir="auto">{{ identity.display_name || identity.username || identity.external_id }}</span>
                    </li>
                </ul>
            </Card>

            <Card v-if="participants.length" class="p-4">
                <h3 :class="heading">{{ t('customer.participants') }}</h3>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="(p, index) in participants" :key="index" class="inline-flex items-center gap-1.5 rounded-full bg-elevated px-2 py-0.5 text-2xs">
                        <span class="size-2 rounded-full" :style="{ backgroundColor: p.user?.color || '#6366f1' }" aria-hidden="true" />
                        {{ p.user?.name ?? t('thread.bot') }} · {{ t(`customer.roles.${p.role}`) }}
                    </li>
                </ul>
            </Card>

            <div v-if="cases?.length" class="space-y-2">
                <h3 :class="heading">{{ t('nav.cases') }}</h3>
                <CaseCard v-for="c in cases" :key="c.id" :support-case="c" @updated="emit('caseUpdated', $event)" />
            </div>

            <Card class="p-4">
                <h3 :class="heading">{{ t('customer.orders') }}</h3>
                <p v-if="!customer?.orders?.length" class="text-xs text-muted-foreground">{{ t('customer.no_orders') }}</p>
                <div v-else class="space-y-2">
                    <OrderCard
                        v-for="order in customer.orders"
                        :key="order.id"
                        :order="order"
                        show-edit
                        @edit-order="emit('editOrder', $event)"
                        @copy-status="emit('copyStatus', $event)"
                    />
                </div>
            </Card>

            <Card class="p-4">
                <h3 :class="heading">{{ t('customer.notes') }}</h3>
                <p class="mb-1 text-2xs text-muted-foreground">{{ t('notes.mention_hint') }} · {{ t('notes.newline_hint') }}</p>
                <form class="space-y-1.5" @submit.prevent="submitNote">
                    <MentionTextarea
                        ref="noteInput"
                        v-model="noteBody"
                        v-model:mentions="noteMentions"
                        :users="mentionable"
                        :rows="2"
                        :disabled="addingNote"
                        :me-id="meId"
                        size="xs"
                        :placeholder="t('customer.note_placeholder')"
                        class="rounded-md border-s-4 border-[var(--note-border)] bg-[var(--note-bg)] px-2 py-1.5"
                        @submit="submitNote"
                    />
                    <div class="flex justify-end">
                        <button type="submit" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-7 text-xs')" :disabled="!noteBody.trim() || addingNote">
                            <LoaderCircle v-if="addingNote" class="animate-spin" />
                            <Plus v-else />{{ t('customer.add_note') }}
                        </button>
                    </div>
                </form>
                <p v-if="!notes.length" class="mt-2 text-xs text-muted-foreground">{{ t('customer.no_notes') }}</p>
                <ul v-else class="mt-2 space-y-2">
                    <li v-for="note in notes" :key="note.id" class="rounded-md border-s-4 border-[var(--note-border)] bg-[var(--note-bg)] px-2.5 py-2 text-xs text-foreground">
                        <p class="whitespace-pre-wrap" dir="auto">{{ note.body }}</p>
                        <p class="mt-1 text-2xs opacity-70">
                            {{ note.user?.name }} · <span class="tabular-nums">{{ formatDateTime(note.created_at, locale) }}</span>
                        </p>
                    </li>
                </ul>
            </Card>
        </div>
    </aside>
</template>
