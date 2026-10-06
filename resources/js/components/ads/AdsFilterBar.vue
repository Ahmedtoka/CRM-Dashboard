<script setup lang="ts">
/** One filter bar for every Ads page (U 4.1–4.4, spec 4.6). All state in the URL; presets are links. */
import DateInput from '@/components/crm/DateInput.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { AD_PLATFORM_LABELS, formatDayLong } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import { activePreset, BASE_KEYS, buildHref, LIST_KEYS, PRESETS, presetQuery, readQuery, withParam, type Query } from '@/lib/adsFilters';
import type { AdPlatformValue, AdsAccess, AdsAccountOption, AdsControlFilters, AdsExplorerFilters, AdsOption } from '@/types/ads';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        path: string;
        filters: AdsControlFilters & Partial<AdsExplorerFilters>;
        accountOptions: AdsAccountOption[];
        buyers: AdsOption[];
        platforms: string[];
        defaults?: Query;
        show?: { range?: boolean; status?: boolean; list?: boolean; presets?: boolean; search?: boolean };
    }>(),
    { defaults: () => ({}), show: () => ({}) },
);

const { t, locale } = useI18n();
const page = usePage();
const access = computed(() => (page.props.ads ?? null) as AdsAccess | null);
const can = (k: 'range' | 'status' | 'list' | 'presets' | 'search') => props.show[k] ?? (k === 'search' ? props.show.list !== false : true);

/** Every change starts from the address: params this bar does not own (`ad`, `open`, `tab`, `section`) stay. */
const current = (): Query => readQuery(window.location.search);
function go(next: Query, replace = true): void {
    router.get(props.path, next, { preserveState: true, preserveScroll: true, replace });
}
const set = (key: string, value: string | null) => go(withParam(current(), key, value, props.defaults));

/* range: named ranges, or custom dates */
const RANGES = ['today', 'yesterday', 'last7', 'last30', 'this_month'] as const;
const custom = ref(props.filters.range === null);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
watch(
    () => [props.filters.from, props.filters.to, props.filters.range] as const,
    ([f, tt, r]) => {
        from.value = f;
        to.value = tt;
        if (r !== null) custom.value = false;
    },
);
function setRange(value: string): void {
    if (value === 'custom') {
        custom.value = true;
        return;
    }
    custom.value = false;
    const q = current();
    delete q.from;
    delete q.to;
    go(withParam(q, 'range', value));
}
const datesInvalid = computed(() => !from.value || !to.value || to.value < from.value);
function applyDates(): void {
    if (datesInvalid.value) return;
    const q = current();
    delete q.range;
    go(withParam(withParam(q, 'from', from.value), 'to', to.value));
}

