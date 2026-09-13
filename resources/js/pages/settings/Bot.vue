<script setup lang="ts">
import BotSettingsForm from '@/components/crm/BotSettingsForm.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import RuleForm from '@/components/crm/RuleForm.vue';
import RuleTester from '@/components/crm/RuleTester.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import type { BotRule, BotRuleInput, BotSettings } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{ settings: BotSettings; rules: BotRule[]; canEditAi: boolean }>();

const { t, locale } = useI18n();
const crud = useCrud('/settings/bot/rules', 'rules');

const formOpen = ref(false);
const editing = ref<BotRule | null>(null);

function openForm(rule: BotRule | null): void {
    editing.value = rule;
    crud.error.value = null;
    formOpen.value = true;
}

async function submit(id: number | null, payload: BotRuleInput): Promise<void> {
    if (await crud.save(id, { ...payload })) formOpen.value = false;
}

const columns = computed<Column[]>(() => [
    { key: 'priority', label: t('settings.rules.priority'), align: 'end' },
    { key: 'name', label: t('settings.rules.name') },
    { key: 'scope', label: t('settings.rules.scope_label') },
    { key: 'keywords', label: t('settings.rules.keywords') },
    { key: 'action', label: t('settings.rules.action_label') },
    { key: 'hits', label: t('settings.rules.hits'), align: 'end' },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.bot.title'), href: '/settings/bot' }]);
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.bot.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('settings.bot.title')" />

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                <BotSettingsForm :settings="settings" :can-edit-ai="canEditAi" />
                <RuleTester class="xl:self-start" />
            </div>

            <section class="space-y-2">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium">{{ t('settings.bot.rules') }}</h2>
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="openForm(null)">
                        <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.bot.add_rule') }}
                    </button>
                </div>
                <DataTable :columns="columns" :rows="rules" :empty="t('settings.bot.rules_empty')" :caption="t('settings.bot.rules')">
                    <template #cell-priority="{ row }"><span class="tabular-nums">{{ row.priority }}</span></template>
                    <template #cell-name="{ row }">
                        <span class="flex items-center gap-1.5">
                            <span class="font-medium" dir="auto">{{ row.name }}</span>
                            <StatusChip v-if="!row.is_active" :label="t('ui.inactive')" />
                            <PlatformBadge v-for="p in row.platforms ?? []" :key="p" :platform="p" size="xs" />
                        </span>
                    </template>
                    <template #cell-scope="{ row }">{{ t(`settings.rules.scope.${row.scope}`) }}</template>
                    <template #cell-keywords="{ row }">
                        <span class="line-clamp-1 max-w-xs text-muted-foreground" dir="auto">{{ row.keywords.join('، ') }}</span>
                    </template>
                    <template #cell-action="{ row }"><span class="whitespace-nowrap">{{ t(`settings.rules.action.${row.action}`) }}</span></template>
                    <template #cell-hits="{ row }"><span class="tabular-nums">{{ formatCount(row.hits, locale) }}</span></template>
                    <template #cell-actions="{ row }">
                        <span class="inline-flex gap-0.5">
                            <button type="button" :class="iconBtn" :aria-label="`${t('ui.edit')} ${row.name}`" :title="t('ui.edit')" @click="openForm(row)"><Pencil class="size-3.5" /></button>
                            <button type="button" :class="[iconBtn, 'hover:text-red-700']" :aria-label="`${t('ui.delete')} ${row.name}`" :title="t('ui.delete')" @click="crud.remove(row.id, t('ui.confirm_delete', { name: row.name }))">
                                <Trash2 class="size-3.5" />
                            </button>
                        </span>
                    </template>
                </DataTable>
            </section>
        </div>

        <RuleForm v-model:open="formOpen" :rule="editing" :busy="crud.busy.value" :error="crud.error.value" @submit="submit" />
    </AppLayout>
</template>
