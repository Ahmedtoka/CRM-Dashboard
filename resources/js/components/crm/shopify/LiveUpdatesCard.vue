<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { ShopifyWebhookRow } from '@/types/admin';
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, LoaderCircle, Scale } from 'lucide-vue-next';
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
    <section class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card">
        <header class="flex flex-wrap items-center gap-2">
            <CheckCircle2 v-if="live" class="size-4 text-success" aria-hidden="true" />
            <CircleAlert v-else class="size-4 text-warning" aria-hidden="true" />
            <h2 class="text-sm font-semibold">{{ t('settings.shopify.live.title') }}</h2>
            <Link
                href="/settings/shopify/reconcile"
                class="ms-auto inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 font-medium hover:bg-muted"
            >
                <Scale class="size-3.5" aria-hidden="true" />
                {{ t('settings.shopify.reconcile.title') }}
            </Link>
        </header>

        <p v-if="live" class="text-success">{{ t('settings.shopify.live.ok') }}</p>
        <template v-else>
            <p v-if="!hasSecret" class="rounded bg-warning/10 px-2 py-1.5">{{ t('settings.shopify.live.no_secret') }}</p>
            <p v-else class="rounded bg-warning/10 px-2 py-1.5">{{ t('settings.shopify.live.nothing_received') }}</p>
        </template>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="submit">
            <label class="grid min-w-64 flex-1 gap-1">
                <span class="text-muted-foreground">{{ hasSecret ? t('settings.shopify.live.replace_secret') : t('settings.shopify.live.secret') }}</span>
                <input v-model="secret" type="password" autocomplete="off" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" />
            </label>
            <button
                type="submit"
                class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                :disabled="saving || secret.trim().length < 10"
            >
                <LoaderCircle v-if="saving" class="size-3.5 animate-spin" aria-hidden="true" />
                {{ t('settings.shopify.live.save') }}
            </button>
            <p class="basis-full text-muted-foreground">{{ t('settings.shopify.live.secret_hint') }}</p>
        </form>
    </section>
</template>
