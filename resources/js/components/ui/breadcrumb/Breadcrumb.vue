<script lang="ts" setup>
import { useI18n } from '@/composables/useI18n';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const { t } = useI18n();
</script>

<template>
    <nav :aria-label="t('nav.breadcrumb')" :class="props.class">
        <slot />
    </nav>
</template>
