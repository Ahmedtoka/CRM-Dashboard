<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatBytes } from '@/lib/format';
import { File, FileArchive, FileSpreadsheet, FileText } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ name: string; size: number | null; mime: string | null; href?: string | null }>();

const { t, locale } = useI18n();

const icon = computed(() => {
    const mime = (props.mime ?? '').toLowerCase();
    const name = props.name.toLowerCase();
    if (mime.includes('pdf') || mime.includes('word') || /\.(pdf|docx?)$/.test(name)) return FileText;
    if (mime.includes('sheet') || mime.includes('csv') || /\.(xlsx?|csv)$/.test(name)) return FileSpreadsheet;
    if (mime.includes('zip') || mime.includes('archive') || /\.(zip|rar|7z)$/.test(name)) return FileArchive;
    return File;
});
</script>

<template>
    <component
        :is="href ? 'a' : 'div'"
        :href="href ?? undefined"
        :download="href ? name : undefined"
        class="flex max-w-64 items-center gap-2 rounded-lg border bg-card px-2.5 py-2 text-xs"
        :class="{ 'hover:bg-muted': href }"
    >
        <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
            <component :is="icon" class="size-4" aria-hidden="true" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium" dir="auto">{{ name }}</span>
            <span v-if="size !== null" class="block text-2xs text-muted-foreground">{{ formatBytes(size, locale) }}</span>
        </span>
        <span v-if="href" class="sr-only">{{ t('media.download') }}</span>
    </component>
</template>
