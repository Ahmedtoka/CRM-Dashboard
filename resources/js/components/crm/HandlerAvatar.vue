<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import type { Handling, UserRef } from '@/types/crm';
import { Lock } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ handling: Handling | null; viewers?: UserRef[]; meId?: number; size?: 'xs' | 'sm' }>(), {
    viewers: () => [],
    meId: undefined,
    size: 'xs',
});

const { t } = useI18n();
const { getInitials } = useInitials();

// Up to 3 stacked viewer dots after the handler, excluding the current user —
// the handler avatar itself already covers the lock holder / recent responder.
const otherViewers = computed(() => props.viewers.filter((v) => v.id !== props.meId && v.id !== props.handling?.id).slice(0, 3));

const dimension = computed(() => (props.size === 'sm' ? 'size-7' : 'size-5'));
const fontSize = computed(() => (props.size === 'sm' ? 'text-2xs' : 'text-[9px]'));
const dotSize = computed(() => (props.size === 'sm' ? 'size-5' : 'size-4'));

const title = computed(() => {
    if (!props.handling) return '';
    return props.handling.via === 'lock' ? t('handling.lock', { name: props.handling.name }) : t('handling.recent', { name: props.handling.name });
});

const hasAnything = computed(() => !!props.handling || otherViewers.value.length > 0);
</script>

<template>
    <span v-if="hasAnything" class="flex shrink-0 items-center gap-1">
        <span
            v-if="handling"
            class="relative flex items-center justify-center rounded-full font-semibold text-white"
            :class="[dimension, fontSize]"
            :style="{ backgroundColor: handling.color || 'hsl(var(--primary))' }"
            :title="title"
            :aria-label="title"
        >
            {{ getInitials(handling.name) }}
            <span
                v-if="handling.via === 'lock'"
                class="absolute -bottom-0.5 -end-0.5 flex size-3 items-center justify-center rounded-full bg-card text-foreground shadow-card"
                aria-hidden="true"
            >
                <Lock class="size-2" />
            </span>
        </span>

        <span v-if="otherViewers.length" class="flex -space-x-1.5 rtl:space-x-reverse" :title="t('handling.viewers')">
            <span
                v-for="viewer in otherViewers"
                :key="viewer.id"
                aria-hidden="true"
                class="flex items-center justify-center rounded-full border-2 border-card font-semibold text-white"
                :class="[dotSize, fontSize]"
                :style="{ backgroundColor: viewer.color || '#64748b' }"
            >
                {{ getInitials(viewer.name) }}
            </span>
        </span>
    </span>
</template>
