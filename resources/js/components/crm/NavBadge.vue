<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';

/**
 * A count of waiting work (DESIGN §3 Badge): numbers only, grey for ordinary waiting work, red once it is past its
 * SLA. `rail` sits on the aubergine sidebar, `surface` on a page (tabs).
 */
withDefaults(defineProps<{ count: number; urgent?: boolean; tone?: 'rail' | 'surface' }>(), { urgent: false, tone: 'surface' });

const { t, locale } = useI18n();
</script>

<template>
    <span
        v-if="count > 0"
        data-nav-badge
        :data-urgent="urgent ? 'true' : undefined"
        class="inline-grid h-5 min-w-5 shrink-0 place-items-center rounded-full px-1.5 text-[11.5px] font-bold leading-none tabular-nums"
        :class="urgent ? 'bg-danger-solid text-white' : tone === 'rail' ? 'bg-rail-line text-rail-fg' : 'bg-sunken text-fg-2'"
    >
        {{ formatCount(count, locale) }}<span v-if="urgent" class="sr-only"> {{ t('nav.badge_late') }}</span>
    </span>
</template>
