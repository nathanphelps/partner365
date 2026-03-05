<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    partners: { id: number; display_name: string }[];
    reviewers: { id: number; name: string; role: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
    { title: 'Create', href: '/access-reviews/create' },
];

const form = useForm({
    title: '',
    description: '',
    review_type: 'guest_users' as string,
    scope_partner_id: null as number | null,
    recurrence_type: 'one_time' as string,
    recurrence_interval_days: 90,
    remediation_action: 'flag_only' as string,
    reviewer_user_id: null as number | null,
});

const isPartnerReview = computed(
    () => form.review_type === 'partner_organizations',
);

watch(isPartnerReview, (val) => {
    if (val) {
        form.remediation_action = 'flag_only';
        form.scope_partner_id = null;
    }
});

function submit() {
    form.post('/access-reviews');
}
</script>

<template>
    <Head title="Create Access Review" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <h1 class="mb-6 text-2xl font-semibold">Create Access Review</h1>

            <form @submit.prevent="submit" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Review Details</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label for="title">Title</Label>
                            <Input
                                id="title"
                                v-model="form.title"
                                placeholder="e.g. Q1 Guest Access Review"
                            />
                            <p
                                v-if="form.errors.title"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ form.errors.title }}
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

                        <div>
                            <Label>Review Type</Label>
                            <Select v-model="form.review_type">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="guest_users"
                                        >Guest Users</SelectItem
                                    >
                                    <SelectItem value="partner_organizations"
                                        >Partner Organizations</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div v-if="form.review_type === 'guest_users'">
                            <Label>Scope to Partner (optional)</Label>
                            <Select v-model="form.scope_partner_id">
                                <SelectTrigger
                                    ><SelectValue placeholder="All guests"
                                /></SelectTrigger>
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
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Schedule</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label>Recurrence</Label>
                            <Select v-model="form.recurrence_type">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="one_time"
                                        >One-time</SelectItem
                                    >
                                    <SelectItem value="recurring"
                                        >Recurring</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div v-if="form.recurrence_type === 'recurring'">
                            <Label for="interval">Interval (days)</Label>
                            <Input
                                id="interval"
                                type="number"
                                v-model.number="form.recurrence_interval_days"
                                min="1"
                                max="365"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Remediation &amp; Reviewer</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label>Remediation Action</Label>
                            <Select
                                v-model="form.remediation_action"
                                :disabled="isPartnerReview"
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="flag_only"
                                        >Flag Only</SelectItem
                                    >
                                    <SelectItem
                                        value="disable"
                                        :disabled="isPartnerReview"
                                        >Disable Account</SelectItem
                                    >
                                    <SelectItem
                                        value="remove"
                                        :disabled="isPartnerReview"
                                        >Remove User</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <p
                                v-if="isPartnerReview"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                Partner reviews are always flag-only due to high
                                impact.
                            </p>
                        </div>

                        <div>
                            <Label>Reviewer</Label>
                            <Select v-model="form.reviewer_user_id">
                                <SelectTrigger
                                    ><SelectValue
                                        placeholder="Select a reviewer"
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="r in reviewers"
                                        :key="r.id"
                                        :value="r.id"
                                    >
                                        {{ r.name }} ({{ r.role }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="form.errors.reviewer_user_id"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ form.errors.reviewer_user_id }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        as="a"
                        href="/access-reviews"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Review' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
