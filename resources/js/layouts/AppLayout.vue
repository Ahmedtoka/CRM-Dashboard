<script setup lang="ts">
import ToastStack from '@/components/crm/ToastStack.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    /** Full-height pages (inbox): the content area is exactly one viewport tall and the page fills what's left with flex. */
    fill?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fill: false,
});

const page = usePage<SharedData>();
const { t } = useI18n();

const alerts = computed(() => page.props.channelAlerts ?? []);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :content-class="fill ? 'h-svh max-h-svh overflow-hidden' : undefined">
        <div v-if="alerts.length" role="alert" class="border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-xs text-amber-900">
            <p v-for="alert in alerts" :key="alert.id" class="flex items-center gap-2">
                <TriangleAlert class="size-3.5 shrink-0" />
                {{ t('alerts.channel_error', { name: alert.name, error: alert.last_error ?? '' }) }}
            </p>
        </div>
        <slot />
        <ToastStack />
    </AppLayout>
</template>
