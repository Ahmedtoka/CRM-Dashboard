<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import type { UserRef } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{ viewers: UserRef[]; meId: number; typing: string[] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const others = computed(() => props.viewers.filter((v) => v.id !== props.meId));
const names = computed(() => others.value.map((v) => v.name).join(locale.value === 'ar' ? '، ' : ', '));
const typingText = computed(() => (props.typing.length ? t('thread.typing', { name: props.typing.join(locale.value === 'ar' ? '، ' : ', ') }) : ''));
</script>

<template>
    <div v-if="others.length || typing.length" class="flex min-w-0 items-center gap-2">
        <div v-if="others.length" class="flex shrink-0 -space-x-1.5 rtl:space-x-reverse" :title="`${t('thread.viewing')}: ${names}`">
            <span class="sr-only">{{ t('thread.viewing') }}: {{ names }}</span>
            <span
                v-for="viewer in others.slice(0, 4)"
                :key="viewer.id"
                aria-hidden="true"
                class="flex size-5 items-center justify-center rounded-full border-2 border-card text-[9px] font-semibold text-white"
                :style="{ backgroundColor: viewer.color || '#64748b' }"
            >
                {{ getInitials(viewer.name) }}
            </span>
            <span
                v-if="others.length > 4"
                aria-hidden="true"
                class="flex size-5 items-center justify-center rounded-full border-2 border-card bg-slate-400 text-[9px] font-semibold text-white"
            >
                +{{ others.length - 4 }}
            </span>
        </div>
        <span v-if="typingText" class="truncate text-2xs font-medium text-primary" aria-live="polite">{{ typingText }}</span>
    </div>
</template>
