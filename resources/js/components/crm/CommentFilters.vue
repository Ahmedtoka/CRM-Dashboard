<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import type { CommentFilters, CommentIntent, CommentStatus } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ filters: CommentFilters; adOnly: boolean }>();
const emit = defineEmits<{ 'update:filters': [filters: CommentFilters]; 'update:adOnly': [value: boolean] }>();

const { t } = useI18n();
const page = usePage<SharedData>();

const STATUSES: CommentStatus[] = ['new', 'replied', 'hidden', 'ignored'];
const INTENTS: CommentIntent[] = ['buy', 'question', 'complaint', 'spam', 'other'];

const platforms = computed(() => {
    const user = page.props.auth.user;
    const all = page.props.platforms ?? [];
    return user.role === 'moderator' ? all.filter((p) => user.platforms?.includes(p.value)) : all;
});

function set(patch: Partial<CommentFilters>): void {
    emit('update:filters', { ...props.filters, ...patch });
}

const hasFilters = computed(() => Object.values(props.filters).some((v) => v !== null) || props.adOnly);

const chip = (active: boolean) =>
    active ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground';
</script>

<template>
    <aside class="space-y-4 text-xs" :aria-label="t('comments.filters')">
        <fieldset>
            <legend class="mb-1.5 font-medium text-foreground">{{ t('ui.platforms') }}</legend>
            <div class="flex flex-wrap gap-1.5 lg:flex-col">
                <button type="button" class="rounded-md border px-2.5 py-1 text-start" :class="chip(filters.platform === null)" :aria-pressed="filters.platform === null" @click="set({ platform: null })">
                    {{ t('ui.all_platforms') }}
                </button>
                <button
                    v-for="p in platforms"
                    :key="p.value"
                    type="button"
                    class="rounded-md border px-2.5 py-1 text-start"
                    :class="chip(filters.platform === p.value)"
                    :aria-pressed="filters.platform === p.value"
                    @click="set({ platform: filters.platform === p.value ? null : (p.value as PlatformValue) })"
                >
                    {{ p.label }}
                </button>
            </div>
        </fieldset>

        <fieldset>
            <legend class="mb-1.5 font-medium text-foreground">{{ t('comments.status_label') }}</legend>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="s in STATUSES" :key="s" type="button" class="rounded-full border px-2.5 py-1" :class="chip(filters.status === s)" :aria-pressed="filters.status === s" @click="set({ status: filters.status === s ? null : s })">
                    {{ t(`comments.status.${s}`) }}
                </button>
            </div>
        </fieldset>

        <fieldset>
            <legend class="mb-1.5 font-medium text-foreground">{{ t('comments.intent_label') }}</legend>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="i in INTENTS" :key="i" type="button" class="rounded-full border px-2.5 py-1" :class="chip(filters.intent === i)" :aria-pressed="filters.intent === i" @click="set({ intent: filters.intent === i ? null : i })">
                    {{ t(`comments.intent.${i}`) }}
                </button>
            </div>
        </fieldset>

        <label class="flex items-start gap-2">
            <input type="checkbox" class="mt-0.5 rounded border-input" :checked="adOnly" @change="emit('update:adOnly', ($event.target as HTMLInputElement).checked)" />
            <span>
                <span class="font-medium text-foreground">{{ t('comments.ad_only') }}</span>
                <span class="block text-2xs text-muted-foreground">{{ t('comments.ad_only_hint') }}</span>
            </span>
        </label>

        <button v-if="hasFilters" type="button" class="text-primary hover:underline" @click="emit('update:filters', { status: null, intent: null, platform: null, post_id: null }); emit('update:adOnly', false)">
            {{ t('ui.clear_filters') }}
        </button>
    </aside>
</template>
