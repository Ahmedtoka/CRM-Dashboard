<script setup lang="ts">
/**
 * The settings save bar: sticky to the bottom of the page flow (so it reserves its own row after the
 * last field and never floats over one), on a solid surface, with an «unsaved changes» note.
 */
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { LoaderCircle } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        busy?: boolean;
        dirty?: boolean;
        label?: string;
        /** Inside a <form>: the button is its submit button, so Enter in a field saves too (no `save` event then). */
        submit?: boolean;
        /** Stretch the bar over the page shell's padding (`p-3 md:p-6`), edge to edge; off inside a padded card. */
        bleed?: boolean;
    }>(),
    { busy: false, dirty: false, label: undefined, submit: false, bleed: true },
);
const emit = defineEmits<{ save: [] }>();

const { t } = useI18n();
</script>

<template>
    <div class="sticky bottom-0 z-10 border-t border-border bg-background py-3" :class="bleed ? '-mx-3 px-3 md:-mx-6 md:px-6' : ''">
        <div class="flex flex-wrap items-center justify-end gap-3">
            <p v-if="dirty" class="me-auto text-xs text-muted-foreground" role="status">{{ t('ui.unsaved_changes') }}</p>
            <slot />
            <Button :type="submit ? 'submit' : 'button'" :disabled="busy" class="gap-1.5" @click="!submit && emit('save')">
                <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />{{ label ?? t('common.save') }}
            </Button>
        </div>
    </div>
</template>
