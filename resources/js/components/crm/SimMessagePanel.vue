<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useSimulator } from '@/composables/useSimulator';
import type { SharedData } from '@/types';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { LoaderCircle, MessageCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const { t } = useI18n();
const page = usePage<SharedData>();
const sim = useSimulator();

// Demo sample chips are Egyptian customer phrasing in both UI languages.
const SAMPLES = ['بكام؟', 'متاح مقاس L؟', 'عايزة اطلب', 'الأوردر اتأخر', 'عايز اكلم حد'];

const platform = ref<PlatformValue>('facebook');
const customerKey = ref<string>('');
const name = ref('');
const text = ref('');
const attachment = ref<'' | 'image' | 'voice' | 'video' | 'file'>('');

const selected = computed(() => sim.customers.value.find((c) => c.key === customerKey.value) ?? null);
const valid = computed(() => (text.value.trim() || attachment.value) && (selected.value || name.value.trim()));

async function send(): Promise<void> {
    if (!valid.value) return;
    const customer = selected.value ?? sim.newCustomer(name.value.trim());
    const result = await sim.post(
        'message',
        '/simulator/message',
        { platform: platform.value, customer_key: customer.key, name: customer.name, text: text.value.trim(), attachment: attachment.value || null },
        t('simulator.message.sent', { name: customer.name }),
        { href: `/inbox?platform=${platform.value}`, label: t('simulator.open_inbox') },
    );
    if (result) {
        sim.remember(customer);
        customerKey.value = customer.key;
        name.value = '';
        text.value = '';
        attachment.value = '';
    }
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <form class="flex flex-col gap-3 rounded-lg bg-card p-4 text-xs shadow-card" @submit.prevent="send">
        <h2 class="flex items-center gap-1.5 text-sm font-medium"><MessageCircle class="size-4" aria-hidden="true" />{{ t('simulator.message.title') }}</h2>
        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('simulator.platform') }}</span>
            <select v-model="platform" :class="input">
                <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
        </label>
        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('simulator.message.customer') }}</span>
            <select v-model="customerKey" :class="input">
                <option value="">{{ t('simulator.message.new_customer') }}</option>
                <option v-for="c in sim.customers.value" :key="c.key" :value="c.key">{{ c.name }}</option>
            </select>
        </label>
        <label v-if="!selected" class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('simulator.message.customer_name') }}</span>
            <input v-model="name" dir="auto" maxlength="100" :class="input" />
        </label>
        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('simulator.text') }}</span>
            <textarea v-model="text" rows="3" dir="auto" maxlength="2000" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
        </label>
        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('simulator.message.attachment') }}</span>
            <select v-model="attachment" :class="input">
                <option value="">{{ t('simulator.message.attachment_none') }}</option>
                <option value="image">{{ t('simulator.message.attachment_image') }}</option>
                <option value="voice">{{ t('simulator.message.attachment_voice') }}</option>
                <option value="video">{{ t('simulator.message.attachment_video') }}</option>
                <option value="file">{{ t('simulator.message.attachment_file') }}</option>
            </select>
        </label>
        <div class="flex flex-wrap gap-1.5" role="group" :aria-label="t('simulator.message.samples')">
            <button v-for="sample in SAMPLES" :key="sample" type="button" dir="rtl" class="rounded-full bg-elevated px-2.5 py-1 hover:bg-muted" @click="text = sample">{{ sample }}</button>
        </div>
        <button type="submit" class="mt-auto inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="!valid || sim.busy.value !== null">
            <LoaderCircle v-if="sim.busy.value === 'message'" class="size-4 animate-spin" aria-hidden="true" />{{ t('simulator.message.send') }}
        </button>
    </form>
</template>
