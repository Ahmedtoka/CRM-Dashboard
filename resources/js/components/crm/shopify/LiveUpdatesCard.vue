<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { ShopifyWebhookRow } from '@/types/admin';
import { CheckCircle2, CircleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ hasSecret: boolean; webhooks: ShopifyWebhookRow[]; saving: boolean }>();
const emit = defineEmits<{ saveSecret: [secret: string] }>();

const { t } = useI18n();
const secret = ref('');

// Live = Shopify can sign its webhooks (secret on file) and the CRM has heard from at least one.
const received = computed(() => props.webhooks.some((w) => w.last_received_at !== null));
const live = computed(() => props.hasSecret && received.value);

function submit(): void {
    if (secret.value.trim().length < 10) return;
    emit('saveSecret', secret.value.trim());
    secret.value = '';
}
</script>

<template>
    <section class="grid gap-3 rounded-lg border border-line bg-card p-4 text-13">
        <header class="flex flex-wrap items-center gap-2">
            <CheckCircle2 v-if="live" class="size-4 text-ok" aria-hidden="true" />
            <CircleAlert v-else class="size-4 text-warn" aria-hidden="true" />
            <h2 class="text-base font-bold">{{ t('settings.shopify.live.title') }}</h2>
        </header>

        <p v-if="live" class="text-ok">{{ t('settings.shopify.live.ok') }}</p>
        <template v-else>
            <p v-if="!hasSecret" class="rounded bg-warn-soft px-2 py-1.5">{{ t('settings.shopify.live.no_secret') }}</p>
            <p v-else class="rounded bg-warn-soft px-2 py-1.5">{{ t('settings.shopify.live.nothing_received') }}</p>
        </template>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="submit">
            <label class="grid min-w-64 flex-1 gap-1">
                <span class="text-fg-2">{{ hasSecret ? t('settings.shopify.live.replace_secret') : t('settings.shopify.live.secret') }}</span>
                <input
                    v-model="secret"
                    type="password"
                    autocomplete="off"
                    dir="ltr"
                    class="h-10 rounded-md border border-line-strong bg-card px-3 sm:h-9"
                />
            </label>
            <Button size="sm" type="submit" :disabled="saving || secret.trim().length < 10" :loading="saving">
                {{ t('settings.shopify.live.save') }}
            </Button>
            <p class="basis-full text-fg-2">{{ t('settings.shopify.live.secret_hint') }}</p>
        </form>
    </section>
</template>
