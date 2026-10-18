<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { CardState } from '@/lib/integrations';
import { ChevronDown, CircleAlert, CircleCheck, Clock, LoaderCircle, type LucideIcon } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';

/**
 * One connection on Settings › القنوات › الربط (mockup `.setrow`): the account in one line, what it is under it, and at
 * the far end its state in words (متوصل / فيه مشكلة) or «اربطي». The connect form, the health check and the actions
 * fold under the row and open on demand (or by themselves when something needs attention).
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        state: CardState;
        icon: LucideIcon;
        /** Account line shown instead of the platform title once connected. */
        accountName?: string | null;
        accountDetail?: string | null;
        picture?: string | null;
        /** Whether the row has a body to unfold (Shopify links away, TikTok has nothing yet). */
        toggleable?: boolean;
    }>(),
    { accountName: null, accountDetail: null, picture: null, toggleable: true },
);

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const bodyId = `integration-${useId()}`;

const CHIPS = {
    not_connected: { icon: undefined, tone: 'neutral' },
    soon: { icon: Clock, tone: 'neutral' },
    connecting: { icon: LoaderCircle, tone: 'info' },
    connected: { icon: CircleCheck, tone: 'ok' },
    problem: { icon: CircleAlert, tone: 'danger' },
} as const;
const chip = computed(() => CHIPS[props.state]);

const toggleLabel = computed(() => {
    if (open.value) return t('settings.integrations.row.hide');
    return props.state === 'not_connected' ? t('settings.integrations.row.connect') : t('settings.integrations.row.manage');
});

// Instagram CDN picture URLs expire: fall back to the platform mark when one fails.
const pictureFailed = ref(false);
watch(
    () => props.picture,
    () => (pictureFailed.value = false),
);
</script>

<template>
    <section class="border-b border-line last:border-b-0" :aria-label="title" :data-integration-state="state">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5" :class="state === 'soon' ? 'opacity-70' : ''">
            <div class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-md bg-sunken text-fg-2">
                <img
                    v-if="picture && !pictureFailed"
                    :src="picture"
                    alt=""
                    class="size-full object-cover"
                    referrerpolicy="no-referrer"
                    loading="lazy"
                    @error="pictureFailed = true"
                />
                <component :is="icon" v-else class="size-5" aria-hidden="true" />
            </div>

            <div class="min-w-[min(100%,12rem)] flex-1">
                <h2 class="truncate text-sm font-semibold text-foreground">
                    <template v-if="accountName">
                        <bdi>{{ accountName }}</bdi>
                        <span class="font-normal text-fg-3"> · {{ title }}</span>
                    </template>
                    <template v-else>{{ title }}</template>
                </h2>
                <p class="mt-0.5 text-13 text-fg-2" :class="accountName ? 'truncate' : ''">
                    <bdi v-if="accountName && accountDetail">{{ accountDetail }}</bdi>
                    <template v-else>{{ description }}</template>
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <StatusChip
                    v-if="state !== 'not_connected'"
                    :label="t(`settings.integrations.state.${state}`)"
                    :tone="chip.tone"
                    :icon="chip.icon"
                    :class="state === 'connecting' ? '[&_svg]:animate-spin [&_svg]:motion-reduce:animate-none' : ''"
                />
                <slot name="action">
                    <Button
                        v-if="toggleable"
                        type="button"
                        :variant="state === 'not_connected' && !open ? 'secondary' : 'ghost'"
                        size="sm"
                        class="max-sm:h-10"
                        :aria-expanded="open"
                        :aria-controls="bodyId"
                        @click="open = !open"
                    >
                        {{ toggleLabel }}
                        <ChevronDown class="transition-transform duration-200" :class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </Button>
                </slot>
            </div>
        </div>

        <div v-if="$slots.default" v-show="open" :id="bodyId" class="grid gap-3 border-t border-line bg-surface-2 px-4 py-4">
            <slot />
        </div>
    </section>
</template>