/* accounts */
const accountSearch = ref('');
const picked = computed(() => new Set(props.filters.accounts));
const shownAccounts = computed(() => props.accountOptions.filter((a) => a.name.toLowerCase().includes(accountSearch.value.trim().toLowerCase())));
function toggleAccount(id: number): void {
    const next = new Set(picked.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    set('accounts', next.size ? [...next].join(',') : null);
}
const accountsLabel = computed(() =>
    props.filters.accounts.length
        ? t('ads.control.filter.summary_accounts', { n: formatCount(props.filters.accounts.length, locale.value), total: formatCount(props.accountOptions.length, locale.value) })
        : t('ads.control.filter.accounts_all'),
);

/* chips */
const CHIP_KEYS = ['accounts', 'buyer', 'platform', 'objective', 'health', 'changed', 'q'];
const healthLabel = (h: string) => (['top', 'promising', 'neutral'].includes(h) ? (h === 'top' ? t('ads.control.explorer.tier_top') : t(`ads.tier.${h}`)) : t(`ads.control.health_filter.${h}`));
const chips = computed(() => {
    const f = props.filters;
    const out: { key: string; label: string }[] = [];
    if (f.accounts.length) out.push({ key: 'accounts', label: accountsLabel.value });
    if (f.buyer !== null && f.buyer !== undefined && access.value?.isBuyer !== true) {
        out.push({ key: 'buyer', label: props.buyers.find((b) => b.id === f.buyer)?.name ?? t('ads.control.preset.mine') });
    }
    if (f.platform) out.push({ key: 'platform', label: AD_PLATFORM_LABELS[f.platform as AdPlatformValue] ?? f.platform });
    if (f.objective) out.push({ key: 'objective', label: t(`ads.control.objective.${f.objective}`) });
    if (f.health) out.push({ key: 'health', label: healthLabel(f.health) });
    if (f.changed) out.push({ key: 'changed', label: t('ads.control.filter.changed_today') });
    return out;
});
const moreCount = computed(() => [props.filters.platform, props.filters.objective, props.filters.health].filter(Boolean).length);

/** «مسح الكل» clears what the chips show and the search; range, status, sort, view and the open drawer stay. */
function clearAll(): void {
    const next: Query = {};
    for (const [k, v] of Object.entries(current())) if (!CHIP_KEYS.includes(k) && k !== 'page') next[k] = v;
    go(next);
}

/**
 * The applied filters as a URL query (defaults left out, like the address), plus the params this bar does not own
 * (`ad`, `open`, `tab`) from the address. Presets key on this, so they follow the props after every visit.
 */
const OWNED: readonly string[] = [...BASE_KEYS, ...LIST_KEYS];
const filtersQuery = computed<Query>(() => {
    const f = props.filters;
    const q: Query = {};
    const put = (k: string, v: string | number | null | undefined) => {
        if (v === null || v === undefined || v === '') return;
        if (props.defaults[k] === String(v)) return;
        q[k] = String(v);
    };
    if (f.range) put('range', f.range);
    else {
        put('from', f.from);
        put('to', f.to);
    }
    put('platform', f.platform);
    put('buyer', f.buyer);
    if (f.accounts.length) q.accounts = f.accounts.join(',');
    for (const k of ['status', 'objective', 'health', 'changed', 'q', 'sort', 'view'] as const) put(k, f[k] ?? null);
    if (f.per_page && f.per_page !== 25) q.per_page = String(f.per_page);
    for (const [k, v] of Object.entries(current())) if (!OWNED.includes(k)) q[k] = v;
    return q;
});

/* presets */
const presets = computed(() => {
    if (!can('presets')) return [];
    const q = filtersQuery.value;
    const active = activePreset(q, props.defaults);
    return PRESETS.filter((p) => p.key !== 'mine' || (access.value?.isBuyer !== true && access.value?.buyerId !== null && access.value?.buyerId !== undefined)).map((p) => ({
        key: p.key,
        label: active?.key === p.key && active.modified ? `${t(`ads.control.preset.${p.key}`)} · ${t('ads.control.preset.modified')}` : t(`ads.control.preset.${p.key}`),
        href: buildHref(props.path, presetQuery(q, p.key)),
        active: active?.key === p.key,
    }));
});

/* summary: what is hidden */
const summary = computed(() => {
    const f = props.filters;
    const parts = [f.accounts.length ? accountsLabel.value : t('ads.control.filter.summary_all_accounts')];
    if (f.status === 'running') parts.push(t('ads.control.filter.summary_running'));
    if (f.status === 'paused') parts.push(t('ads.control.filter.summary_paused'));
    parts.push(f.from === f.to ? formatDayLong(f.from, locale.value) : `${formatDayLong(f.from, locale.value)} – ${formatDayLong(f.to, locale.value)}`);
    return parts.join(' · ');
});


const select = 'h-9 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <FilterBar
        :search="filters.q ?? ''"
        :search-placeholder="can('search') ? t('ads.control.filter.search') : undefined"
        :chips="chips"
        :more-count="moreCount"
        :presets="presets"
        :summary="summary"
        @update:search="set('q', $event || null)"
        @remove="set($event, null)"
        @clear="clearAll"
    >
        <template #inline>
            <div v-if="can('range')" class="flex flex-wrap items-center gap-1.5">
                <select
                    :value="custom ? 'custom' : (filters.range ?? 'custom')"
                    :class="select"
                    :aria-label="t('ads.control.range.label')"
                    @change="setRange(($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="r in RANGES" :key="r" :value="r">{{ t(`ads.control.range.${r}`) }}</option>
                    <option value="custom">{{ t('ads.control.range.custom') }}</option>
                </select>
                <form v-if="custom" class="flex flex-wrap items-center gap-1.5" @submit.prevent="applyDates">
                    <label class="sr-only" for="ads-range-from">{{ t('range.from') }}</label>
                    <DateInput id="ads-range-from" v-model="from" class="h-9 rounded-md border border-input bg-background px-2 text-xs" :max="to" />
                    <label class="sr-only" for="ads-range-to">{{ t('range.to') }}</label>
                    <DateInput id="ads-range-to" v-model="to" class="h-9 rounded-md border border-input bg-background px-2 text-xs" :min="from" />
                    <button type="submit" class="h-9 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="datesInvalid">
                        {{ t('range.apply') }}
                    </button>
                </form>
            </div>
            <Popover v-if="accountOptions.length > 1">
                <PopoverTrigger :class="[select, 'inline-flex items-center gap-1']" :aria-label="t('ads.control.filter.accounts')">
                    <span class="max-w-40 truncate">{{ accountsLabel }}</span>
                    <ChevronDown class="size-3.5 text-muted-foreground" aria-hidden="true" />
                </PopoverTrigger>
                <PopoverContent class="w-72 max-w-[calc(100vw-2rem)] space-y-2" :collision-padding="16">
                    <label class="block">
                        <span class="sr-only">{{ t('ads.control.filter.accounts_search') }}</span>
                        <input
                            v-model="accountSearch"
                            type="text"
                            :placeholder="t('ads.control.filter.accounts_search')"
                            class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                        />
                    </label>
                    <ul class="max-h-64 space-y-1 overflow-y-auto">
                        <li v-for="a in shownAccounts" :key="a.id">
                            <label class="flex min-h-8 items-center gap-2 text-xs">
                                <input type="checkbox" class="size-4" :checked="picked.has(a.id)" @change="toggleAccount(a.id)" />
                                <span dir="auto" class="truncate">{{ a.name }}</span>
                            </label>
                        </li>
                    </ul>
                </PopoverContent>
            </Popover>
            <select
                v-if="buyers.length && access?.isBuyer !== true"
                data-test="buyer-select"
                :value="filters.buyer ?? ''"
                :class="select"
                :aria-label="t('ads.control.filter.buyer')"
                @change="set('buyer', ($event.target as HTMLSelectElement).value || null)"
            >
                <option value="">{{ t('ads.control.filter.buyer_all') }}</option>
                <option v-for="b in buyers" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <div v-if="can('status') && filters.status" class="inline-flex rounded-md border border-input p-0.5" role="radiogroup" :aria-label="t('ads.control.status.label')">
                <button
                    v-for="s in ['running', 'paused', 'all'] as const"
                    :key="s"
                    type="button"
                    role="radio"
                    :aria-checked="filters.status === s"
                    class="h-8 rounded px-2 text-xs"
                    :class="filters.status === s ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    @click="set('status', s)"
                >
                    {{ t(`ads.control.status.${s}`) }}
                </button>
            </div>
        </template>
        <template v-if="platforms.length > 1 || can('list')" #more>
            <label v-if="platforms.length > 1" class="block space-y-1 text-xs">
                <span>{{ t('ads.control.filter.platform') }}</span>
                <select :value="filters.platform ?? ''" :class="[select, 'w-full']" @change="set('platform', ($event.target as HTMLSelectElement).value || null)">
                    <option value="">{{ t('ads.control.filter.platform_all') }}</option>
                    <option v-for="p in platforms" :key="p" :value="p">{{ AD_PLATFORM_LABELS[p as AdPlatformValue] ?? p }}</option>
                </select>
            </label>
            <template v-if="can('list')">
                <label class="block space-y-1 text-xs">
                    <span>{{ t('ads.control.objective.label') }}</span>
                    <select :value="filters.objective ?? ''" :class="[select, 'w-full']" @change="set('objective', ($event.target as HTMLSelectElement).value || null)">
                        <option value="">{{ t('ads.control.objective.any') }}</option>
                        <option v-for="o in ['messages', 'sales', 'traffic']" :key="o" :value="o">{{ t(`ads.control.objective.${o}`) }}</option>
                    </select>
                </label>
                <label class="block space-y-1 text-xs">
                    <span>{{ t('ads.control.health_filter.label') }}</span>
                    <select :value="filters.health ?? ''" :class="[select, 'w-full']" @change="set('health', ($event.target as HTMLSelectElement).value || null)">
                        <option value="">{{ t('ads.control.health_filter.any') }}</option>
                        <option v-for="h in ['no_result', 'losing', 'tired', 'winning', 'out_of_stock']" :key="h" :value="h">{{ t(`ads.control.health_filter.${h}`) }}</option>
                        <option v-if="filters.health && !['no_result', 'losing', 'tired', 'winning', 'out_of_stock'].includes(filters.health)" :value="filters.health">
                            {{ healthLabel(filters.health) }}
                        </option>
                    </select>
                </label>
            </template>
        </template>
    </FilterBar>
</template>
