<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import StickySaveBar from '@/components/crm/StickySaveBar.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { QueueSettings, QueueShiftTemplate } from '@/types/admin';
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    settings: QueueSettings;
    supervisors: { id: number; name: string; role: string }[];
    canEditAdmin: boolean;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [{ title: t('settings.queue.title'), href: '/settings/queue' }]);

const pointKeys = [
    'inquiry',
    'problem',
    'case_open',
    'case_resolved_in_time',
    'case_resolved_late',
    'speed_fast',
    'speed_ok',
    'review_per_star',
    'qa_per_point',
    'auto_close',
    'escalation',
    'no_reply',
    'daily_cap',
] as const;

function emptyShift(): QueueShiftTemplate {
    return { key: '', name: '', from: '09:00', to: '17:00', location: 'office', leader_user_id: null };
}

// Type aliases, not interfaces: Inertia's form data constraint needs an index signature, which
// an interface never has, and without an explicit shape every field degrades to `FormDataConvertible`.
type ShiftRow = { [K in keyof QueueShiftTemplate]: QueueShiftTemplate[K] };
type QueueForm = {
    enabled: boolean;
    night_message_enabled: boolean;
    case_follow_owner: boolean;
    points: Record<string, number>;
    shifts: ShiftRow[];
} & Record<(typeof NUMERIC_KEYS)[number], number>;

const NUMERIC_KEYS = [
    'windows_per_moderator',
    'silence_warn_seconds',
    'silence_close_seconds',
    'return_priority_minutes',
    'close_confirm_minutes',
    'sla_first_reply_seconds',
    'sla_target_pct',
    'occupancy_cap_pct',
    'break_minutes',
    'break_after_minutes',
    'review_sample_pct',
    'review_delay_seconds',
    'case_sla_hours',
    'speed_fast_seconds',
    'speed_ok_seconds',
    'eta_default_handle_seconds',
    'waiting_update_seconds',
    'agent_apology_seconds',
    'agent_reassign_first_seconds',
    'agent_reassign_seconds',
] as const;

const form = useForm<QueueForm>({
    enabled: props.settings.enabled,
    windows_per_moderator: props.settings.windows_per_moderator,
    silence_warn_seconds: props.settings.silence_warn_seconds,
    silence_close_seconds: props.settings.silence_close_seconds,
    return_priority_minutes: props.settings.return_priority_minutes,
    close_confirm_minutes: props.settings.close_confirm_minutes,
    sla_first_reply_seconds: props.settings.sla_first_reply_seconds,
    sla_target_pct: props.settings.sla_target_pct,
    occupancy_cap_pct: props.settings.occupancy_cap_pct,
    break_minutes: props.settings.break_minutes,
    break_after_minutes: props.settings.break_after_minutes,
    review_sample_pct: props.settings.review_sample_pct,
    review_delay_seconds: props.settings.review_delay_seconds,
    case_sla_hours: props.settings.case_sla_hours,
    night_message_enabled: props.settings.night_message_enabled,
    speed_fast_seconds: props.settings.speed_fast_seconds,
    speed_ok_seconds: props.settings.speed_ok_seconds,
    eta_default_handle_seconds: props.settings.eta_default_handle_seconds,
    waiting_update_seconds: props.settings.waiting_update_seconds,
    agent_apology_seconds: props.settings.agent_apology_seconds,
    agent_reassign_first_seconds: props.settings.agent_reassign_first_seconds,
    agent_reassign_seconds: props.settings.agent_reassign_seconds,
    case_follow_owner: props.settings.case_follow_owner,
    points: { ...(props.settings.points ?? {}) },
    shifts: (props.settings.shifts ?? []).map((s) => ({ ...s })),
});

function addShift(): void {
    form.shifts.push(emptyShift());
}

function removeShift(index: number): void {
    form.shifts.splice(index, 1);
}

// The wrapped <Input> component's own v-model never sees a `.number` modifier applied at the call site
// (that only works on a native element's v-model), so every numeric field arrives here as a string typed
// into the DOM. Laravel's `integer` rule tolerates a numeric string, but we coerce anyway so the payload —
// and the settings read back afterwards — are genuinely numeric. A supervisor also cannot touch
// points/shifts (controller: abort_if hasAny(ADMIN_FIELDS)); useForm always serializes every field it
// holds, so those two are dropped for a non-admin via transform() rather than sent unchanged.
function submit(): void {
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };
        for (const key of NUMERIC_KEYS) {
            payload[key] = Number(payload[key]);
        }
        if (props.canEditAdmin) {
            payload.points = Object.fromEntries(Object.entries(data.points).map(([key, value]) => [key, Number(value)]));
        } else {
            delete payload.points;
            delete payload.shifts;
        }

        return payload;
    }).put('/settings/queue', { preserveScroll: true });
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm tabular-nums';
const card = 'space-y-4 rounded-lg bg-card p-4 shadow-card';
const grid = 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3';
const hint = 'text-2xs text-muted-foreground';
</script>

