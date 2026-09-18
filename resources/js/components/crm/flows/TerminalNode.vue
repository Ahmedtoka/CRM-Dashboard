<script setup lang="ts">
import type { TerminalNodeData } from '@/types/flows';
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { CircleStop } from 'lucide-vue-next';

defineProps<NodeProps<TerminalNodeData>>();

// Chip colours per menu action kind; `end` is the big dark pill.
const tone: Record<TerminalNodeData['kind'], string> = {
    flow: 'border-indigo-300 bg-indigo-50 text-indigo-800 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-200',
    menu: 'border-violet-300 bg-violet-50 text-violet-800 dark:border-violet-500/40 dark:bg-violet-500/10 dark:text-violet-200',
    script: 'border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-500/40 dark:bg-orange-500/10 dark:text-orange-200',
    handover: 'border-pink-300 bg-pink-50 text-pink-800 dark:border-pink-500/40 dark:bg-pink-500/10 dark:text-pink-200',
    unknown: 'border-border bg-muted text-muted-foreground',
    end: '',
};
</script>

<template>
    <div
        v-if="data.kind === 'end'"
        class="flex items-center gap-2 rounded-full bg-slate-800 px-5 py-2.5 text-sm font-semibold text-white shadow-card dark:bg-slate-200 dark:text-slate-900"
        dir="rtl"
    >
        <Handle id="in" type="target" :position="Position.Top" class="flow-handle flow-handle--in" />
        <CircleStop class="size-4" aria-hidden="true" />{{ data.label }}
    </div>
    <div
        v-else
        class="h-6 max-w-[200px] truncate rounded-full border px-3 text-2xs font-medium leading-[22px] shadow-sm"
        :class="tone[data.kind]"
        dir="rtl"
        :title="data.label"
    >
        <Handle id="in" type="target" :position="Position.Right" :connectable="false" class="flow-handle flow-handle--chip" />
        {{ data.label }}
    </div>
</template>
