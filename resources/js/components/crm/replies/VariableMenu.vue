<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import type { QuickReplyVariable } from '@/types/admin';
import { Variable } from 'lucide-vue-next';

defineProps<{ variables: QuickReplyVariable[] }>();
const emit = defineEmits<{ insert: [token: string] }>();

const { t } = useI18n();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-7 gap-1 px-2 text-2xs')">
            <Variable class="size-3.5" aria-hidden="true" />{{ t('replies.insert_variable') }}
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuItem v-for="v in variables" :key="v.key" class="flex items-center justify-between gap-2 text-xs" @select="emit('insert', v.alias)">
                <span>{{ t('replies.variables.' + v.key) }}</span>
                <kbd class="rounded bg-muted px-1 font-mono text-2xs text-muted-foreground" dir="ltr">{{ '{' + v.alias + '}' }}</kbd>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
