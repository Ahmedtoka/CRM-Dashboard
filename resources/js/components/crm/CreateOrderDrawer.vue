<script setup lang="ts">
import OrderAddressPicker, { type AddressFields } from '@/components/crm/OrderAddressPicker.vue';
import OrderDiscountField, { type DiscountFields } from '@/components/crm/OrderDiscountField.vue';
import OrderProductPicker from '@/components/crm/OrderProductPicker.vue';
import OrderShippingPicker, { type ShippingFields } from '@/components/crm/OrderShippingPicker.vue';
import { buttonVariants } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Customer, Order, ProductVariant } from '@/types/crm';
import { LoaderCircle, Minus, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{
    conversationId: number;
    customer: Customer | null;
    canDiscount: boolean;
    /** Set to reopen the drawer prefilled from a failed order ("Edit order"). */
    retryOrder?: Order | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ created: [order: Order] }>();

const api = useApi();
const { t, locale, dir } = useI18n();

interface Line {
    variant: ProductVariant;
    qty: number;
}

const TYPES = ['cod', 'payment_link'] as const;

const lines = ref<Line[]>([]);
const address = reactive<AddressFields>({ name: '', phone: '', address: '', address_id: null });
const shipping = reactive<ShippingFields>({ province_code: null, rate_id: null });
const shippingFee = ref(0);
const discount = reactive<DiscountFields>({ type: 'fixed', value: 0, reason: '' });
const type = ref<(typeof TYPES)[number]>('cod');
const note = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);
// One key per open drawer, reused on every submit attempt until the drawer closes (spec ruling #1).
const idempotencyKey = ref('');

// The server requires a real UUID; `crypto.randomUUID` needs a secure context (HTTPS or
// localhost), which a plain-HTTP LAN IP is not, so this falls back to building a v4 UUID
// by hand from `crypto.getRandomValues` (also secure-context-independent) instead of a
// non-UUID timestamp string that Shopify's validation would reject with a 422.
function newKey(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// Prefill from the customer profile (or a failed order, for "Edit order") each time the drawer opens.
watch(open, (isOpen) => {
    if (!isOpen) return;
    idempotencyKey.value = newKey();
    error.value = null;

    const retry = props.retryOrder;
    const c = props.customer;

    if (retry) {
        // Reconstruct display-only line items from the failed order; the server re-prices
        // from the real catalog by variant_id regardless of what we show here.
        lines.value = (retry.items ?? [])
            .filter((i) => i.variant_id !== null)
            .map((i) => ({
                variant: {
                    id: i.variant_id as number,
                    product_id: 0,
                    product_title: i.title,
                    title: null,
                    sku: i.sku,
                    price: i.price,
                    stock: 9999,
                    inventory_policy: 'continue',
                    image_url: i.image_url ?? null,
                },
                qty: i.qty,
            }));
        type.value = retry.type;
        note.value = retry.note ?? '';
        address.name = retry.shipping?.name ?? c?.name ?? '';
        address.phone = retry.shipping?.phone ?? c?.phone ?? '';
        address.address = retry.shipping?.address ?? '';
        address.address_id = null;
        shipping.province_code = retry.shipping_province_code ?? null;
        shipping.rate_id = null;
        discount.type = retry.discount_type ?? 'fixed';
        discount.value = Number(retry.discount_value ?? retry.discount ?? 0);
        discount.reason = '';
    } else {
        lines.value = [];
        type.value = 'cod';
        note.value = '';
        address.name = c?.name ?? '';
        address.phone = c?.phone ?? '';
        address.address = c?.address ?? '';
        address.address_id = null;
        shipping.province_code = null;
        shipping.rate_id = null;
        discount.type = 'fixed';
        discount.value = 0;
        discount.reason = '';
    }
});

function add(variant: ProductVariant): void {
    const line = lines.value.find((l) => l.variant.id === variant.id);
    if (line) line.qty = Math.min(999, line.qty + 1);
    else lines.value.push({ variant, qty: 1 });
}

const step = (line: Line, delta: number) => (line.qty = Math.max(1, Math.min(999, line.qty + delta)));
const remove = (line: Line) => (lines.value = lines.value.filter((l) => l !== line));

// Display-only totals; the server prices from the catalog (OrderService).
const subtotal = computed(() => lines.value.reduce((sum, l) => sum + l.variant.price * l.qty, 0));
const appliedDiscount = computed(() => {
    if (!props.canDiscount || discount.value <= 0) return 0;
    const raw = discount.type === 'percent' ? (subtotal.value * discount.value) / 100 : discount.value;
    return Math.max(0, Math.min(subtotal.value, raw));
});
const total = computed(() => Math.max(0, subtotal.value + shippingFee.value - appliedDiscount.value));

async function submit(): Promise<void> {
    if (!lines.value.length) {
        error.value = t('order.no_items');
        return;
    }
    if (appliedDiscount.value > 0 && !discount.reason.trim()) {
        error.value = t('order.discount_reason');
        return;
    }

    submitting.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ data: Order }>(`/inbox/conversations/${props.conversationId}/orders`, {
            idempotency_key: idempotencyKey.value,
            type: type.value,
            items: lines.value.map((l) => ({ variant_id: l.variant.id, qty: l.qty })),
            shipping: {
                name: address.name,
                phone: address.phone,
                address: address.address,
                address_id: address.address_id ?? undefined,
                province_code: shipping.province_code ?? undefined,
                rate_id: shipping.rate_id ?? undefined,
            },
            discount_type: appliedDiscount.value > 0 ? discount.type : undefined,
            discount: appliedDiscount.value > 0 ? discount.value : undefined,
            discount_reason: appliedDiscount.value > 0 ? discount.reason.trim() : undefined,
            note: note.value.trim() || undefined,
        });
        emit('created', data.data);
        open.value = false;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        submitting.value = false;
    }
}

