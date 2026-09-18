<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { CardState } from '@/lib/integrations';
import { CircleAlert, CircleCheck, CircleDashed, Clock, LoaderCircle, type LucideIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * One connection on Settings → Integrations. The coloured rail on the start edge
 * is the card's state at a glance (grey: not connected, blue: connecting, green:
 * connected, red: needs attention); the chip repeats it in words and an icon so
 * the state never depends on colour alone.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        state: CardState;
        icon: LucideIcon;
        /** Brand colour for the platform mark. */
        color: string;
        /** Account line shown instead of the description once connected. */
        accountName?: string | null;
        accountDetail?: string | null;
        picture?: string | null;
        compact?: boolean;
    }>(),
    { accountName: null, accountDetail: null, picture: null, compact: false },
);

const { t } = useI18n();

const rail = computed(
    () =>
        ({
            not_connected: 'bg-border',
            soon: 'bg-border',
            connecting: 'bg-primary motion-safe:animate-pulse',
            connected: 'bg-success',
            problem: 'bg-destructive',
        })[props.state],
);

const chip = computed(
    () =>
        ({
            not_connected: { icon: CircleDashed, cls: 'bg-muted text-muted-foreground' },
            soon: { icon: Clock, cls: 'bg-muted text-muted-foreground' },
            connecting: { icon: LoaderCircle, cls: 'bg-primary/10 text-primary' },
            connected: { icon: CircleCheck, cls: 'bg-success/15 text-foreground' },
            problem: { icon: CircleAlert, cls: 'bg-destructive/10 text-destructive' },
        })[props.state],
);

// Instagram CDN picture URLs expire: fall back to the platform mark when one fails.
const pictureFailed = ref(false);
watch(
    () => props.picture,
    () => (pictureFailed.value = false),
);
</script>

<template>
    <section class="relative overflow-hidden rounded-lg bg-card shadow-card" :class="state === 'soon' ? 'opacity-75' : ''" :aria-label="title">
        <span class="absolute inset-y-0 start-0 w-1 transition-colors duration-300" :class="rail" aria-hidden="true" />
        <div class="grid gap-3 pe-4 ps-5" :class="compact ? 'py-3' : 'py-4'">
            <header class="flex items-start gap-3">
                <div
                    class="grid shrink-0 place-items-center overflow-hidden rounded-lg"
                    :class="compact ? 'size-9' : 'size-11'"
                    :style="{ backgroundColor: `${color}14`, color }"
                >
                    <img
                        v-if="picture && !pictureFailed"
                        :src="picture"
                        alt=""
                        class="size-full object-cover"
                        referrerpolicy="no-referrer"
                        loading="lazy"
                        @error="pictureFailed = true"
                    />
                    <component :is="icon" v-else :class="compact ? 'size-4' : 'size-5'" aria-hidden="true" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h2 class="text-sm font-semibold text-foreground">{{ title }}</h2>
                        <span class="inline-flex h-5 items-center gap-1 rounded-full px-2 text-2xs font-medium" :class="chip.cls">
                            <component :is="chip.icon" class="size-3" :class="state === 'connecting' ? 'animate-spin' : ''" aria-hidden="true" />
                            {{ t(`settings.integrations.state.${state}`) }}
                        </span>
                    </div>
                    <p v-if="accountName" class="mt-0.5 truncate text-sm font-medium text-foreground">
                        <bdi>{{ accountName }}</bdi>
                    </p>
                    <p v-if="accountName && accountDetail" class="truncate text-xs text-muted-foreground">
                        <bdi>{{ accountDetail }}</bdi>
                    </p>
                    <p v-else-if="!accountName" class="mt-0.5 text-xs leading-relaxed text-muted-foreground">{{ description }}</p>
                </div>

                <div v-if="$slots.aside" class="flex shrink-0 items-center gap-2">
                    <slot name="aside" />
                </div>
            </header>

            <slot />
        </div>
    </section>
</template>
