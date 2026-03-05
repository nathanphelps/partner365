<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: {
        tenant_id: string | null;
        client_id: string | null;
        client_secret_masked: string | null;
        scopes: string | null;
        base_url: string | null;
        sync_interval_minutes: string | number | null;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Microsoft Graph', href: '/admin/graph' },
];

const form = useForm({
    tenant_id: props.settings.tenant_id ?? '',
    client_id: props.settings.client_id ?? '',
    client_secret: '',
    scopes: props.settings.scopes ?? '',
    base_url: props.settings.base_url ?? '',
    sync_interval_minutes: props.settings.sync_interval_minutes ?? 15,
});

const submit = () => {
    form.put('/admin/graph');
};

const testResult = ref<{ success: boolean; message: string } | null>(null);
const testLoading = ref(false);

const testConnection = async () => {
    testResult.value = null;
    testLoading.value = true;

    try {
        const response = await fetch('/admin/graph/test', {
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
        <Head title="Microsoft Graph Settings" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Microsoft Graph Configuration"
                description="Configure credentials for the Microsoft Graph API connection"
            />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="tenant_id">Tenant ID</Label>
                    <Input
                        id="tenant_id"
                        v-model="form.tenant_id"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                    />
                    <InputError :message="form.errors.tenant_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="client_id">Client ID</Label>
                    <Input
                        id="client_id"
                        v-model="form.client_id"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                    />
                    <InputError :message="form.errors.client_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="client_secret">Client Secret</Label>
                    <Input
                        id="client_secret"
                        v-model="form.client_secret"
                        type="password"
                        :placeholder="
                            props.settings.client_secret_masked ??
                            'Enter client secret'
                        "
                    />
                    <p
                        v-if="props.settings.client_secret_masked"
                        class="text-xs text-muted-foreground"
                    >
                        Leave blank to keep the current secret.
                    </p>
                    <InputError :message="form.errors.client_secret" />
                </div>

                <div class="grid gap-2">
                    <Label for="scopes">Scopes</Label>
                    <Input id="scopes" v-model="form.scopes" />
                    <InputError :message="form.errors.scopes" />
                </div>

                <div class="grid gap-2">
                    <Label for="base_url">Base URL</Label>
                    <Input id="base_url" v-model="form.base_url" />
                    <InputError :message="form.errors.base_url" />
                </div>

                <div class="grid gap-2">
                    <Label for="sync_interval_minutes"
                        >Sync Interval (minutes)</Label
                    >
                    <Input
                        id="sync_interval_minutes"
                        v-model="form.sync_interval_minutes"
                        type="number"
                        min="1"
                        max="1440"
                    />
                    <InputError :message="form.errors.sync_interval_minutes" />
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
                    description="Verify credentials against Microsoft Graph"
                />
                <div class="mt-4 flex items-center gap-4">
                    <Button
                        variant="outline"
                        :disabled="testLoading"
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
