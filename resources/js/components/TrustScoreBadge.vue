<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    score: number | null;
}>();

const tier = computed(() => {
    if (props.score === null) return null;
    if (props.score >= 80)
        return {
            label: 'High',
            color: 'text-green-700 bg-green-100 dark:text-green-400 dark:bg-green-950',
        };
    if (props.score >= 50)
        return {
            label: 'Medium',
            color: 'text-amber-700 bg-amber-100 dark:text-amber-400 dark:bg-amber-950',
        };
    return {
        label: 'Low',
        color: 'text-red-700 bg-red-100 dark:text-red-400 dark:bg-red-950',
    };
});
</script>

<template>
    <span v-if="score !== null" class="inline-flex items-center gap-1.5">
        <span
            class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
            :class="tier?.color"
        >
            {{ score }}
        </span>
    </span>
    <span v-else class="text-sm text-muted-foreground">&mdash;</span>
</template>
