<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import templates from '@/routes/templates';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    template: {
        id: number;
        name: string;
        description: string;
        policy_config: Record<string, boolean>;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Templates', href: templates.index.url() },
    { title: props.template.name, href: templates.edit.url(props.template.id) },
];

const policyKeys = [
    { key: 'mfa_trust_enabled', label: 'MFA Trust', description: 'Trust MFA claims from partner tenants.' },
    { key: 'device_trust_enabled', label: 'Device Trust', description: 'Trust device compliance from partner tenants.' },
    { key: 'direct_connect_enabled', label: 'Direct Connect', description: 'Allow Teams direct connect.' },
    { key: 'b2b_inbound_enabled', label: 'B2B Inbound', description: 'Allow inbound B2B collaboration.' },
    { key: 'b2b_outbound_enabled', label: 'B2B Outbound', description: 'Allow outbound B2B collaboration.' },
];

const defaultPolicyConfig: Record<string, boolean> = {
    mfa_trust_enabled: false,
    device_trust_enabled: false,
    direct_connect_enabled: false,
    b2b_inbound_enabled: false,
    b2b_outbound_enabled: false,
};

const form = useForm({
    name: props.template.name,
    description: props.template.description ?? '',
    policy_config: { ...defaultPolicyConfig, ...props.template.policy_config } as Record<string, boolean>,
});

function submit() {
    form.put(templates.update.url(props.template.id));
}

// Delete
const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deleteTemplate() {
    deleting.value = true;
    router.delete(templates.destroy.url(props.template.id), {
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <Head :title="`Edit: ${template.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 max-w-xl">
            <div>
                <h1 class="text-2xl font-semibold">Edit Template</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Update the policy configuration for "{{ template.name }}".
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Template Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="flex flex-col gap-5">
                        <!-- Name -->
                        <div class="flex flex-col gap-1.5">
                            <Label for="name">Name <span class="text-destructive">*</span></Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                placeholder="e.g. Standard Vendor"
                                required
                                :class="form.errors.name ? 'border-destructive' : ''"
                            />
                            <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                        </div>

                        <!-- Description -->
                        <div class="flex flex-col gap-1.5">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Describe when to use this template..."
                                class="min-h-[80px]"
                            />
                            <p v-if="form.errors.description" class="text-xs text-destructive">{{ form.errors.description }}</p>
                        </div>

                        <Separator />

                        <!-- Policy config -->
                        <div class="flex flex-col gap-3">
                            <p class="text-sm font-medium">Policy Configuration</p>
                            <div
                                v-for="policy in policyKeys"
                                :key="policy.key"
                                class="flex items-center justify-between py-1.5"
                            >
                                <div>
                                    <p class="text-sm font-medium">{{ policy.label }}</p>
                                    <p class="text-xs text-muted-foreground">{{ policy.description }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Checkbox
                                        :id="`policy-${policy.key}`"
                                        :checked="form.policy_config[policy.key]"
                                        @update:checked="(v: boolean) => { form.policy_config[policy.key] = v; }"
                                    />
                                    <Label :for="`policy-${policy.key}`" class="cursor-pointer text-sm w-8">
                                        {{ form.policy_config[policy.key] ? 'On' : 'Off' }}
                                    </Label>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2 pt-2">
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Saving…' : 'Save Changes' }}
                            </Button>
                            <Link :href="templates.index.url()">
                                <Button type="button" variant="outline">Cancel</Button>
                            </Link>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Danger Zone -->
            <Card class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="text-sm text-muted-foreground mb-3">
                            Permanently delete this template. Partners using it will not be affected.
                        </p>
                        <Button variant="destructive" @click="showDeleteConfirm = true">
                            Delete Template
                        </Button>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">Are you sure? This cannot be undone.</p>
                        <div class="flex gap-2">
                            <Button variant="destructive" @click="deleteTemplate" :disabled="deleting">
                                {{ deleting ? 'Deleting…' : 'Yes, Delete' }}
                            </Button>
                            <Button variant="outline" @click="showDeleteConfirm = false">Cancel</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
