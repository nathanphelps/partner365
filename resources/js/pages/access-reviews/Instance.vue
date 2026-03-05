<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type {
    AccessReview,
    AccessReviewInstance,
    AccessReviewDecision,
} from '@/types/access-review';

const props = defineProps<{
    review: AccessReview;
    instance: AccessReviewInstance;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
    { title: props.review.title, href: `/access-reviews/${props.review.id}` },
    { title: 'Instance', href: '#' },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function decisionVariant(
    decision: string,
): 'default' | 'destructive' | 'outline' {
    if (decision === 'approve') return 'default';
    if (decision === 'deny') return 'destructive';
    return 'outline';
}

function decisionLabel(decision: string): string {
    return decision.replace(/\b\w/g, (c) => c.toUpperCase());
}

const editingId = ref<number | null>(null);
const decisionForm = useForm({
    decision: '' as string,
    justification: '',
});

function startEdit(d: AccessReviewDecision) {
    editingId.value = d.id;
    decisionForm.decision = d.decision;
    decisionForm.justification = d.justification ?? '';
}

function cancelEdit() {
    editingId.value = null;
    decisionForm.reset();
}

function submitDecision(decisionId: number) {
    decisionForm.post(`/access-reviews/decisions/${decisionId}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

const applying = ref(false);

function applyRemediations() {
    applying.value = true;
    router.post(
        `/access-reviews/instances/${props.instance.id}/apply`,
        {},
        {
            onFinish: () => {
                applying.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${review.title} - Instance`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">{{ review.title }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ formatDate(instance.started_at) }} &mdash;
                    {{ formatDate(instance.due_at) }}
                </p>
            </div>

            <div class="flex flex-wrap gap-4">
                <Card class="flex-1">
                    <CardContent class="pt-6 text-center">
                        <div class="text-2xl font-bold">
                            {{ instance.approved_count ?? 0 }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            Approved
                        </div>
                    </CardContent>
                </Card>
                <Card class="flex-1">
                    <CardContent class="pt-6 text-center">
                        <div class="text-2xl font-bold text-destructive">
                            {{ instance.denied_count ?? 0 }}
                        </div>
                        <div class="text-sm text-muted-foreground">Denied</div>
                    </CardContent>
                </Card>
                <Card class="flex-1">
                    <CardContent class="pt-6 text-center">
                        <div class="text-2xl font-bold">
                            {{ instance.pending_count ?? 0 }}
                        </div>
                        <div class="text-sm text-muted-foreground">Pending</div>
                    </CardContent>
                </Card>
            </div>

            <Separator />

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Decisions</h2>
                <Button
                    v-if="isAdmin && (instance.denied_count ?? 0) > 0"
                    variant="destructive"
                    @click="applyRemediations"
                    :disabled="applying"
                >
                    {{ applying ? 'Applying\u2026' : 'Apply Remediations' }}
                </Button>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Subject</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Decision</TableHead>
                        <TableHead>Justification</TableHead>
                        <TableHead>Decided By</TableHead>
                        <TableHead v-if="canManage"></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="d in instance.decisions" :key="d.id">
                        <TableCell class="font-mono text-xs"
                            >#{{ d.subject_id }}</TableCell
                        >
                        <TableCell>{{
                            d.subject_type === 'guest_user'
                                ? 'Guest'
                                : 'Partner'
                        }}</TableCell>
                        <TableCell>
                            <template v-if="editingId === d.id">
                                <Select v-model="decisionForm.decision">
                                    <SelectTrigger class="w-32"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="approve"
                                            >Approve</SelectItem
                                        >
                                        <SelectItem value="deny"
                                            >Deny</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </template>
                            <Badge
                                v-else
                                :variant="decisionVariant(d.decision)"
                                >{{ decisionLabel(d.decision) }}</Badge
                            >
                        </TableCell>
                        <TableCell>
                            <template v-if="editingId === d.id">
                                <Textarea
                                    v-model="decisionForm.justification"
                                    placeholder="Justification..."
                                    class="w-48"
                                />
                            </template>
                            <template v-else>{{
                                d.justification ?? '\u2014'
                            }}</template>
                        </TableCell>
                        <TableCell>{{
                            d.decided_by?.name ?? '\u2014'
                        }}</TableCell>
                        <TableCell v-if="canManage">
                            <template v-if="editingId === d.id">
                                <div class="flex gap-1">
                                    <Button
                                        size="sm"
                                        @click="submitDecision(d.id)"
                                        :disabled="decisionForm.processing"
                                        >Save</Button
                                    >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="cancelEdit"
                                        >Cancel</Button
                                    >
                                </div>
                            </template>
                            <Button
                                v-else-if="d.decision === 'pending'"
                                size="sm"
                                variant="outline"
                                @click="startEdit(d)"
                            >
                                Review
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
