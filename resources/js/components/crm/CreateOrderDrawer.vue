<script setup lang="ts">
import OrderProductPicker from '@/components/crm/OrderProductPicker.vue';
import { buttonVariants } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { City, Customer, Order, ProductVariant } from '@/types/crm';
import { LoaderCircle, Minus, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ conversationId: number; customer: Customer | null; cities: City[]; canDiscount: boolean }>();
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
const shipping = reactive({ name: '', phone: '', city_id: null as number | null, address: '' });
const discount = ref(0);
const type = ref<(typeof TYPES)[number]>('cod');
const note = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

// Prefill from the customer profile each time the drawer opens.
watch(open, (isOpen) => {
    if (!isOpen) return;
    const c = props.customer;
    lines.value = [];
    discount.value = 0;
    type.value = 'cod';
    note.value = '';
    error.value = null;
    shipping.name = c?.name ?? '';
    shipping.phone = c?.phone ?? '';
    shipping.address = c?.address ?? '';
    shipping.city_id = props.cities.find((city) => !!c?.city && (city.name_ar === c.city || city.name_en === c.city))?.id ?? null;
});

function add(variant: ProductVariant): void {
    const line = lines.value.find((l) => l.variant.id === variant.id);
    if (line) line.qty = Math.min(999, line.qty + 1);
    else lines.value.push({ variant, qty: 1 });
}

const step = (line: Line, delta: number) => (line.qty = Math.max(1, Math.min(999, line.qty + delta)));
const remove = (line: Line) => (lines.value = lines.value.filter((l) => l !== line));

// Display-only totals; the server prices from the catalog (OrderService).
const city = computed(() => props.cities.find((c) => c.id === shipping.city_id) ?? null);
const subtotal = computed(() => lines.value.reduce((sum, l) => sum + l.variant.price * l.qty, 0));
const fee = computed(() => Number(city.value?.shipping_fee ?? 0));
const appliedDiscount = computed(() => (props.canDiscount ? Math.max(0, Number(discount.value) || 0) : 0));
const total = computed(() => Math.max(0, subtotal.value + fee.value - appliedDiscount.value));
const cityLabel = (c: City) => (locale.value === 'en' && c.name_en ? c.name_en : c.name_ar);

async function submit(): Promise<void> {
    if (!lines.value.length) {
        error.value = t('order.no_items');
        return;
    }
    submitting.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ data: Order }>(`/inbox/conversations/${props.conversationId}/orders`, {
            type: type.value,
            items: lines.value.map((l) => ({ variant_id: l.variant.id, qty: l.qty })),
            shipping: { ...shipping },
            discount: appliedDiscount.value > 0 ? appliedDiscount.value : undefined,
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

const field = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
const stepper = 'flex size-7 items-center justify-center hover:bg-muted disabled:opacity-40';
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent :side="dir === 'rtl' ? 'left' : 'right'" class="flex w-full flex-col gap-0 p-0 sm:max-w-md">
            <SheetHeader class="border-b px-5 py-4 text-start">
                <SheetTitle class="text-base">{{ t('order.create_title') }}</SheetTitle>
                <SheetDescription class="text-xs">{{ customer?.name }}</SheetDescription>
            </SheetHeader>

            <div class="scrollbar-thin min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                <section>
                    <p :class="label">{{ t('order.items') }}</p>
                    <OrderProductPicker @add="add" />
                    <ul v-if="lines.length" class="mt-3 divide-y rounded-md border">
                        <li v-for="line in lines" :key="line.variant.id" class="flex items-center gap-2 px-2.5 py-2">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-medium">{{ line.variant.product_title }}</p>
                                <p class="truncate text-2xs text-muted-foreground">{{ line.variant.title }} · {{ formatMoney(line.variant.price, locale) }}</p>
                            </div>
                            <div class="flex items-center rounded-md border">
                                <button type="button" :class="stepper" :disabled="line.qty <= 1" :aria-label="t('order.decrease')" @click="step(line, -1)">
                                    <Minus class="size-3" />
                                </button>
                                <span class="w-7 text-center text-xs tabular-nums" aria-live="polite">{{ line.qty }}</span>
                                <button type="button" :class="stepper" :aria-label="t('order.increase')" @click="step(line, 1)"><Plus class="size-3" /></button>
                            </div>
                            <button
                                type="button"
                                class="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-red-50 hover:text-red-600"
                                :aria-label="t('order.remove')"
                                @click="remove(line)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="grid grid-cols-2 gap-3">
                    <label><span :class="label">{{ t('order.name') }}</span><input v-model="shipping.name" :class="field" /></label>
                    <label><span :class="label">{{ t('order.phone') }}</span><input v-model="shipping.phone" dir="ltr" inputmode="tel" :class="[field, 'text-start']" /></label>
                    <label class="col-span-2">
                        <span :class="label">{{ t('order.city') }}</span>
                        <select v-model="shipping.city_id" :class="field">
                            <option :value="null">{{ t('order.choose_city') }}</option>
                            <option v-for="c in cities" :key="c.id" :value="c.id">{{ cityLabel(c) }} — {{ formatMoney(c.shipping_fee, locale) }}</option>
                        </select>
                    </label>
                    <label class="col-span-2">
                        <span :class="label">{{ t('order.address') }}</span>
                        <textarea v-model="shipping.address" rows="2" dir="auto" :class="[field, 'h-auto py-1.5']" />
                    </label>
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
                    <label v-if="canDiscount" class="block">
                        <span :class="label">{{ t('order.discount') }}</span>
                        <input v-model.number="discount" type="number" min="0" step="1" :class="field" />
                    </label>
                    <label class="block"><span :class="label">{{ t('order.note') }}</span><input v-model="note" dir="auto" :class="field" /></label>
                </section>
            </div>

            <footer class="space-y-1.5 border-t bg-muted/40 px-5 py-4 text-xs">
                <div class="flex justify-between">
                    <span class="text-muted-foreground">{{ t('order.subtotal') }}</span><span class="tabular-nums">{{ formatMoney(subtotal, locale) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">{{ t('order.shipping_fee') }}</span><span class="tabular-nums">{{ formatMoney(fee, locale) }}</span>
                </div>
                <div v-if="appliedDiscount > 0" class="flex justify-between text-emerald-700">
                    <span>{{ t('order.discount') }}</span><span class="tabular-nums">− {{ formatMoney(appliedDiscount, locale) }}</span>
                </div>
                <div class="flex justify-between border-t pt-1.5 text-sm font-semibold">
                    <span>{{ t('order.total') }}</span><span class="tabular-nums">{{ formatMoney(total, locale) }}</span>
                </div>
                <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
                <button type="button" :class="cn(buttonVariants(), 'mt-2 w-full')" :disabled="submitting || !lines.length" @click="submit">
                    <LoaderCircle v-if="submitting" class="animate-spin" />{{ t('order.submit') }}
                </button>
            </footer>
        </SheetContent>
    </Sheet>
</template>
