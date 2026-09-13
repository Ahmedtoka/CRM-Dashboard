<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { Customer } from '@/types/crm';
import { Link, router } from '@inertiajs/vue3';
import { LoaderCircle, Users } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const props = defineProps<{ customerId: number; canMerge: boolean }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();

const suggestions = ref<Customer[]>([]);
const loading = ref(true);
const merging = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await api.get<{ data: Customer[] }>(`/customers/${props.customerId}/merge-suggestions`);
        suggestions.value = data.data;
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        loading.value = false;
    }
}

async function merge(other: Customer): Promise<void> {
    if (!window.confirm(t('customers.merge_confirm', { name: other.name ?? `#${other.id}` }))) return;
    merging.value = other.id;
    try {
        await api.post(`/customers/${props.customerId}/merge`, { other_id: other.id });
        toast.push(t('customers.merged'));
        suggestions.value = suggestions.value.filter((c) => c.id !== other.id);
        router.reload();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        merging.value = null;
    }
}

onMounted(load);
</script>

<template>
    <section class="rounded-lg border bg-card p-3 text-xs" :aria-busy="loading">
        <h2 class="flex items-center gap-1.5 font-medium"><Users class="size-3.5" aria-hidden="true" />{{ t('customers.duplicates') }}</h2>
        <p class="mb-2 text-2xs text-muted-foreground">{{ t('customers.duplicates_hint') }}</p>
        <LoaderCircle v-if="loading" class="size-4 animate-spin text-muted-foreground" aria-hidden="true" />
        <p v-else-if="!suggestions.length" class="text-muted-foreground">{{ t('customers.no_duplicates') }}</p>
        <ul v-else class="space-y-2">
            <li v-for="other in suggestions" :key="other.id" class="flex items-center gap-2 rounded-md border px-2 py-1.5">
                <div class="min-w-0 flex-1">
                    <Link :href="`/customers/${other.id}`" class="block truncate font-medium hover:underline">{{ other.name ?? `#${other.id}` }}</Link>
                    <span class="flex items-center gap-1 text-2xs text-muted-foreground">
                        <span dir="ltr">{{ other.phone }}</span>
                        <PlatformBadge v-for="identity in other.identities ?? []" :key="identity.id" :platform="identity.platform" size="xs" />
                    </span>
                </div>
                <button
                    v-if="canMerge"
                    type="button"
                    class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 font-medium hover:bg-muted disabled:opacity-50"
                    :disabled="merging !== null"
                    @click="merge(other)"
                >
                    <LoaderCircle v-if="merging === other.id" class="size-3 animate-spin" aria-hidden="true" />{{ t('customers.merge') }}
                </button>
            </li>
        </ul>
    </section>
</template>
