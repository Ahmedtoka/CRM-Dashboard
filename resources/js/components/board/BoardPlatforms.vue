<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { PlatformValue } from '@/types/crm';
import { computed } from 'vue';

// The platforms a moderator may serve, as small coloured marks (the room's own drawings).
const props = defineProps<{ platforms: PlatformValue[] }>();

const { t } = useI18n();

const COLOURS: Record<PlatformValue, string> = { facebook: '#1877f2', instagram: '#e1306c', whatsapp: '#25d366', tiktok: '#111827' };

const label = computed(() =>
    props.platforms.length === 0
        ? t('board.platforms.none')
        : t('board.platforms.serves', { list: props.platforms.map((p) => t(`board.platforms.${p}`)).join(t('board.platforms.separator')) }),
);
</script>

<template>
    <span class="inline-flex items-center gap-1" role="img" :aria-label="label" :title="label">
        <span v-for="p in platforms" :key="p" class="grid size-4 place-items-center rounded-full text-white" :style="{ background: COLOURS[p] }">
            <svg class="size-2.5" aria-hidden="true"><use :href="`#br-i-${p}`" /></svg>
        </span>
        <span v-if="platforms.length === 0" class="text-2xs text-muted-foreground">{{ t('board.platforms.none') }}</span>
    </span>
</template>
