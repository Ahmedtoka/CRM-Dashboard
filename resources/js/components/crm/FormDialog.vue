<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import { LoaderCircle } from 'lucide-vue-next';

withDefaults(
    defineProps<{ open: boolean; title: string; description?: string; busy?: boolean; error?: string | null; wide?: boolean; submitLabel?: string; destructive?: boolean }>(),
    {
        busy: false,
        wide: false,
        destructive: false,
    },
);

const emit = defineEmits<{ 'update:open': [open: boolean]; submit: [] }>();

const { t } = useI18n();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90svh] overflow-y-auto" :class="wide ? 'sm:max-w-2xl' : 'sm:max-w-md'">
            <form class="space-y-4" @submit.prevent="emit('submit')">
                <DialogHeader class="text-start">
                    <DialogTitle class="text-base">{{ title }}</DialogTitle>
                    <DialogDescription v-if="description" class="text-xs">{{ description }}</DialogDescription>
                </DialogHeader>
                <div class="space-y-3 text-sm">
                    <slot />
                </div>
                <p v-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ error }}</p>
                <DialogFooter class="gap-2 sm:justify-start">
                    <button type="submit" :class="cn(buttonVariants({ variant: destructive ? 'destructive' : 'default' }), 'disabled:opacity-50')" :disabled="busy">
                        <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />{{ submitLabel ?? t('common.save') }}
                    </button>
                    <button type="button" :class="buttonVariants({ variant: 'outline' })" @click="emit('update:open', false)">{{ t('common.cancel') }}</button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
