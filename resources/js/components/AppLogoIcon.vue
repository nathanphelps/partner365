<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        :class="className"
        v-bind="$attrs"
    >
        <!-- Shield shape -->
        <path
            fill="currentColor"
            d="M24 2L6 10v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V10L24 2zm0 4.4L38 12v10c0 9.4-6.5 18.2-14 20.7V4.4zM10 12l14-5.6v38.3C16.5 42.2 10 33.4 10 22V12z"
            opacity="0.15"
        />
        <path
            fill="currentColor"
            d="M24 2L6 10v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V10L24 2zm14 20c0 9.4-6.5 18.2-14 20.7C16.5 40.2 10 31.4 10 22V12l14-5.6L38 12v10z"
        />
        <!-- "365" text -->
        <text
            x="24"
            y="28"
            text-anchor="middle"
            fill="currentColor"
            font-family="Inter, system-ui, sans-serif"
            font-size="11"
            font-weight="700"
        >365</text>
    </svg>
</template>
