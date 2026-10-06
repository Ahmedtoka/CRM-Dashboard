<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import { Eraser } from 'lucide-vue-next';
import { nextTick, ref, watch } from 'vue';

/**
 * «تصفير المحادثة» asks first in a real dialog (it wipes the thread), never `window.confirm`.
 * After «صفّري» it stays open with a spinner while the reset runs (`busy`), and closes when it ends.
 */
const props = withDefaults(defineProps<{ busy?: boolean }>(), { busy: false });
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: []; closeFocus: [] }>();

const { t } = useI18n();
const cancelButton = ref<InstanceType<typeof Button> | null>(null);
const pending = ref(false);

// The safe choice gets the focus: Enter right after opening never wipes a conversation.
function focusCancel(event: Event): void {
    event.preventDefault();
    (cancelButton.value?.$el as HTMLElement | undefined)?.focus();
}

// Focus goes back to whoever opened it (the header's «⋯»), not to the body.
function returnFocus(event: Event): void {
    event.preventDefault();
    emit('closeFocus');
}

/** No closing (Escape, outside click, cancel) while the reset runs. */
function setOpen(value: boolean): void {
    if (!value && (pending.value || props.busy)) return;
    open.value = value;
}

function confirm(): void {
    pending.value = true;
    emit('confirm');
    // The reset was not started (another action in flight): nothing to wait for.
    void nextTick(() => {
        if (!props.busy) finish();
    });
}

function finish(): void {
    pending.value = false;
    open.value = false;
}

watch(
    () => props.busy,
    (busy) => {
        if (!busy && pending.value) finish();
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent class="sm:max-w-md" @open-auto-focus="focusCancel" @close-auto-focus="returnFocus">
            <DialogHeader>
                <DialogTitle>{{ t('thread.reset_dialog.title') }}</DialogTitle>
                <DialogDescription>{{ t('thread.reset_dialog.body') }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button ref="cancelButton" type="button" variant="ghost" :disabled="pending" @click="setOpen(false)">{{ t('common.cancel') }}</Button>
                <Button type="button" variant="destructive" :disabled="pending || busy" data-reset-confirm @click="confirm" :loading="pending">
                    <Eraser aria-hidden="true" />
                    {{ t('thread.reset_dialog.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