<template>
    <Head :title="t('settings.queue.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.queue.title')" :description="t('settings.queue.description')" />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Running -->
                <section :class="card">
                    <div class="flex items-center justify-between gap-3">
                        <Label for="queue-enabled">{{ t('settings.queue.enabled') }}</Label>
                        <ToggleSwitch id="queue-enabled" v-model="form.enabled" :label="t('settings.queue.enabled')" />
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <Label for="queue-night-message">{{ t('settings.queue.night_message_enabled') }}</Label>
                        <ToggleSwitch id="queue-night-message" v-model="form.night_message_enabled" :label="t('settings.queue.night_message_enabled')" />
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <Label for="queue-case-follow">{{ t('settings.queue.case_follow_owner') }}</Label>
                        <ToggleSwitch id="queue-case-follow" v-model="form.case_follow_owner" :label="t('settings.queue.case_follow_owner')" />
                    </div>

                    <div :class="grid">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.windows_per_moderator') }}</Label>
                            <Input v-model="form.windows_per_moderator" type="number" min="1" max="10" dir="ltr" :class="input" />
                        </label>
                    </div>
                </section>

                <!-- Timers -->
                <section :class="card">
                    <h2 class="text-sm font-semibold">
                        {{ t('settings.queue.silence_warn_seconds') }} / {{ t('settings.queue.silence_close_seconds') }}
                    </h2>
                    <div :class="grid">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.silence_warn_seconds') }}</Label>
                            <Input v-model="form.silence_warn_seconds" type="number" min="30" max="3600" dir="ltr" :class="input" />
                            <span v-if="form.errors.silence_warn_seconds" class="text-2xs text-destructive">{{
                                form.errors.silence_warn_seconds
                            }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.silence_close_seconds') }}</Label>
                            <Input v-model="form.silence_close_seconds" type="number" min="60" max="7200" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.return_priority_minutes') }}</Label>
                            <Input v-model="form.return_priority_minutes" type="number" min="0" max="1440" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.close_confirm_minutes') }}</Label>
                            <Input v-model="form.close_confirm_minutes" type="number" min="0" max="1440" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.review_delay_seconds') }}</Label>
                            <Input v-model="form.review_delay_seconds" type="number" min="0" max="3600" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.case_sla_hours') }}</Label>
                            <Input v-model="form.case_sla_hours" type="number" min="1" max="168" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.waiting_update_seconds') }}</Label>
                            <Input v-model="form.waiting_update_seconds" type="number" min="30" max="900" dir="ltr" :class="input" />
                            <span v-if="form.errors.waiting_update_seconds" class="text-2xs text-destructive">{{ form.errors.waiting_update_seconds }}</span>
                        </label>
                    </div>
                </section>

                <!-- Moderator reply (flow revision §4) -->
                <section :class="card">
                    <header>
                        <h2 class="text-sm font-semibold">{{ t('settings.queue.reply_section') }}</h2>
                        <p :class="hint">{{ t('settings.queue.reply_section_hint') }}</p>
                    </header>
                    <div :class="grid">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.agent_apology_seconds') }}</Label>
                            <Input v-model="form.agent_apology_seconds" type="number" min="30" max="3600" dir="ltr" :class="input" />
                            <span v-if="form.errors.agent_apology_seconds" class="text-2xs text-destructive">{{ form.errors.agent_apology_seconds }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.agent_reassign_first_seconds') }}</Label>
                            <Input v-model="form.agent_reassign_first_seconds" type="number" min="120" max="3600" dir="ltr" :class="input" />
                            <span v-if="form.errors.agent_reassign_first_seconds" class="text-2xs text-destructive">{{
                                form.errors.agent_reassign_first_seconds
                            }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.agent_reassign_seconds') }}</Label>
                            <Input v-model="form.agent_reassign_seconds" type="number" min="120" max="3600" dir="ltr" :class="input" />
                            <span v-if="form.errors.agent_reassign_seconds" class="text-2xs text-destructive">{{ form.errors.agent_reassign_seconds }}</span>
                        </label>
                    </div>
                </section>

                <!-- SLA & occupancy -->
                <section :class="card">
                    <h2 class="text-sm font-semibold">{{ t('settings.queue.sla_first_reply_seconds') }}</h2>
                    <div :class="grid">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.sla_first_reply_seconds') }}</Label>
                            <Input v-model="form.sla_first_reply_seconds" type="number" min="30" max="7200" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.sla_target_pct') }}</Label>
                            <Input v-model="form.sla_target_pct" type="number" min="1" max="100" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.occupancy_cap_pct') }}</Label>
                            <Input v-model="form.occupancy_cap_pct" type="number" min="10" max="100" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.break_minutes') }}</Label>
                            <Input v-model="form.break_minutes" type="number" min="1" max="120" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.review_sample_pct') }}</Label>
                            <Input v-model="form.review_sample_pct" type="number" min="0" max="100" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.speed_fast_seconds') }}</Label>
                            <Input v-model="form.speed_fast_seconds" type="number" min="10" max="3600" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.speed_ok_seconds') }}</Label>
                            <Input v-model="form.speed_ok_seconds" type="number" min="10" max="3600" dir="ltr" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.eta_default_handle_seconds') }}</Label>
                            <Input v-model="form.eta_default_handle_seconds" type="number" min="30" max="3600" dir="ltr" :class="input" />
                        </label>
                    </div>
                </section>

                <!-- Points -->
                <section :class="card">
                    <header>
                        <h2 class="text-sm font-semibold">{{ t('settings.queue.points') }}</h2>
                        <p v-if="!canEditAdmin" :class="hint">{{ t('settings.queue.points_admin_only') }}</p>
                    </header>
                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <label v-for="key in pointKeys" :key="key" class="grid content-start gap-1">
                            <Label>{{ t(`settings.queue.point_keys.${key}`) }}</Label>
                            <Input
                                v-model="form.points[key]"
                                type="number"
                                min="-100"
                                max="1000"
                                dir="ltr"
                                :disabled="!canEditAdmin"
                                :class="input"
                            />
                        </label>
                    </div>
                </section>

                <!-- Shifts -->
                <section :class="card">
                    <header class="flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-semibold">{{ t('settings.queue.shifts') }}</h2>
                            <p v-if="!canEditAdmin" :class="hint">{{ t('settings.queue.points_admin_only') }}</p>
                        </div>
                        <Button type="button" variant="outline" size="sm" :disabled="!canEditAdmin" @click="addShift">
                            <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.queue.add_shift') }}
                        </Button>
                    </header>

                    <p v-if="form.errors.shifts" class="text-2xs text-destructive">{{ form.errors.shifts }}</p>

                    <div v-for="(shift, index) in form.shifts" :key="index" class="grid gap-2 rounded-md bg-elevated p-3 sm:grid-cols-6">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.shift_key') }}</Label>
                            <Input v-model="shift.key" type="text" maxlength="40" dir="ltr" :disabled="!canEditAdmin" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.shift_name') }}</Label>
                            <Input v-model="shift.name" type="text" maxlength="60" dir="auto" :disabled="!canEditAdmin" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.shift_from') }}</Label>
                            <Input v-model="shift.from" type="time" dir="ltr" :disabled="!canEditAdmin" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.shift_to') }}</Label>
                            <Input v-model="shift.to" type="time" dir="ltr" :disabled="!canEditAdmin" :class="input" />
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.shift_location') }}</Label>
                            <select v-model="shift.location" :disabled="!canEditAdmin" :class="input">
                                <option value="office">{{ t('settings.queue.office') }}</option>
                                <option value="home">{{ t('settings.queue.home') }}</option>
                            </select>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('settings.queue.shift_leader') }}</Label>
                            <div class="flex items-center gap-1.5">
                                <select v-model="shift.leader_user_id" :disabled="!canEditAdmin" :class="input">
                                    <option :value="null">—</option>
                                    <option v-for="sup in supervisors" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
                                </select>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="!canEditAdmin"
                                    :aria-label="t('settings.queue.remove')"
                                    @click="removeShift(index)"
                                >
                                    <Trash2 class="size-3.5 text-destructive" aria-hidden="true" />
                                </Button>
                            </div>
                        </label>
                    </div>
                </section>

                <StickySaveBar :busy="form.processing" :dirty="form.isDirty" :label="t('settings.queue.save')" @save="submit" />
            </form>
        </div>
    </AppLayout>
</template>
