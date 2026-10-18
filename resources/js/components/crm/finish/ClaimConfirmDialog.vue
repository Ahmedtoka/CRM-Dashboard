<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import { Hand } from 'lucide-vue-next';

/** Q10: «هتستلمي المحادثة دي من البوت» before her first reply on a chat the bot holds. */
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: []; cancel: [] }>();

const { t } = useI18n();

function onOpen(value: boolean): void {
    open.value = value;
    if (!value) emit('cancel');
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('finish.claim.title') }}</DialogTitle>
                <DialogDescription>{{ t('finish.claim.body') }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" data-claim-cancel @click="onOpen(false)">{{ t('finish.claim.cancel') }}</Button>
                <Button
                    type="button"
                    data-claim-ok
                    @click="
                        open = false;
                        emit('confirm');
                    "
                >
                    <Hand aria-hidden="true" />{{ t('finish.claim.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
