<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
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

type GroupMapping = {
    entra_group_id: string;
    entra_group_name: string;
    role: string;
};

type Props = {
    settings: {
        enabled: boolean;
        auto_approve: boolean;
        default_role: string;
        group_mapping_enabled: boolean;
        group_mappings: GroupMapping[];
        restrict_provisioning_to_mapped_groups: boolean;
    };
    graphConfigured: boolean;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'SSO', href: '/admin/sso' },
];

const form = useForm({
    enabled: props.settings.enabled,
    auto_approve: props.settings.auto_approve,
    default_role: props.settings.default_role,
    group_mapping_enabled: props.settings.group_mapping_enabled,
    group_mappings:
        props.settings.group_mappings.length > 0
            ? props.settings.group_mappings
            : ([] as GroupMapping[]),
    restrict_provisioning_to_mapped_groups:
        props.settings.restrict_provisioning_to_mapped_groups,
});

const addMapping = () => {
    form.group_mappings.push({
        entra_group_id: '',
        entra_group_name: '',
        role: 'viewer',
    });
};

const removeMapping = (index: number) => {
    form.group_mappings.splice(index, 1);
};

const getError = (key: string) => (form.errors as Record<string, string>)[key];

const submit = () => {
    form.put('/admin/sso');
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="SSO Settings" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Single Sign-On (SSO)"
                description="Configure Entra ID (Azure AD) sign-in for your users"
            />

            <div
                v-if="!graphConfigured"
                class="rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200"
            >
                Graph API credentials are not configured. SSO uses the same app
                registration.
                <TextLink href="/admin/graph"
                    >Configure Graph credentials first.</TextLink
                >
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="flex items-center gap-3">
                    <Switch
                        id="enabled"
                        :checked="form.enabled"
                        @update:checked="form.enabled = $event"
                    />
                    <Label for="enabled">Enable Entra ID SSO</Label>
                </div>

                <div class="space-y-6 border-t pt-6">
                    <Heading
                        variant="small"
                        title="User Provisioning"
                        description="How new users are handled when they first sign in via SSO"
                    />

                    <div class="flex items-center gap-3">
                        <Switch
                            id="auto_approve"
                            :checked="form.auto_approve"
                            :disabled="!form.enabled"
                            @update:checked="form.auto_approve = $event"
                        />
                        <Label for="auto_approve">Auto-approve SSO users</Label>
                    </div>
                    <p class="-mt-4 text-xs text-muted-foreground">
                        When disabled, SSO users must be approved by an admin
                        before accessing the application.
                    </p>

                    <div class="grid gap-2">
                        <Label for="default_role">Default Role</Label>
                        <Select
                            v-model="form.default_role"
                            :disabled="!form.enabled"
                        >
                            <SelectTrigger class="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="operator"
                                    >Operator</SelectItem
                                >
                                <SelectItem value="viewer">Viewer</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.default_role" />
                        <p class="text-xs text-muted-foreground">
                            Role assigned to new users who sign in via SSO (when
                            no group mapping matches).
                        </p>
                    </div>
                </div>

                <div class="space-y-6 border-t pt-6">
                    <Heading
                        variant="small"
                        title="Group Mapping"
                        description="Map Entra ID security groups to application roles"
                    />

                    <div class="flex items-center gap-3">
                        <Switch
                            id="group_mapping_enabled"
                            :checked="form.group_mapping_enabled"
                            :disabled="!form.enabled"
                            @update:checked="
                                form.group_mapping_enabled = $event
                            "
                        />
                        <Label for="group_mapping_enabled"
                            >Enable group-to-role mapping</Label
                        >
                    </div>

                    <div
                        v-if="form.group_mapping_enabled && form.enabled"
                        class="space-y-4"
                    >
                        <div class="flex items-center gap-3">
                            <Switch
                                id="restrict_provisioning"
                                :checked="
                                    form.restrict_provisioning_to_mapped_groups
                                "
                                @update:checked="
                                    form.restrict_provisioning_to_mapped_groups =
                                        $event
                                "
                            />
                            <Label for="restrict_provisioning"
                                >Only allow users in mapped groups</Label
                            >
                        </div>
                        <p class="-mt-2 text-xs text-muted-foreground">
                            When enabled, users not in any mapped group will be
                            denied access.
                        </p>

                        <div
                            v-for="(mapping, index) in form.group_mappings"
                            :key="index"
                            class="flex items-start gap-3"
                        >
                            <div class="grid flex-1 gap-1">
                                <Label
                                    :for="`group_id_${index}`"
                                    class="text-xs"
                                    >Group ID</Label
                                >
                                <Input
                                    :id="`group_id_${index}`"
                                    v-model="mapping.entra_group_id"
                                    placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                />
                                <InputError
                                    :message="
                                        getError(
                                            `group_mappings.${index}.entra_group_id`,
                                        )
                                    "
                                />
                            </div>
                            <div class="grid flex-1 gap-1">
                                <Label
                                    :for="`group_name_${index}`"
                                    class="text-xs"
                                    >Display Name</Label
                                >
                                <Input
                                    :id="`group_name_${index}`"
                                    v-model="mapping.entra_group_name"
                                    placeholder="e.g. P365 Admins"
                                />
                            </div>
                            <div class="grid w-36 gap-1">
                                <Label class="text-xs">Role</Label>
                                <Select v-model="mapping.role">
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="admin"
                                            >Admin</SelectItem
                                        >
                                        <SelectItem value="operator"
                                            >Operator</SelectItem
                                        >
                                        <SelectItem value="viewer"
                                            >Viewer</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        getError(`group_mappings.${index}.role`)
                                    "
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="mt-6 text-destructive"
                                @click="removeMapping(index)"
                            >
                                Remove
                            </Button>
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addMapping"
                        >
                            Add Group Mapping
                        </Button>
                    </div>
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
        </div>
    </AdminLayout>
</template>
