<script setup lang="ts">
import FilterBar from '@/components/crm/FilterBar.vue';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import type { CommentFilters, CommentIntent, CommentStatus } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** The comments feed's filter bar: platform and status inline, intent and «الإعلانات فقط» under «فلاتر». */
const props = defineProps<{ filters: CommentFilters; adOnly: boolean; summary?: string }>();
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

const chips = computed(() => {
    const f = props.filters;
    const out: { key: string; label: string }[] = [];
    if (f.platform) out.push({ key: 'platform', label: platforms.value.find((p) => p.value === f.platform)?.label ?? f.platform });
    if (f.status) out.push({ key: 'status', label: t(`comments.status.${f.status}`) });
    if (f.intent) out.push({ key: 'intent', label: t(`comments.intent.${f.intent}`) });
    if (f.post_id) out.push({ key: 'post_id', label: t('comments.post_chip', { id: f.post_id }) });
    if (props.adOnly) out.push({ key: 'ad', label: t('comments.ad_only') });
    return out;
});
const moreCount = computed(() => (props.filters.intent ? 1 : 0) + (props.adOnly ? 1 : 0));

function remove(key: string): void {
    if (key === 'ad') emit('update:adOnly', false);
    else set({ [key]: null } as Partial<CommentFilters>);
}

// «الإعلانات فقط» goes first: the filters emit starts the visit, which carries `ad` from the URL as it is then.
function clear(): void {
    emit('update:adOnly', false);
    emit('update:filters', { status: null, intent: null, platform: null, post_id: null });
}

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;
const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-xs sm:w-auto';
const fieldLabel = 'mb-1 block text-2xs font-medium text-muted-foreground';
</script>

<template>
    <section :aria-label="t('comments.filters')">
        <FilterBar :chips="chips" :more-count="moreCount" :summary="summary" @remove="remove" @clear="clear">
            <template #inline>
                <select
                    :value="filters.platform ?? ''"
                    :class="selectClass"
                    :aria-label="t('ui.platforms')"
                    @change="set({ platform: selectValue($event) as PlatformValue | null })"
                >
                    <option value="">{{ t('ui.all_platforms') }}</option>
                    <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <select
                    :value="filters.status ?? ''"
                    :class="selectClass"
                    :aria-label="t('comments.status_label')"
                    @change="set({ status: selectValue($event) as CommentStatus | null })"
                >
                    <option value="">{{ t('comments.status_all') }}</option>
                    <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`comments.status.${s}`) }}</option>
                </select>
            </template>
            <template #more>
                <label class="block">
                    <span :class="fieldLabel">{{ t('comments.intent_label') }}</span>
                    <select
                        :value="filters.intent ?? ''"
                        :class="[selectClass, 'sm:w-full']"
                        @change="set({ intent: selectValue($event) as CommentIntent | null })"
                    >
                        <option value="">{{ t('comments.intent_all') }}</option>
                        <option v-for="i in INTENTS" :key="i" :value="i">{{ t(`comments.intent.${i}`) }}</option>
                    </select>
                </label>
                <label class="flex items-start gap-2 text-xs">
                    <input
                        type="checkbox"
                        class="mt-0.5 rounded border-input"
                        :checked="adOnly"
                        @change="emit('update:adOnly', ($event.target as HTMLInputElement).checked)"
                    />
                    <span>
                        <span class="font-medium text-foreground">{{ t('comments.ad_only') }}</span>
                        <span class="block text-2xs text-muted-foreground">{{ t('comments.ad_only_hint') }}</span>
                    </span>
                </label>
            </template>
        </FilterBar>
    </section>
</template>
