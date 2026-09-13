<script setup lang="ts">
import OrderCard from '@/components/crm/OrderCard.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { formatNumber } from '@/i18n';
import { formatDateTime, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Customer, Note, Participant } from '@/types/crm';
import { LoaderCircle, Mail, MapPin, Phone, Plus, ShoppingBag } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ customer: Customer | null; notes: Note[]; participants: Participant[]; addingNote: boolean }>();
const emit = defineEmits<{ addNote: [body: string, done: () => void]; createOrder: [] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const noteBody = ref('');
const name = computed(() => props.customer?.name || '—');
const place = computed(() => [props.customer?.city, props.customer?.address].filter(Boolean).join(' · '));

function submitNote(): void {
    const body = noteBody.value.trim();
    if (!body || props.addingNote) return;
    emit('addNote', body, () => (noteBody.value = ''));
}

const heading = 'mb-2 text-2xs font-semibold text-muted-foreground';
</script>

<template>
    <aside class="flex min-h-0 flex-col bg-card" :aria-label="t('thread.customer')">
        <div class="scrollbar-thin min-h-0 flex-1 space-y-5 overflow-y-auto p-4">
            <section class="flex items-start gap-3">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                    {{ getInitials(name) }}
                </span>
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="truncate text-sm font-semibold">{{ name }}</p>
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

            <button type="button" :class="cn(buttonVariants({ size: 'sm' }), 'w-full')" :disabled="!customer" @click="emit('createOrder')">
                <ShoppingBag />{{ t('customer.create_order') }}
            </button>

            <section v-if="customer?.identities?.length">
                <h3 :class="heading">{{ t('customer.identities') }}</h3>
                <ul class="space-y-1.5">
                    <li v-for="identity in customer.identities" :key="identity.id" class="flex items-center gap-2 text-xs">
                        <PlatformBadge :platform="identity.platform" show-label size="xs" />
                        <span class="truncate text-muted-foreground" dir="auto">{{ identity.display_name || identity.username || identity.external_id }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="participants.length">
                <h3 :class="heading">{{ t('customer.participants') }}</h3>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="(p, index) in participants" :key="index" class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-2xs">
                        <span class="size-2 rounded-full" :style="{ backgroundColor: p.user?.color || '#6366f1' }" aria-hidden="true" />
                        {{ p.user?.name ?? t('thread.bot') }} · {{ t(`customer.roles.${p.role}`) }}
                    </li>
                </ul>
            </section>

            <section>
                <h3 :class="heading">{{ t('customer.orders') }}</h3>
                <p v-if="!customer?.orders?.length" class="text-xs text-muted-foreground">{{ t('customer.no_orders') }}</p>
                <div v-else class="space-y-2">
                    <OrderCard v-for="order in customer.orders" :key="order.id" :order="order" />
                </div>
            </section>

            <section>
                <h3 :class="heading">{{ t('customer.notes') }}</h3>
                <form class="space-y-1.5" @submit.prevent="submitNote">
                    <textarea
                        v-model="noteBody"
                        rows="2"
                        dir="auto"
                        :placeholder="t('customer.note_placeholder')"
                        :aria-label="t('customer.note_placeholder')"
                        class="w-full resize-none rounded-md border border-amber-200 bg-amber-50/60 px-2 py-1.5 text-xs placeholder:text-amber-800/50"
                        @keydown.enter.ctrl.prevent="submitNote"
                        @keydown.enter.meta.prevent="submitNote"
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
                    <li v-for="note in notes" :key="note.id" class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-950">
                        <p class="whitespace-pre-wrap" dir="auto">{{ note.body }}</p>
                        <p class="mt-1 text-2xs text-amber-800/80">
                            {{ note.user?.name }} · <span class="tabular-nums">{{ formatDateTime(note.created_at, locale) }}</span>
                        </p>
                    </li>
                </ul>
            </section>
        </div>
    </aside>
</template>
