<script setup lang="ts">
import PlatformCheckboxes from '@/components/crm/PlatformCheckboxes.vue';
import { useI18n } from '@/composables/useI18n';
import { useSimulator } from '@/composables/useSimulator';
import type { PlatformValue } from '@/types/crm';
import { LoaderCircle, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const { t } = useI18n();
const sim = useSimulator();

const count = ref(20);
const seconds = ref(60);
const platforms = ref<PlatformValue[]>(['facebook', 'instagram', 'whatsapp', 'tiktok']);

const valid = computed(() => count.value >= 1 && count.value <= 500 && seconds.value >= 0 && seconds.value <= 3600 && platforms.value.length > 0);

async function send(): Promise<void> {
    if (!valid.value) return;
    await sim.post(
        'burst',
        '/simulator/burst',
        { count: count.value, seconds: seconds.value, platforms: platforms.value },
        t('simulator.burst.queued', { n: count.value }),
        { href: '/inbox', label: t('simulator.open_inbox') },
    );
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <form class="flex flex-col gap-3 rounded-lg bg-card p-4 text-xs shadow-card" @submit.prevent="send">
        <h2 class="flex items-center gap-1.5 text-sm font-medium"><Zap class="size-4" aria-hidden="true" />{{ t('simulator.burst.title') }}</h2>
        <div class="grid grid-cols-2 gap-2">
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('simulator.burst.count') }}</span>
                <input v-model.number="count" type="number" min="1" max="500" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('simulator.burst.seconds') }}</span>
                <input v-model.number="seconds" type="number" min="0" max="3600" :class="input" />
            </label>
        </div>
        <PlatformCheckboxes v-model="platforms" :legend="t('ui.platforms')" />
        <p class="text-2xs text-muted-foreground">{{ t('simulator.processing') }}</p>
        <button type="submit" class="mt-auto inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="!valid || sim.busy.value !== null">
            <LoaderCircle v-if="sim.busy.value === 'burst'" class="size-4 animate-spin" aria-hidden="true" />{{ t('simulator.burst.send') }}
        </button>
    </form>
</template>
