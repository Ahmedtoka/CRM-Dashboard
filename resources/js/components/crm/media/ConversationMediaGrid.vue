<script setup lang="ts">
import AudioPlayer from '@/components/crm/media/AudioPlayer.vue';
import FileChip from '@/components/crm/media/FileChip.vue';
import MediaLightbox from '@/components/crm/media/MediaLightbox.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import type { Attachment } from '@/types/crm';
import axios from 'axios';
import { CircleAlert, Images, LoaderCircle, Play, Video } from 'lucide-vue-next';
import { computed, onScopeDispose, ref, watch } from 'vue';

const props = defineProps<{ conversationId: number }>();

const { t } = useI18n();
const api = useApi();

const items = ref<Attachment[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const lightboxIndex = ref<number | null>(null);

const visualItems = computed(() => items.value.filter((a) => a.type === 'image' || a.type === 'video'));
const otherItems = computed(() => items.value.filter((a) => a.type === 'audio' || a.type === 'file'));

function openLightbox(attachment: Attachment): void {
    const index = visualItems.value.findIndex((a) => a.id === attachment.id);
    if (index !== -1) lightboxIndex.value = index;
}

let controller: AbortController | null = null;
let requestSeq = 0;

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const seq = ++requestSeq;
    const conversationId = props.conversationId;
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get<{ data: Attachment[] }>(`/inbox/conversations/${conversationId}/media`, { signal: controller.signal });
        if (seq !== requestSeq || conversationId !== props.conversationId) return; // a newer request already landed
        items.value = data.data;
    } catch (e) {
        if (axios.isCancel(e) || seq !== requestSeq) return;
        loadError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        if (seq === requestSeq) loading.value = false;
    }
}

watch(() => props.conversationId, load, { immediate: true });

onScopeDispose(() => controller?.abort());
</script>

<template>
    <div class="space-y-4 p-4">
        <div v-if="loading" class="flex flex-1 items-center justify-center py-8" aria-busy="true">
            <LoaderCircle class="size-5 animate-spin text-muted-foreground" aria-hidden="true" />
        </div>

        <EmptyState v-else-if="loadError" :icon="CircleAlert" :title="loadError">
            <button type="button" class="text-xs text-primary hover:underline" @click="load">{{ t('common.retry') }}</button>
        </EmptyState>

        <EmptyState v-else-if="!items.length" :icon="Images" :title="t('media.empty')" />

        <template v-else>
            <div v-if="visualItems.length" class="grid grid-cols-3 gap-1">
                <button
                    v-for="attachment in visualItems"
                    :key="attachment.id"
                    type="button"
                    class="relative aspect-square overflow-hidden rounded-md bg-muted"
                    @click="openLightbox(attachment)"
                >
                    <img v-if="attachment.thumb_url" :src="attachment.thumb_url" :alt="attachment.original_name ?? ''" class="size-full object-cover" />
                    <span v-else class="flex size-full items-center justify-center">
                        <Video class="size-6 text-muted-foreground" aria-hidden="true" />
                    </span>
                    <span v-if="attachment.type === 'video'" class="absolute inset-0 flex items-center justify-center bg-black/20">
                        <Play class="size-5 fill-white text-white" aria-hidden="true" />
                    </span>
                </button>
            </div>

            <div v-if="otherItems.length" class="space-y-2">
                <AudioPlayer
                    v-for="attachment in otherItems.filter((a) => a.type === 'audio')"
                    :key="attachment.id"
                    :src="attachment.url ?? ''"
                    :duration-ms="attachment.duration_ms"
                    preload="none"
                />
                <FileChip
                    v-for="attachment in otherItems.filter((a) => a.type === 'file')"
                    :key="attachment.id"
                    :name="attachment.original_name ?? ''"
                    :size="attachment.size_bytes"
                    :mime="attachment.mime"
                    :href="attachment.url"
                />
            </div>
        </template>

        <MediaLightbox v-model:index="lightboxIndex" :items="visualItems" />
    </div>
</template>
