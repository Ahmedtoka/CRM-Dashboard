<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatDuration } from '@/lib/format';
import { Send, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ elapsed: number; max: number }>();
const emit = defineEmits<{ cancel: []; send: [] }>();

const { t, locale } = useI18n();

const time = computed(() => formatDuration(props.elapsed * 1000, locale.value));
</script>

<template>
    <div class="flex flex-1 items-center gap-2 px-1 py-1.5">
        <span class="size-2.5 shrink-0 animate-pulse rounded-full bg-red-600" aria-hidden="true" />
        <span class="min-w-0 flex-1 text-sm tabular-nums text-foreground">{{ t('media.recording', { time }) }}</span>
        <button
            type="button"
            :aria-label="t('media.cancel_recording')"
            class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
            @click="emit('cancel')"
        >
            <X class="size-4" aria-hidden="true" />
        </button>
        <button
            type="button"
            :aria-label="t('media.send_voice')"
            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground hover:bg-primary/90"
            @click="emit('send')"
        >
            <Send class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
