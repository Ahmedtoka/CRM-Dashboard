<script setup lang="ts">
/**
 * The settings save bar: sticky to the bottom of the page flow (so it reserves its own row after the
 * last field and never floats over one), on a solid surface, with an «unsaved changes» note.
 */
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { LoaderCircle } from 'lucide-vue-next';

withDefaults(defineProps<{ busy?: boolean; dirty?: boolean; label?: string }>(), { busy: false, dirty: false, label: undefined });
const emit = defineEmits<{ save: [] }>();

const { t } = useI18n();
</script>

<template>
    <div class="sticky bottom-0 z-10 -mx-3 border-t border-border bg-background px-3 py-3 md:-mx-6 md:px-6">
        <div class="flex flex-wrap items-center justify-end gap-3">
            <p v-if="dirty" class="me-auto text-xs text-muted-foreground" role="status">{{ t('ui.unsaved_changes') }}</p>
            <slot />
            <Button type="button" :disabled="busy" class="gap-1.5" @click="emit('save')">
                <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />{{ label ?? t('common.save') }}
            </Button>
        </div>
    </div>
</template>
