<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { ShopifyTestResult } from '@/types/admin';
import { Eye, EyeOff, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    testing: boolean;
    connecting: boolean;
    testResult: ShopifyTestResult | null;
    reconnect?: boolean;
}>();
const emit = defineEmits<{
    test: [payload: { shop_domain: string; access_token: string }];
    connect: [payload: { shop_domain: string; access_token: string; api_secret: string | null }];
}>();

const { t } = useI18n();

const shopDomain = ref('');
const accessToken = ref('');
const apiSecret = ref('');
const showToken = ref(false);
const showSecret = ref(false);

const canTest = computed(() => shopDomain.value.trim() !== '' && accessToken.value.trim() !== '' && !props.testing);
const blockingMissing = computed(() => (props.testResult?.missing_scopes ?? []).filter((s) => !(props.testResult?.optional_scopes ?? []).includes(s)));
const optionalMissing = computed(() => (props.testResult?.missing_scopes ?? []).filter((s) => (props.testResult?.optional_scopes ?? []).includes(s)));
const testPassed = computed(() => props.testResult?.ok === true && blockingMissing.value.length === 0);
const canConnect = computed(() => testPassed.value && !props.connecting);

function submitTest(): void {
    if (!canTest.value) return;
    emit('test', { shop_domain: shopDomain.value.trim(), access_token: accessToken.value.trim() });
}

function submitConnect(): void {
    if (!canConnect.value) return;
    emit('connect', { shop_domain: shopDomain.value.trim(), access_token: accessToken.value.trim(), api_secret: apiSecret.value.trim() || null });
}
</script>

<template>
    <form class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card" @submit.prevent="submitConnect">
        <h2 v-if="reconnect" class="text-sm font-semibold">{{ t('settings.shopify.form.reconnect') }}</h2>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.form.domain') }}</span>
            <input
                v-model="shopDomain"
                type="text"
                dir="ltr"
                autocomplete="off"
                :placeholder="t('settings.shopify.form.domain_hint')"
                class="h-9 rounded-md border border-input bg-background px-3"
            />
        </label>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.form.token') }}</span>
            <div class="flex items-center gap-1">
                <input
                    v-model="accessToken"
                    :type="showToken ? 'text' : 'password'"
                    dir="ltr"
                    autocomplete="off"
                    class="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-3"
                />
                <button type="button" class="rounded p-2 text-muted-foreground hover:bg-muted" :aria-label="showToken ? t('settings.shopify.form.hide') : t('settings.shopify.form.show')" @click="showToken = !showToken">
                    <EyeOff v-if="showToken" class="size-4" aria-hidden="true" />
                    <Eye v-else class="size-4" aria-hidden="true" />
                </button>
            </div>
        </label>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.form.secret') }}</span>
            <div class="flex items-center gap-1">
                <input
                    v-model="apiSecret"
                    :type="showSecret ? 'text' : 'password'"
                    dir="ltr"
                    autocomplete="off"
                    class="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-3"
                />
                <button type="button" class="rounded p-2 text-muted-foreground hover:bg-muted" :aria-label="showSecret ? t('settings.shopify.form.hide') : t('settings.shopify.form.show')" @click="showSecret = !showSecret">
                    <EyeOff v-if="showSecret" class="size-4" aria-hidden="true" />
                    <Eye v-else class="size-4" aria-hidden="true" />
                </button>
            </div>
            <span class="text-muted-foreground">{{ t('settings.shopify.form.secret_hint') }}</span>
        </label>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-md border border-border px-3 font-medium hover:bg-muted disabled:opacity-50"
                :disabled="!canTest"
                @click="submitTest"
            >
                <LoaderCircle v-if="testing" class="size-3.5 animate-spin" aria-hidden="true" />
                {{ testing ? t('settings.shopify.form.testing') : t('settings.shopify.form.test') }}
            </button>
            <button
                type="submit"
                class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 font-medium text-primary-foreground disabled:opacity-50"
                :disabled="!canConnect"
                :title="!testPassed ? t('settings.shopify.form.connect_hint') : undefined"
            >
                <LoaderCircle v-if="connecting" class="size-3.5 animate-spin" aria-hidden="true" />
                {{ connecting ? t('settings.shopify.form.connecting') : t('settings.shopify.form.connect') }}
            </button>
        </div>

        <p v-if="testResult?.ok" class="rounded bg-success/10 px-2 py-1.5 text-foreground">
            {{ t('settings.shopify.form.test_ok', { name: testResult.shop_name ?? '—', currency: testResult.currency ?? '—' }) }}
        </p>
        <p v-else-if="testResult && !testResult.ok && testResult.error" class="rounded bg-destructive/10 px-2 py-1.5 text-foreground" dir="ltr">
            {{ testResult.error }}
        </p>

        <div v-if="blockingMissing.length" class="rounded bg-destructive/10 px-2 py-1.5 text-foreground">
            <p>{{ t('settings.shopify.form.missing_scopes') }}</p>
            <ul class="mt-1 flex flex-wrap gap-1.5" dir="ltr">
                <li v-for="scope in blockingMissing" :key="scope" class="rounded-full border border-destructive/30 bg-card px-2 py-0.5 font-mono text-2xs">
                    {{ scope }}
                </li>
            </ul>
        </div>

        <div v-if="optionalMissing.length" class="rounded bg-warning/10 px-2 py-1.5 text-foreground">
            <p>{{ t('settings.shopify.form.optional_missing_scopes') }}</p>
            <ul class="mt-1 flex flex-wrap gap-1.5" dir="ltr">
                <li v-for="scope in optionalMissing" :key="scope" class="rounded-full border border-warning/30 bg-card px-2 py-0.5 font-mono text-2xs">
                    {{ scope }}
                </li>
            </ul>
        </div>
    </form>
</template>
