<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { Copy } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ note: string | null | undefined; lines?: number }>(), { lines: 2 });

const { t } = useI18n();
const toast = useToast();

const text = computed(() => (props.note ?? '').trim());
const expanded = ref(false);
const clamped = ref(false);
const body = ref<HTMLElement | null>(null);

// «المزيد» only when the clamp really hides something.
function measure(): void {
    const el = body.value;
    if (!el || expanded.value) return;
    clamped.value = el.scrollHeight - el.clientHeight > 1;
}

let observer: ResizeObserver | null = null;
onMounted(() => {
    measure();
    if (typeof ResizeObserver !== 'undefined' && body.value) {
        observer = new ResizeObserver(measure);
        observer.observe(body.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());
watch(text, () => {
    expanded.value = false;
    nextTick(measure);
});

const clampStyle = computed(() =>
    expanded.value
        ? undefined
        : { display: '-webkit-box', WebkitBoxOrient: 'vertical' as const, WebkitLineClamp: String(props.lines), overflow: 'hidden' },
);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(text.value);
        toast.push(t('orders.note_card.copied'));
    } catch {
        toast.push(t('orders.note_card.copy_failed'), 'error');
    }
}
</script>

<template>
    <div v-if="text" class="flex min-w-0 items-start gap-1">
        <div class="min-w-0 flex-1">
            <!-- Customer / staff text: plain text only, its own direction, its own line breaks. -->
            <p ref="body" class="whitespace-pre-wrap break-words text-start leading-relaxed text-foreground" dir="auto" :style="clampStyle">
                {{ text }}
            </p>
            <button
                v-if="clamped || expanded"
                type="button"
                class="mt-0.5 rounded text-2xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                {{ expanded ? t('orders.note_card.less') : t('orders.note_card.more') }}
            </button>
        </div>
        <button
            type="button"
            class="-me-1 -mt-1 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :title="t('orders.note_card.copy')"
            :aria-label="t('orders.note_card.copy')"
            @click="copy"
        >
            <Copy class="size-3.5" aria-hidden="true" />
        </button>
    </div>
</template>
