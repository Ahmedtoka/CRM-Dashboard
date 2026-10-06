<script setup lang="ts">
/** «وافق على الآمن كله» (L 3.3): the plan, then one approval at a time with a determinate progress bar and a result per launch. */
import ProgressBar from '@/components/crm/ProgressBar.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useBulkApprove } from '@/composables/useBulkApprove';
import { useI18n } from '@/composables/useI18n';
import { CircleCheck, CircleX, LoaderCircle } from 'lucide-vue-next';
import { watch } from 'vue';

/** Bumped by the page after a mid-run re-auth: the run resumes with the launches still pending. */
const props = withDefaults(defineProps<{ open: boolean; resume?: number }>(), { resume: 0 });
const emit = defineEmits<{ 'update:open': [open: boolean]; done: []; reauth: [] }>();

const { t } = useI18n();
const bulk = useBulkApprove();
const { plan, rows, done, running, stoppedReason, needsReauth, error, okCount, failedCount, leftCount } = bulk;

watch(
    () => props.open,
    async (o) => {
        if (!o) return;
        await bulk.load();
        if (needsReauth.value) {
            emit('update:open', false);
            emit('reauth');
        }
    },
    { immediate: true },
);

watch(
    () => props.resume,
    () => {
        if (props.open && plan.value) void start();
    },
);

async function start(): Promise<void> {
    await bulk.run();
    if (needsReauth.value) {
        emit('reauth');
        return;
    }
    emit('done');
}
</script>

<template>
    <Dialog :open="open" @update:open="!running && emit('update:open', $event)">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-xl">
            <DialogHeader class="text-start">
                <DialogTitle class="text-base">{{ t('ads.launch.bulk.title') }}</DialogTitle>
                <DialogDescription v-if="plan" class="text-xs">
                    {{ t('ads.launch.bulk.plan', { n: plan.launches.length, ads: plan.total_ads, left: plan.approvals_left }) }}
                </DialogDescription>
            </DialogHeader>

            <p v-if="!plan && !error" role="status" class="flex items-center gap-2 text-sm text-muted-foreground">
                <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />{{ t('ads.launch.bulk.loading') }}
            </p>
            <p v-else-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ error }}</p>
            <template v-else-if="plan">
                <p v-if="!plan.launches.length" class="text-sm">{{ t('ads.launch.bulk.none') }}</p>
                <p class="text-2xs text-muted-foreground">
                    {{
                        t('ads.launch.bulk.skipped', {
                            warned: plan.skipped.warned,
                            first: plan.skipped.first_launch,
                            self: plan.skipped.self,
                            limit: plan.skipped.limit,
                        })
                    }}
                </p>
                <ProgressBar
                    v-if="plan.launches.length"
                    :value="done"
                    :max="plan.launches.length"
                    :label="running ? t('ads.launch.bulk.running') : undefined"
                />
                <ul class="space-y-1 text-sm">
                    <li v-for="r in rows" :key="r.id" class="flex items-center gap-2">
                        <CircleCheck v-if="r.outcome === 'ok'" class="size-4 text-emerald-600" :aria-label="t('ads.launch.bulk.result_ok')" />
                        <CircleX
                            v-else-if="r.outcome === 'failed'"
                            class="size-4 text-destructive"
                            :aria-label="t('ads.launch.bulk.result_failed')"
                        />
                        <span v-else class="size-4 rounded-full border" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate">{{ r.title }}</span>
                        <span class="text-2xs tabular-nums text-muted-foreground">{{ r.ads }}</span>
                        <span v-if="r.outcome === 'failed' && r.message" class="truncate text-2xs text-destructive">{{ r.message }}</span>
                    </li>
                </ul>
                <p v-if="stoppedReason" role="alert" class="text-xs text-destructive">
                    {{ t('ads.launch.bulk.stopped', { reason: stoppedReason, n: leftCount }) }}
                </p>
                <p v-else-if="done > 0 && !running" role="status" class="text-xs">
                    {{ t('ads.launch.bulk.done', { ok: okCount, failed: failedCount }) }}
                </p>
            </template>

            <DialogFooter class="gap-2 sm:justify-start">
                <Button v-if="plan && plan.launches.length && leftCount > 0" :loading="running" @click="start">
                    {{ t('ads.launch.bulk.run', { n: leftCount }) }}
                </Button>
                <Button variant="outline" :disabled="running" @click="emit('update:open', false)">{{ t('common.close') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
