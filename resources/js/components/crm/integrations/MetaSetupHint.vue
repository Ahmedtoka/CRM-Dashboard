<script setup lang="ts">
import CopyField from '@/components/crm/integrations/CopyField.vue';
import { useI18n } from '@/composables/useI18n';
import { ChevronDown, Wrench } from 'lucide-vue-next';

/**
 * The one-time Meta App Dashboard setup this connection depends on (webhook object,
 * callback URL, verify token, fields) and the permissions its token needs. Folded by
 * default: it is for whoever manages the Meta app, not for the daily user.
 */
defineProps<{
    object: string;
    callbackUrl: string;
    verifyToken: string;
    fields: string[];
    permissions: string[];
}>();

const { t } = useI18n();
</script>

<template>
    <details class="group rounded-md text-xs">
        <summary
            class="flex w-fit cursor-pointer list-none items-center gap-1.5 rounded px-1 py-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden"
        >
            <Wrench class="size-3.5" aria-hidden="true" />
            {{ t('settings.integrations.meta_setup.title') }}
            <ChevronDown class="size-3.5 transition-transform duration-200 group-open:rotate-180" aria-hidden="true" />
        </summary>
        <div class="mt-2 grid gap-3 rounded-md border border-dashed border-border p-3">
            <p class="leading-relaxed text-muted-foreground">{{ t('settings.integrations.meta_setup.intro') }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <span class="text-2xs font-semibold text-muted-foreground">{{ t('settings.integrations.meta_setup.object') }}</span>
                    <code class="w-fit rounded bg-muted px-2 py-1.5 text-2xs" dir="ltr">{{ object }}</code>
                </div>
                <div class="grid gap-1">
                    <span class="text-2xs font-semibold text-muted-foreground">{{ t('settings.integrations.meta_setup.fields') }}</span>
                    <div class="flex flex-wrap gap-1" dir="ltr">
                        <code v-for="field in fields" :key="field" class="rounded bg-muted px-1.5 py-1 text-2xs">{{ field }}</code>
                    </div>
                </div>
                <CopyField :label="t('settings.integrations.meta_setup.callback_url')" :value="callbackUrl" />
                <CopyField :label="t('settings.integrations.meta_setup.verify_token')" :value="verifyToken" />
            </div>
            <div class="grid gap-1">
                <span class="text-2xs font-semibold text-muted-foreground">{{ t('settings.integrations.meta_setup.permissions') }}</span>
                <div class="flex flex-wrap gap-1" dir="ltr">
                    <code v-for="permission in permissions" :key="permission" class="rounded bg-muted px-1.5 py-1 text-2xs">{{ permission }}</code>
                </div>
            </div>
        </div>
    </details>
</template>
