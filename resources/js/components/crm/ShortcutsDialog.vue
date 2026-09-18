<script setup lang="ts">
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatKeys, useShortcutRegistry, type ShortcutGroup } from '@/composables/useShortcuts';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();
const { list } = useShortcutRegistry();

const GROUPS: ShortcutGroup[] = ['global', 'inbox', 'composer'];
const GROUP_LABEL_KEY: Record<ShortcutGroup, string> = {
    global: 'shortcuts.group_global',
    inbox: 'shortcuts.group_inbox',
    composer: 'shortcuts.group_composer',
};

const grouped = computed(() =>
    GROUPS.map((group) => ({ group, items: list.value.filter((d) => d.group === group) })).filter((g) => g.items.length),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="text-base">{{ t('shortcuts.title') }}</DialogTitle>
            </DialogHeader>

            <div class="max-h-[70vh] space-y-4 overflow-y-auto">
                <section v-for="section in grouped" :key="section.group">
                    <h3 class="mb-1.5 text-2xs font-semibold uppercase tracking-wide text-muted-foreground">{{ t(GROUP_LABEL_KEY[section.group]) }}</h3>
                    <ul class="space-y-1">
                        <li v-for="def in section.items" :key="def.id" class="flex items-center justify-between gap-3 rounded-md px-1.5 py-1 text-sm">
                            <span class="text-foreground/90">{{ t(def.labelKey) }}</span>
                            <span class="flex shrink-0 gap-1" dir="ltr">
                                <kbd
                                    v-for="combo in def.keys"
                                    :key="combo"
                                    class="rounded border bg-elevated px-1.5 py-0.5 font-mono text-2xs text-muted-foreground"
                                    dir="ltr"
                                >
                                    {{ formatKeys(combo) }}
                                </kbd>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
