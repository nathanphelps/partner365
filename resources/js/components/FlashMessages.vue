<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, TriangleAlert, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

interface FlashProps {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

const page = usePage<{ flash: FlashProps }>();

const visible = ref({ success: true, error: true, warning: true });

const flash = computed<FlashProps>(() => page.props.flash ?? {});

// Reset visibility each time a new flash arrives (navigation, redirect).
watch(
    () => [flash.value.success, flash.value.error, flash.value.warning],
    () => {
        visible.value = { success: true, error: true, warning: true };
    },
);

function dismiss(kind: keyof FlashProps) {
    visible.value[kind] = false;
}
</script>

<template>
    <div
        class="pointer-events-none fixed top-4 right-4 z-50 flex w-full max-w-md flex-col gap-2"
        aria-live="polite"
        role="status"
    >
        <Alert
            v-if="flash.success && visible.success"
            variant="default"
            class="pointer-events-auto flex items-start gap-2 border-green-300 bg-green-50 text-green-900"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
            <AlertDescription class="flex-1">{{
                flash.success
            }}</AlertDescription>
            <button
                type="button"
                class="shrink-0 rounded p-0.5 hover:bg-green-100"
                @click="dismiss('success')"
            >
                <X class="size-4" />
            </button>
        </Alert>

        <Alert
            v-if="flash.error && visible.error"
            variant="destructive"
            class="pointer-events-auto flex items-start gap-2"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" />
            <AlertDescription class="flex-1">{{
                flash.error
            }}</AlertDescription>
            <button
                type="button"
                class="shrink-0 rounded p-0.5 hover:bg-red-100"
                @click="dismiss('error')"
            >
                <X class="size-4" />
            </button>
        </Alert>

        <Alert
            v-if="flash.warning && visible.warning"
            variant="default"
            class="pointer-events-auto flex items-start gap-2 border-yellow-300 bg-yellow-50 text-yellow-900"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <AlertDescription class="flex-1">{{
                flash.warning
            }}</AlertDescription>
            <button
                type="button"
                class="shrink-0 rounded p-0.5 hover:bg-yellow-100"
                @click="dismiss('warning')"
            >
                <X class="size-4" />
            </button>
        </Alert>
    </div>
</template>
