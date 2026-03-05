<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: {
        enabled: boolean;
        host: string;
        port: number;
        transport: string;
        facility: number;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'SIEM Integration', href: '/admin/syslog' },
];

const form = useForm({
    enabled: props.settings.enabled,
    host: props.settings.host,
    port: props.settings.port,
    transport: props.settings.transport,
    facility: props.settings.facility,
});

const submit = () => {
    form.put('/admin/syslog');
};

const testResult = ref<{ success: boolean; message: string } | null>(null);
const testLoading = ref(false);

const testConnection = async () => {
    testResult.value = null;
    testLoading.value = true;

    try {
        const response = await fetch('/admin/syslog/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
                Accept: 'application/json',
            },
        });
        testResult.value = await response.json();
    } catch {
        testResult.value = { success: false, message: 'Request failed.' };
    } finally {
        testLoading.value = false;
    }
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="SIEM Integration" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="SIEM Integration"
                description="Configure syslog/CEF forwarding to LogRhythm or other SIEM platforms"
            />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="flex items-center gap-3">
                    <Switch
                        id="enabled"
                        :checked="form.enabled"
                        @update:checked="form.enabled = $event"
                    />
                    <Label for="enabled">Enable syslog forwarding</Label>
                </div>

                <div class="grid gap-2">
                    <Label for="host">Syslog Host</Label>
                    <Input
                        id="host"
                        v-model="form.host"
                        placeholder="10.0.0.1 or syslog.example.com"
                        :disabled="!form.enabled"
                    />
                    <InputError :message="form.errors.host" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="port">Port</Label>
                        <Input
                            id="port"
                            v-model="form.port"
                            type="number"
                            min="1"
                            max="65535"
                            :disabled="!form.enabled"
                        />
                        <InputError :message="form.errors.port" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="transport">Transport Protocol</Label>
                        <Select
                            v-model="form.transport"
                            :disabled="!form.enabled"
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="udp">UDP</SelectItem>
                                <SelectItem value="tcp">TCP</SelectItem>
                                <SelectItem value="tls">TLS</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.transport" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="facility">Syslog Facility</Label>
                    <Select v-model="form.facility" :disabled="!form.enabled">
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="16">local0 (16)</SelectItem>
                            <SelectItem :value="17">local1 (17)</SelectItem>
                            <SelectItem :value="18">local2 (18)</SelectItem>
                            <SelectItem :value="19">local3 (19)</SelectItem>
                            <SelectItem :value="20">local4 (20)</SelectItem>
                            <SelectItem :value="21">local5 (21)</SelectItem>
                            <SelectItem :value="22">local6 (22)</SelectItem>
                            <SelectItem :value="23">local7 (23)</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.facility" />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="form.processing">Save</Button>

                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="form.recentlySuccessful"
                            class="text-sm text-muted-foreground"
                        >
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>

            <div class="border-t pt-6">
                <Heading
                    variant="small"
                    title="Test Connection"
                    description="Send a test CEF event to verify connectivity"
                />
                <div class="mt-4 flex items-center gap-4">
                    <Button
                        variant="outline"
                        :disabled="testLoading || !form.enabled"
                        @click="testConnection"
                    >
                        {{ testLoading ? 'Testing...' : 'Test Connection' }}
                    </Button>
                    <p
                        v-if="testResult"
                        :class="
                            testResult.success
                                ? 'text-green-600'
                                : 'text-red-600'
                        "
                        class="text-sm"
                    >
                        {{ testResult.message }}
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
