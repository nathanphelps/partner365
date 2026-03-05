<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { GraphGroup, GraphSharePointSite } from '@/types/entitlement';

const props = defineProps<{
    partners: { id: number; display_name: string; tenant_id: string }[];
    approvers: { id: number; name: string; role: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Entitlements', href: '/entitlements' },
    { title: 'Create', href: '/entitlements/create' },
];

const step = ref(1);
const totalSteps = 4;

const availableGroups = ref<GraphGroup[]>([]);
const availableSites = ref<GraphSharePointSite[]>([]);
const loadingGroups = ref(false);
const loadingSites = ref(false);

const form = useForm({
    partner_organization_id: null as number | null,
    display_name: '',
    description: '',
    duration_days: 90,
    approval_required: true,
    approver_user_id: null as number | null,
    resources: [] as {
        resource_type: string;
        resource_id: string;
        resource_display_name: string;
    }[],
});

const selectedPartner = computed(() =>
    props.partners.find((p) => p.id === form.partner_organization_id),
);

async function fetchGroups() {
    loadingGroups.value = true;
    try {
        const res = await fetch('/entitlements-groups');
        availableGroups.value = await res.json();
    } finally {
        loadingGroups.value = false;
    }
}

async function fetchSites() {
    loadingSites.value = true;
    try {
        const res = await fetch('/entitlements-sharepoint-sites');
        availableSites.value = await res.json();
    } finally {
        loadingSites.value = false;
    }
}

function goToStep(s: number) {
    if (s === 2 && !availableGroups.value.length && !loadingGroups.value) {
        fetchGroups();
        fetchSites();
    }
    step.value = s;
}

function addResource(type: string, id: string, name: string) {
    if (!form.resources.some((r) => r.resource_id === id)) {
        form.resources.push({
            resource_type: type,
            resource_id: id,
            resource_display_name: name,
        });
    }
}

function removeResource(index: number) {
    form.resources.splice(index, 1);
}

function submit() {
    form.post('/entitlements');
}
</script>

<template>
    <Head title="Create Access Package" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <h1 class="mb-2 text-2xl font-semibold">Create Access Package</h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Step {{ step }} of {{ totalSteps }}
            </p>

            <!-- Step 1: Select Partner -->
            <form
                v-if="step === 1"
                class="flex flex-col gap-6"
                @submit.prevent="goToStep(2)"
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Select Partner Organization</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label>Partner</Label>
                            <Select v-model="form.partner_organization_id">
                                <SelectTrigger>
                                    <SelectValue
                                        placeholder="Select a partner"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="p in partners"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ p.display_name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="form.errors.partner_organization_id"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ form.errors.partner_organization_id }}
                            </p>
                        </div>

                        <div>
                            <Label for="display_name">Package Name</Label>
                            <Input
                                id="display_name"
                                v-model="form.display_name"
                                placeholder="e.g. Partner Dev Access"
                            />
                            <p
                                v-if="form.errors.display_name"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ form.errors.display_name }}
                            </p>
                        </div>

                        <div>
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Optional description..."
                            />
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="
                            !form.partner_organization_id || !form.display_name
                        "
                    >
                        Next: Add Resources
                    </Button>
                </div>
            </form>

            <!-- Step 2: Add Resources -->
            <div v-if="step === 2" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Add Resources</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div
                            v-if="form.resources.length"
                            class="flex flex-col gap-2"
                        >
                            <p class="text-sm font-medium">
                                Selected Resources:
                            </p>
                            <div
                                v-for="(r, i) in form.resources"
                                :key="r.resource_id"
                                class="flex items-center justify-between rounded border p-2"
                            >
                                <div class="flex items-center gap-2">
                                    <Badge variant="outline">{{
                                        r.resource_type === 'group'
                                            ? 'Group'
                                            : 'SharePoint'
                                    }}</Badge>
                                    <span class="text-sm">{{
                                        r.resource_display_name
                                    }}</span>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="removeResource(i)"
                                    >Remove</Button
                                >
                            </div>
                        </div>
                        <p
                            v-if="form.errors.resources"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.resources }}
                        </p>

                        <div>
                            <p class="mb-2 text-sm font-medium">Groups</p>
                            <p
                                v-if="loadingGroups"
                                class="text-sm text-muted-foreground"
                            >
                                Loading groups...
                            </p>
                            <div
                                v-else
                                class="max-h-48 overflow-y-auto rounded border"
                            >
                                <div
                                    v-for="g in availableGroups"
                                    :key="g.id"
                                    class="flex cursor-pointer items-center justify-between border-b p-2 last:border-0 hover:bg-muted/50"
                                    @click="
                                        addResource(
                                            'group',
                                            g.id,
                                            g.displayName,
                                        )
                                    "
                                >
                                    <span class="text-sm">{{
                                        g.displayName
                                    }}</span>
                                    <span
                                        v-if="
                                            form.resources.some(
                                                (r) => r.resource_id === g.id,
                                            )
                                        "
                                        class="text-xs text-muted-foreground"
                                        >Added</span
                                    >
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="mb-2 text-sm font-medium">
                                SharePoint Sites
                            </p>
                            <p
                                v-if="loadingSites"
                                class="text-sm text-muted-foreground"
                            >
                                Loading sites...
                            </p>
                            <div
                                v-else
                                class="max-h-48 overflow-y-auto rounded border"
                            >
                                <div
                                    v-for="s in availableSites"
                                    :key="s.id"
                                    class="flex cursor-pointer items-center justify-between border-b p-2 last:border-0 hover:bg-muted/50"
                                    @click="
                                        addResource(
                                            'sharepoint_site',
                                            s.id,
                                            s.displayName,
                                        )
                                    "
                                >
                                    <span class="text-sm">{{
                                        s.displayName
                                    }}</span>
                                    <span
                                        v-if="
                                            form.resources.some(
                                                (r) => r.resource_id === s.id,
                                            )
                                        "
                                        class="text-xs text-muted-foreground"
                                        >Added</span
                                    >
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-between">
                    <Button variant="outline" @click="goToStep(1)">Back</Button>
                    <Button
                        :disabled="form.resources.length === 0"
                        @click="goToStep(3)"
                        >Next: Configure Policy</Button
                    >
                </div>
            </div>

            <!-- Step 3: Configure Policy -->
            <form
                v-if="step === 3"
                class="flex flex-col gap-6"
                @submit.prevent="goToStep(4)"
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Access Policy</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label for="duration">Duration (days)</Label>
                            <Input
                                id="duration"
                                type="number"
                                v-model.number="form.duration_days"
                                min="1"
                                max="365"
                            />
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch
                                id="approval"
                                v-model:checked="form.approval_required"
                            />
                            <Label for="approval">Require approval</Label>
                        </div>

                        <div v-if="form.approval_required">
                            <Label>Approver</Label>
                            <Select v-model="form.approver_user_id">
                                <SelectTrigger>
                                    <SelectValue
                                        placeholder="Select an approver"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="a in approvers"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.name }} ({{ a.role }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-between">
                    <Button type="button" variant="outline" @click="goToStep(2)"
                        >Back</Button
                    >
                    <Button type="submit">Next: Review</Button>
                </div>
            </form>

            <!-- Step 4: Review & Submit -->
            <div v-if="step === 4" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Review Access Package</CardTitle>
                    </CardHeader>
                    <CardContent
                        class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                    >
                        <span class="text-muted-foreground">Partner</span>
                        <span>{{ selectedPartner?.display_name }}</span>

                        <span class="text-muted-foreground">Package Name</span>
                        <span>{{ form.display_name }}</span>

                        <span
                            v-if="form.description"
                            class="text-muted-foreground"
                            >Description</span
                        >
                        <span v-if="form.description">{{
                            form.description
                        }}</span>

                        <span class="text-muted-foreground">Duration</span>
                        <span>{{ form.duration_days }} days</span>

                        <span class="text-muted-foreground"
                            >Approval Required</span
                        >
                        <span>{{ form.approval_required ? 'Yes' : 'No' }}</span>

                        <span class="text-muted-foreground">Resources</span>
                        <div class="flex flex-col gap-1">
                            <div
                                v-for="r in form.resources"
                                :key="r.resource_id"
                                class="flex items-center gap-1"
                            >
                                <Badge variant="outline" class="text-xs">{{
                                    r.resource_type === 'group' ? 'Group' : 'SP'
                                }}</Badge>
                                <span>{{ r.resource_display_name }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-between">
                    <Button variant="outline" @click="goToStep(3)">Back</Button>
                    <Button @click="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Package' }}
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
