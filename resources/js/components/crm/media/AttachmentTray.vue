<script setup lang="ts">
import FileChip from '@/components/crm/media/FileChip.vue';
import { useI18n } from '@/composables/useI18n';
import type { PendingUpload } from '@/types/crm';
import { RotateCw, X } from 'lucide-vue-next';

defineProps<{ items: PendingUpload[] }>();
const emit = defineEmits<{ remove: [key: string]; retry: [key: string] }>();

const { t } = useI18n();
</script>

<template>
    <div class="mb-2 flex flex-wrap items-start gap-2">
        <div v-for="item in items" :key="item.key" class="flex max-w-32 flex-col gap-1">
            <div class="relative">
                <img v-if="item.previewUrl" :src="item.previewUrl" :alt="item.name" class="size-14 rounded-md border object-cover" />
                <FileChip v-else :name="item.name" :size="item.size" :mime="null" />

                <div
                    v-if="!item.attachment && !item.error"
                    class="absolute inset-x-0 bottom-0 h-1 overflow-hidden rounded-b-md bg-black/10"
                    role="progressbar"
                    :aria-valuenow="item.progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div class="h-full bg-primary transition-all" :style="{ width: `${item.progress}%` }" />
                </div>

                <button
                    type="button"
                    :aria-label="t('media.remove')"
                    class="absolute end-0.5 top-0.5 flex size-4 items-center justify-center rounded-full bg-black/60 text-white hover:bg-black/80"
                    @click="emit('remove', item.key)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </div>

            <div v-if="item.error" class="flex flex-col gap-0.5">
                <span class="line-clamp-2 text-2xs leading-tight text-red-600">{{ item.error }}</span>
                <button type="button" class="inline-flex items-center gap-0.5 self-start text-2xs font-medium text-primary hover:underline" @click="emit('retry', item.key)">
                    <RotateCw class="size-3" aria-hidden="true" />{{ t('common.retry') }}
                </button>
            </div>
        </div>
    </div>
</template>
