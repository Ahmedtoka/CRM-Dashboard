<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { hubItemLabel, hubItemState, type HubGroup } from '@/lib/settingsHub';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, type LucideIcon } from 'lucide-vue-next';

/** One group of the settings hub (DESIGN §3 SettingsHubCard): icon, name, a line on what's inside, then each item with its state. */
defineProps<{ group: HubGroup; icon: LucideIcon; tone: 'brand' | 'info' | 'warn' | 'neutral' }>();

const { t, dir } = useI18n();
const tiles = {
    brand: 'bg-brand-soft text-brand-ink',
    info: 'bg-info-soft text-info',
    warn: 'bg-warn-soft text-warn',
    neutral: 'bg-sunken text-fg-2',
};
const dots = { ok: 'bg-ok', warn: 'bg-warn-solid', danger: 'bg-danger-solid', off: 'bg-fg-3' };
</script>

<template>
    <section
        :id="`settings-${group.id}`"
        class="flex flex-col gap-2.5 rounded-lg border border-line bg-card p-4"
        :aria-labelledby="`settings-${group.id}-title`"
    >
        <div class="flex items-center gap-2.5">
            <span class="grid size-9 shrink-0 place-items-center rounded-md" :class="tiles[tone]">
                <component :is="icon" class="size-[18px]" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <h2 :id="`settings-${group.id}-title`" class="text-[15px] font-bold leading-snug">
                    {{ t(`settings_hub.groups.${group.id}.title`) }}
                </h2>
                <p class="text-xs text-fg-2">{{ t(`settings_hub.groups.${group.id}.description`) }}</p>
            </div>
        </div>
        <ul>
            <li v-for="item in group.items" :key="item.id">
                <Link
                    :href="item.href"
                    class="-mx-2.5 flex min-h-10 items-center gap-2.5 rounded-md px-2.5 py-2 text-start transition-colors hover:bg-surface-2"
                    :data-hub-item="item.id"
                >
                    <span class="flex-1 text-[13.5px] font-semibold">{{ t(hubItemLabel(item)) }}</span>
                    <span v-if="hubItemState(item, t)" class="flex min-w-0 items-center gap-1.5 truncate text-xs text-fg-2">
                        <i
                            v-if="hubItemState(item, t)?.tone"
                            class="size-[7px] shrink-0 rounded-full"
                            :class="dots[hubItemState(item, t)!.tone!]"
                            aria-hidden="true"
                        />
                        <bdi class="truncate">{{ hubItemState(item, t)?.text }}</bdi>
                    </span>
                    <component :is="dir === 'rtl' ? ChevronLeft : ChevronRight" class="size-4 shrink-0 text-fg-3" aria-hidden="true" />
                </Link>
            </li>
        </ul>
    </section>
</template>
