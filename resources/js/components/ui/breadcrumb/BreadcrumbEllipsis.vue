<script lang="ts" setup>
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import { MoreHorizontal } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

const { t } = useI18n();

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <span role="presentation" aria-hidden="true" :class="cn('flex h-9 w-9 items-center justify-center', props.class)">
        <slot>
            <MoreHorizontal class="h-4 w-4" />
        </slot>
        <span class="sr-only">{{ t('common.more') }}</span>
    </span>
</template>
