<script setup lang="ts">
import { Button, buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { useToast } from '@/composables/useToast';
import { cn } from '@/lib/utils';
import type { ConversationQueueEntry, QueueCloseReason, SupportCaseType } from '@/types/crm';
import { ArrowUpCircle, CheckCircle2, ChevronDown, CircleHelp, FolderPlus, LoaderCircle, Wrench, type LucideIcon } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    entry: ConversationQueueEntry;
    /** Set when the window is somebody else's (a supervisor acting on it): said in the menu. */
    ownerName?: string | null;
    disabled?: boolean;
    /** Shown next to the button's title, e.g. the keyboard shortcut. */
    hint?: string;
}>();

const { t } = useI18n();
const toast = useToast();
const queue = useMyQueueContext();

const CASE_TYPES: SupportCaseType[] = ['return_exchange', 'return', 'exchange', 'complaint', 'cancel_edit', 'delivery_followup'];

const reasons: { value: QueueCloseReason; icon: LucideIcon }[] = [
    { value: 'inquiry', icon: CircleHelp },
    { value: 'problem', icon: Wrench },
    { value: 'case', icon: FolderPlus },
];

const menuOpen = ref(false);
const caseOpen = ref(false);
const escalateOpen = ref(false);
const caseType = ref<SupportCaseType>('complaint');

const ticket = computed(() => props.entry.ticket % 100000);
const working = computed(() => queue?.busy.value === `close-${props.entry.id}` || queue?.busy.value === `escalate-${props.entry.id}`);
const blocked = computed(() => props.disabled || (queue?.busy.value ?? null) !== null);

async function close(reason: QueueCloseReason, type: SupportCaseType | null = null): Promise<void> {
    if (!queue || blocked.value) return;

    if (await queue.closeEntry(props.entry.id, reason, type)) {
        caseOpen.value = false;
        toast.push(t('queue.close.closed', { ticket: ticket.value }));
    }
}

function pick(reason: QueueCloseReason): void {
    if (reason === 'case') {
        caseType.value = 'complaint';
        caseOpen.value = true;

        return;
    }

    void close(reason);
}

async function escalate(): Promise<void> {
    if (!queue || blocked.value) return;

    if (await queue.escalate(props.entry.id)) {
        escalateOpen.value = false;
        toast.push(t('queue.close.escalated', { ticket: ticket.value }));
    }
}

defineExpose({ open: () => (menuOpen.value = true) });
</script>

<template>
    <DropdownMenu v-model:open="menuOpen">
        <DropdownMenuTrigger
            :title="`${t('queue.close.menu_label')}${hint ?? ''}`"
            :class="cn(buttonVariants({ size: 'sm' }), 'h-9 gap-1 rounded-full px-2.5')"
            :disabled="blocked"
            :aria-label="t('queue.close.menu_label')"
            data-close-window
        >
            <LoaderCircle v-if="working" class="animate-spin" aria-hidden="true" />
            <CheckCircle2 v-else aria-hidden="true" />
            <span class="hidden sm:inline">{{ t('queue.close.button') }}</span>
            <ChevronDown class="size-3.5 opacity-80" aria-hidden="true" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64">
            <DropdownMenuLabel class="text-xs">{{ t('queue.close.menu_label') }}</DropdownMenuLabel>
            <p v-if="ownerName" class="px-2 pb-1 text-2xs text-muted-foreground" dir="auto">{{ t('queue.close.not_mine', { name: ownerName }) }}</p>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="reason in reasons"
                :key="reason.value"
                class="items-start"
                :data-reason="reason.value"
                @select="pick(reason.value)"
            >
                <component :is="reason.icon" class="mt-0.5 text-primary" aria-hidden="true" />
                <span class="flex min-w-0 flex-col">
                    <span class="text-sm font-medium">{{ t(`queue.close.${reason.value}`) }}</span>
                    <span class="text-2xs text-muted-foreground">{{ t(`queue.close.${reason.value}_hint`) }}</span>
                </span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem class="items-start" data-reason="escalate" @select="escalateOpen = true">
                <ArrowUpCircle class="mt-0.5 text-destructive" aria-hidden="true" />
                <span class="flex min-w-0 flex-col">
                    <span class="text-sm font-medium">{{ t('queue.close.escalate') }}</span>
                    <span class="text-2xs text-muted-foreground">{{ t('queue.close.escalate_hint') }}</span>
                </span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <Dialog v-model:open="caseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('queue.close.case_title') }}</DialogTitle>
                <DialogDescription>{{ t('queue.close.case_description') }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="close('case', caseType)">
                <fieldset class="grid gap-2 sm:grid-cols-2">
                    <legend class="mb-2 text-xs font-medium">{{ t('queue.close.case_type') }}</legend>
                    <label
                        v-for="type in CASE_TYPES"
                        :key="type"
                        class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors focus-within:ring-2 focus-within:ring-ring"
                        :class="caseType === type ? 'border-primary bg-surface-accent font-medium text-primary' : 'border-border hover:bg-elevated'"
                    >
                        <input v-model="caseType" type="radio" name="queue-case-type" :value="type" class="size-3.5 accent-primary" />
                        {{ t(`cases.types.${type}`) }}
                    </label>
                </fieldset>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" :disabled="working" @click="caseOpen = false">{{ t('common.cancel') }}</Button>
                    <Button type="submit" :disabled="blocked">
                        <LoaderCircle v-if="working" class="animate-spin" aria-hidden="true" />
                        <FolderPlus v-else aria-hidden="true" />
                        {{ t('queue.close.case_confirm') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="escalateOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('queue.close.escalate_title') }}</DialogTitle>
                <DialogDescription>{{ t('queue.close.escalate_description') }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" :disabled="working" @click="escalateOpen = false">{{ t('common.cancel') }}</Button>
                <Button type="button" variant="destructive" :disabled="blocked" @click="escalate">
                    <LoaderCircle v-if="working" class="animate-spin" aria-hidden="true" />
                    <ArrowUpCircle v-else aria-hidden="true" />
                    {{ t('queue.close.escalate_confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
