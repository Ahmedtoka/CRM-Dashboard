<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SimBurstPanel from '@/components/crm/SimBurstPanel.vue';
import SimCommentPanel from '@/components/crm/SimCommentPanel.vue';
import SimMessagePanel from '@/components/crm/SimMessagePanel.vue';
import SimOrdersPanel from '@/components/crm/SimOrdersPanel.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrderRow, SimPost, SimShipment } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ awaitingPayment: OrderRow[]; shipments: SimShipment[]; posts: SimPost[] }>();

const { t } = useI18n();
const breadcrumbs = computed(() => [{ title: t('simulator.title'), href: '/simulator' }]);
</script>

<template>
    <Head :title="t('simulator.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('simulator.title')" :description="t('simulator.description')" />
            <div class="grid gap-4 lg:grid-cols-3">
                <SimMessagePanel />
                <SimCommentPanel :posts="posts" />
                <SimBurstPanel />
            </div>
            <SimOrdersPanel :orders="awaitingPayment" :shipments="shipments" />
        </div>
    </AppLayout>
</template>
