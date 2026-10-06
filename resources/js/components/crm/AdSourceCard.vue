<script setup lang="ts">
import RelativeTime from '@/components/crm/RelativeTime.vue';
import { Card } from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import type { AdContext } from '@/types/crm';
import { ExternalLink, Megaphone } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/** «جت من إعلان» (C 2.1, G5): the latest ad she came from, with its campaign and ad set; earlier ads on demand. */
const props = defineProps<{ ad: AdContext }>();
const { t, locale } = useI18n();
const showHistory = ref(false);
const earlier = computed(() => props.ad.history.slice(1));
/** S2's ad drawer deep link; only for the roles that may read the ads reports. */
const href = computed(() => (props.ad.can_open && props.ad.ad_id !== null ? `/ads/explorer?ad=${props.ad.ad_id}` : null));
</script>

<template>
    <Card class="p-4" data-ad-source-card>
        <h3 class="mb-2 flex items-center gap-1.5 text-sm font-bold">
            <Megaphone class="size-4 text-amber-600 dark:text-amber-300" aria-hidden="true" />{{ t('inbox.ad.title') }}
        </h3>
        <div class="flex items-start gap-3">
            <img v-if="ad.thumbnail_url" :src="ad.thumbnail_url" alt="" class="size-14 shrink-0 rounded-md object-cover" loading="lazy" />
            <div class="min-w-0 flex-1 space-y-0.5 text-xs">
                <p class="truncate text-sm font-semibold" dir="auto">{{ ad.name ?? ad.external_id ?? '—' }}</p>
                <p v-if="ad.campaign" class="truncate text-muted-foreground" dir="auto">{{ t('inbox.ad.campaign') }}: {{ ad.campaign }}</p>
                <p v-if="ad.adset" class="truncate text-muted-foreground" dir="auto">{{ t('inbox.ad.adset') }}: {{ ad.adset }}</p>
                <RelativeTime v-if="ad.referred_at" :iso="ad.referred_at" class="text-2xs text-muted-foreground" />
            </div>
        </div>
        <div v-if="href || earlier.length" class="mt-2 flex flex-wrap items-center gap-3 text-xs">
            <a v-if="href" :href="href" class="inline-flex items-center gap-1 font-medium text-primary hover:underline" data-ad-open>
                <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('inbox.ad.open') }}
            </a>
            <button
                v-if="earlier.length"
                type="button"
                class="text-muted-foreground hover:text-foreground"
                :aria-expanded="showHistory"
                data-ad-history
                @click="showHistory = !showHistory"
            >
                {{ t('inbox.ad.more', { n: formatCount(earlier.length, locale) }) }}
            </button>
        </div>
        <ul v-if="showHistory" class="mt-2 space-y-1 border-t pt-2 text-xs" :aria-label="t('inbox.ad.history')">
            <li v-for="item in earlier" :key="`${item.external_id}-${item.referred_at}`" class="flex items-center justify-between gap-2">
                <span class="truncate" dir="auto">{{ item.name ?? item.external_id }}</span>
                <RelativeTime :iso="item.referred_at" class="shrink-0 text-2xs text-muted-foreground" />
            </li>
        </ul>
    </Card>
</template>
