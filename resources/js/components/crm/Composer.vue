<script setup lang="ts">
import QuickReplyMenu from '@/components/crm/QuickReplyMenu.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import type { PlatformValue, QuickReply } from '@/types/crm';
import { SendHorizontal } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{ disabled: boolean; lockHolderName: string | null; quickReplies: QuickReply[]; platform: PlatformValue }>();
const text = defineModel<string>({ required: true });
const emit = defineEmits<{ send: [body: string]; typing: [] }>();

const { t } = useI18n();

const textarea = ref<HTMLTextAreaElement | null>(null);
const confirmButton = ref<HTMLButtonElement | null>(null);
const confirming = ref(false);
const dismissed = ref(false);
const activeIndex = ref(0);

// "/" at the start or after whitespace, followed by the shortcut being typed.
const SLASH = /(^|\s)\/([^\s/]*)$/;

const slashQuery = computed(() => {
    const match = SLASH.exec(text.value);
    return match ? match[2].toLowerCase() : null;
});

const matches = computed(() => {
    const query = slashQuery.value;
    if (query === null) return [];
    return props.quickReplies
        .filter((r) => !r.platforms?.length || r.platforms.includes(props.platform))
        .filter((r) => r.shortcut.toLowerCase().includes(query) || r.title.toLowerCase().includes(query))
        .slice(0, 8);
});

const menuOpen = computed(() => slashQuery.value !== null && !dismissed.value && !props.disabled);

watch(slashQuery, (query) => {
    activeIndex.value = 0;
    if (query === null) dismissed.value = false;
});
watch(text, () => nextTick(autosize));

function autosize(): void {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

function focusEnd(): void {
    const el = textarea.value;
    el?.focus();
    el?.setSelectionRange(el.value.length, el.value.length);
}

function pick(reply: QuickReply): void {
    text.value = text.value.replace(SLASH, `$1${reply.body}`);
    dismissed.value = true;
    nextTick(focusEnd);
}

function submit(): void {
    const body = text.value.trim();
    if (!body || props.disabled) return;
    if (props.lockHolderName) {
        confirming.value = true;
        nextTick(() => confirmButton.value?.focus());
        return;
    }
    emit('send', body);
}

function confirmSend(): void {
    confirming.value = false;
    const body = text.value.trim();
    if (body) emit('send', body);
    nextTick(focusEnd);
}

function cancelConfirm(): void {
    confirming.value = false;
    nextTick(focusEnd);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.isComposing) return;

    if (menuOpen.value) {
        const count = matches.value.length;
        if (event.key === 'Escape') {
            event.preventDefault();
            dismissed.value = true;
            return;
        }
        if (count && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            activeIndex.value = (activeIndex.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
            return;
        }
        if (count && (event.key === 'Enter' || event.key === 'Tab')) {
            event.preventDefault();
            pick(matches.value[activeIndex.value]);
            return;
        }
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}

function onInput(event: Event): void {
    text.value = (event.target as HTMLTextAreaElement).value;
    confirming.value = false;
    if (text.value.trim()) emit('typing');
}

onMounted(() => {
    autosize();
    if (window.matchMedia('(min-width: 768px)').matches) textarea.value?.focus();
});
</script>

<template>
    <div class="relative border-t bg-card px-3 pb-2.5 pt-2">
        <QuickReplyMenu v-if="menuOpen" :items="matches" :active-index="activeIndex" @pick="pick" @hover="activeIndex = $event" />

        <div
            v-if="confirming"
            role="alertdialog"
            aria-live="assertive"
            class="mb-2 flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900"
            @keydown.esc.prevent="cancelConfirm"
        >
            <span class="min-w-0 flex-1">{{ t('composer.confirm_locked', { name: lockHolderName ?? '' }) }}</span>
            <button ref="confirmButton" type="button" :class="cn(buttonVariants({ size: 'sm' }), 'h-7')" @click="confirmSend">{{ t('composer.send_anyway') }}</button>
            <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-7')" @click="cancelConfirm">{{ t('common.cancel') }}</button>
        </div>

        <div
            class="flex items-end gap-2 rounded-lg border border-input bg-background px-2 py-1.5 focus-within:ring-2 focus-within:ring-ring"
            :class="{ 'opacity-60': disabled }"
        >
            <textarea
                ref="textarea"
                :value="text"
                rows="1"
                dir="auto"
                :disabled="disabled"
                :placeholder="disabled ? t('composer.disabled') : t('composer.placeholder')"
                :aria-label="t('composer.placeholder')"
                aria-autocomplete="list"
                :aria-expanded="menuOpen"
                :aria-controls="menuOpen ? 'quick-reply-menu' : undefined"
                class="max-h-40 min-h-8 flex-1 resize-none bg-transparent px-1 py-1.5 text-sm leading-5 outline-none placeholder:text-muted-foreground focus-visible:ring-0 focus-visible:ring-offset-0 disabled:cursor-not-allowed"
                @input="onInput"
                @keydown="onKeydown"
            />
            <button
                type="button"
                :class="cn(buttonVariants({ size: 'icon' }), 'size-8 shrink-0')"
                :disabled="disabled || !text.trim()"
                :aria-label="t('composer.send')"
                @click="submit"
            >
                <SendHorizontal class="rtl-flip" />
            </button>
        </div>
        <p class="mt-1 hidden px-1 text-2xs text-muted-foreground md:block">{{ t('composer.hint') }}</p>
    </div>
</template>
