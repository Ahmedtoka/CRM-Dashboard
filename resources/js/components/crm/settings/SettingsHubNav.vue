<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { hubItemLabel, type HubNavGroup } from '@/lib/settingsHub';
import { Link } from '@inertiajs/vue3';
import { LayoutGrid } from 'lucide-vue-next';

/** The settings groups as a side list (mockup `.hub-nav`): «كل الإعدادات» first, then each group's items; the page you are on is marked. */
defineProps<{ groups: HubNavGroup[]; current: string | null }>();

const { t } = useI18n();
const base = 'flex w-full items-center gap-2 rounded-md px-2.5 py-[7px] text-[13.5px] transition-colors';
const on = 'bg-card font-semibold text-brand-ink shadow-e1';
const off = 'font-medium text-fg-2 hover:bg-card hover:text-foreground';
</script>

<template>
    <nav class="flex-col gap-3.5" :aria-label="t('settings_hub.groups_label')" data-settings-nav>
        <Link href="/settings" :class="[base, current === null ? on : off]" :aria-current="current === null ? 'page' : undefined">
            <LayoutGrid class="size-4" aria-hidden="true" />{{ t('settings_hub.all') }}
        </Link>
        <div v-for="group in groups" :key="group.id">
            <p class="mb-1 px-2.5 text-xs font-semibold text-fg-3">{{ t(`settings_hub.groups.${group.id}.title`) }}</p>
            <Link
                v-for="item in group.items"
                :key="item.id"
                :href="item.href"
                :class="[base, current === item.id ? on : off]"
                :aria-current="current === item.id ? 'page' : undefined"
            >
                {{ t(hubItemLabel({ id: item.id, href: item.href, personal: item.personal })) }}
            </Link>
        </div>
    </nav>
</template>
