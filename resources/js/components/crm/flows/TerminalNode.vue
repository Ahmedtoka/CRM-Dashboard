<script setup lang="ts">
import type { TerminalNodeData } from '@/types/flows';
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { CircleStop } from 'lucide-vue-next';

defineProps<NodeProps<TerminalNodeData>>();

// Chip colours per menu action kind; `end` is the big dark pill.
const tone: Record<TerminalNodeData['kind'], string> = {
    flow: 'border-info bg-info-soft text-info',
    menu: 'border-note bg-note-soft text-note-ink',
    script: 'border-warn bg-warn-soft text-warn',
    handover: 'border-brand bg-brand-soft text-brand-ink',
    unknown: 'border-line bg-sunken text-fg-2',
    end: '',
};
</script>

<template>
    <div
        v-if="data.kind === 'end'"
        class="flex items-center gap-2 rounded-full bg-foreground px-5 py-2.5 text-sm font-semibold text-background shadow-card"
        dir="rtl"
    >
        <Handle id="in" type="target" :position="Position.Top" class="flow-handle flow-handle--in" />
        <CircleStop class="size-4" aria-hidden="true" />{{ data.label }}
    </div>
    <div
        v-else
        class="text-12 h-6 max-w-[200px] truncate rounded-full border px-3 font-medium leading-[22px] shadow-sm"
        :class="tone[data.kind]"
        dir="rtl"
        :title="data.label"
    >
        <Handle id="in" type="target" :position="Position.Right" :connectable="false" class="flow-handle flow-handle--chip" />
        {{ data.label }}
    </div>
</template>
