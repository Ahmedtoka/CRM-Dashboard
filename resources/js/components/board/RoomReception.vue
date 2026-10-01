<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { RECEPTION_SPOTS, type Box } from '@/lib/board/layout';
import { formatCount } from '@/lib/format';
import { computed } from 'vue';

const props = defineProps<{ reception: Box }>();

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const OUTFITS = ['#00838f', '#ad1457', '#4527a0', '#ef6c00', '#2e7d32', '#1565c0'];

// As many figures as customers talking to the bot now, up to what the reception holds at its width.
const room = computed(() => Math.max(1, Math.min(RECEPTION_SPOTS, Math.floor((props.reception.w - 104) / 40))));
const spots = computed(() =>
    Array.from({ length: Math.min(room.value, board.withBot.value) }, (_, i) => ({
        i,
        left: 100 + i * 40,
        colour: OUTFITS[i % OUTFITS.length],
        alt: i % 3 === 1,
    })),
);
</script>

<template>
    <section
        class="recep"
        :style="{ left: `${reception.x}px`, top: `${reception.y}px`, width: `${reception.w}px`, height: `${reception.h}px` }"
        :dir="dir"
        :aria-label="t('board.reception.title')"
    >
        <div class="door" aria-hidden="true">{{ t('board.reception.door') }}</div>
        <div class="ttl">{{ t('board.reception.title') }}</div>
        <div class="kiosk" aria-hidden="true">
            <div class="face"><i /><i /></div>
            <div class="mouth" />
            <div class="lb">{{ t('board.reception.bot') }}</div>
        </div>
        <div class="kstat">
            <b class="num">{{ formatCount(board.withBot.value, locale) }}</b>
            <span>{{ t('board.reception.with_bot') }}</span>
        </div>
        <div
            v-for="spot in spots"
            :key="spot.i"
            class="bspot"
            :style="{ left: `${spot.left}px`, animationDelay: `${spot.i * 80}ms` }"
            aria-hidden="true"
        >
            <svg :style="{ color: spot.colour }"><use :href="spot.alt ? '#br-g-girl2' : '#br-g-girl'" /></svg>
        </div>
    </section>
</template>
