<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { Copy } from 'lucide-vue-next';

defineProps<{ requiredScopes: string[] }>();

const { t } = useI18n();
const toast = useToast();

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toast.push(t('settings.shopify.guide.copied'), 'info');
    } catch {
        toast.push(t('common.error'), 'error');
    }
}
</script>

<template>
    <section class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card">
        <h2 class="text-sm font-semibold">{{ t('settings.shopify.guide.title') }}</h2>

        <ol class="grid list-inside list-decimal gap-2 text-muted-foreground">
            <li>{{ t('settings.shopify.guide.step1') }}</li>
            <li>
                {{ t('settings.shopify.guide.step2') }}
                <div class="mt-2 flex flex-wrap gap-1.5" dir="ltr">
                    <button
                        v-for="scope in requiredScopes"
                        :key="scope"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full border border-border bg-background px-2 py-0.5 font-mono text-2xs hover:bg-muted"
                        :title="t('ui.copy')"
                        @click="copy(scope)"
                    >
                        {{ scope }}
                        <Copy class="size-3" aria-hidden="true" />
                    </button>
                </div>
            </li>
            <li>{{ t('settings.shopify.guide.step3') }}</li>
            <li>{{ t('settings.shopify.guide.step4') }}</li>
        </ol>

        <p class="rounded bg-warning/15 px-2 py-1.5 text-foreground">
            {{ t('settings.shopify.guide.token_once_note') }}
        </p>
    </section>
</template>
