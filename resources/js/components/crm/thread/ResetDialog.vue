<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import { Eraser, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

/** «تصفير المحادثة» asks first in a real dialog (it wipes the thread), never `window.confirm`. */
withDefaults(defineProps<{ busy?: boolean }>(), { busy: false });
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();

const { t } = useI18n();
const cancelButton = ref<InstanceType<typeof Button> | null>(null);

// The safe choice gets the focus: Enter right after opening never wipes a conversation.
function focusCancel(event: Event): void {
    event.preventDefault();
    (cancelButton.value?.$el as HTMLElement | undefined)?.focus();
}

function confirm(): void {
    open.value = false;
    emit('confirm');
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" @open-auto-focus="focusCancel">
            <DialogHeader>
                <DialogTitle>{{ t('thread.reset_dialog.title') }}</DialogTitle>
                <DialogDescription>{{ t('thread.reset_dialog.body') }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button ref="cancelButton" type="button" variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
                <Button type="button" variant="destructive" :disabled="busy" data-reset-confirm @click="confirm">
                    <LoaderCircle v-if="busy" class="animate-spin" aria-hidden="true" />
                    <Eraser v-else aria-hidden="true" />
                    {{ t('thread.reset_dialog.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