const label = 'mb-1 block text-xs font-medium text-muted-foreground';
const stepper = 'flex size-7 items-center justify-center hover:bg-muted disabled:opacity-40';
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent :side="dir === 'rtl' ? 'left' : 'right'" class="flex w-full flex-col gap-0 p-0 sm:max-w-md">
            <SheetHeader class="border-b border-border px-5 py-4 text-start">
                <SheetTitle class="text-base">{{ retryOrder ? t('order.edit_order') : t('order.create_title') }}</SheetTitle>
                <SheetDescription class="text-xs">{{ customer?.name }}</SheetDescription>
            </SheetHeader>

            <div class="scrollbar-thin min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                <section>
                    <p :class="label">{{ t('order.items') }}</p>
                    <OrderProductPicker @add="add" />
                    <ul v-if="lines.length" class="mt-3 divide-y divide-border rounded-md border border-border">
                        <li v-for="line in lines" :key="line.variant.id" class="flex items-center gap-2 px-2.5 py-2">
                            <img v-if="line.variant.image_url" :src="line.variant.image_url" alt="" class="size-8 shrink-0 rounded object-cover" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-medium">{{ line.variant.product_title }}</p>
                                <p class="truncate text-2xs text-muted-foreground">{{ line.variant.title }} · {{ formatMoney(line.variant.price, locale) }}</p>
                            </div>
                            <div class="flex items-center rounded-md border border-border">
                                <button type="button" :class="stepper" :disabled="line.qty <= 1" :aria-label="t('order.decrease')" @click="step(line, -1)">
                                    <Minus class="size-3" />
                                </button>
                                <span class="w-7 text-center text-xs tabular-nums" aria-live="polite">{{ formatCount(line.qty, locale) }}</span>
                                <button type="button" :class="stepper" :aria-label="t('order.increase')" @click="step(line, 1)"><Plus class="size-3" /></button>
                            </div>
                            <button
                                type="button"
                                class="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="t('order.remove')"
                                @click="remove(line)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                </section>

                <section>
                    <OrderAddressPicker :model-value="address" :customer="customer" @update:model-value="Object.assign(address, $event)" />
                </section>

                <section>
                    <OrderShippingPicker :model-value="shipping" :subtotal="subtotal" @update:model-value="Object.assign(shipping, $event)" @fee="shippingFee = $event" />
                </section>

                <section class="space-y-3">
                    <div>
                        <p :class="label">{{ t('order.type') }}</p>
                        <div class="grid grid-cols-2 gap-1 rounded-md bg-muted p-1" role="radiogroup" :aria-label="t('order.type')">
                            <button
                                v-for="option in TYPES"
                                :key="option"
                                type="button"
                                role="radio"
                                :aria-checked="type === option"
                                class="rounded px-2 py-1.5 text-xs font-medium transition-colors"
                                :class="type === option ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                @click="type = option"
                            >
                                {{ t(`order.${option}`) }}
                            </button>
                        </div>
                    </div>
                    <OrderDiscountField v-if="canDiscount" :model-value="discount" @update:model-value="Object.assign(discount, $event)" />
                    <label class="block"><span :class="label">{{ t('order.note') }}</span><input v-model="note" dir="auto" class="h-8 w-full rounded-md border border-input bg-background px-2 text-sm" /></label>
                </section>
            </div>

            <footer class="space-y-1.5 border-t border-border bg-muted/40 px-5 py-4 text-xs">
                <div class="flex justify-between">
                    <span class="text-muted-foreground">{{ t('order.subtotal') }}</span><span class="tabular-nums">{{ formatMoney(subtotal, locale) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">{{ t('order.shipping_fee') }}</span><span class="tabular-nums">{{ formatMoney(shippingFee, locale) }}</span>
                </div>
                <div v-if="appliedDiscount > 0" class="flex justify-between rounded bg-success/10 px-1.5 py-0.5 text-foreground">
                    <span>{{ t('order.discount') }}</span><span class="tabular-nums">− {{ formatMoney(appliedDiscount, locale) }}</span>
                </div>
                <div class="flex justify-between border-t border-border pt-1.5 text-sm font-bold">
                    <span>{{ t('order.total') }}</span><span class="tabular-nums">{{ formatMoney(total, locale) }}</span>
                </div>
                <p v-if="error" role="alert" class="rounded bg-destructive/10 px-2 py-1 text-foreground">{{ error }}</p>
                <button type="button" :class="cn(buttonVariants(), 'mt-2 w-full')" :disabled="submitting || !lines.length" @click="submit">
                    <LoaderCircle v-if="submitting" class="animate-spin" />{{ submitting ? t('order.sending') : t('order.submit') }}
                </button>
            </footer>
        </SheetContent>
    </Sheet>
</template>
