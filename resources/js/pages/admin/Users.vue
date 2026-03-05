<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'operator' | 'viewer';
    approved_at: string | null;
    created_at: string;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type Props = {
    users: Paginated<AdminUser>;
};

defineProps<Props>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'User Management', href: '/admin/users' },
];

const approve = (userId: number) => {
    router.post(`/admin/users/${userId}/approve`);
};

const updateRole = (userId: number, role: string) => {
    router.patch(`/admin/users/${userId}/role`, { role });
};

const deleteTarget = ref<AdminUser | null>(null);
const confirmDelete = () => {
    if (deleteTarget.value) {
        router.delete(`/admin/users/${deleteTarget.value.id}`);
        deleteTarget.value = null;
    }
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="User Management" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="User Management"
                description="Manage users, approve accounts, and assign roles"
            />

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell class="font-medium">{{
                            user.name
                        }}</TableCell>
                        <TableCell>{{ user.email }}</TableCell>
                        <TableCell>
                            <Select
                                :model-value="user.role"
                                :disabled="user.id === currentUserId"
                                @update:model-value="
                                    (v) =>
                                        updateRole(
                                            user.id,
                                            v as string,
                                        )
                                "
                            >
                                <SelectTrigger class="w-32">
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
                        </TableCell>
                        <TableCell>
                            <Badge v-if="user.approved_at" variant="default"
                                >Active</Badge
                            >
                            <Badge
                                v-else
                                variant="secondary"
                                class="bg-yellow-100 text-yellow-800"
                                >Pending</Badge
                            >
                        </TableCell>
                        <TableCell class="space-x-2">
                            <Button
                                v-if="!user.approved_at"
                                size="sm"
                                @click="approve(user.id)"
                            >
                                Approve
                            </Button>
                            <Button
                                v-if="user.id !== currentUserId"
                                size="sm"
                                variant="destructive"
                                @click="deleteTarget = user"
                            >
                                Delete
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <Dialog :open="!!deleteTarget" @update:open="deleteTarget = null">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete User</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete
                        <span class="font-medium">{{
                            deleteTarget?.name
                        }}</span
                        >? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleteTarget = null"
                        >Cancel</Button
                    >
                    <Button variant="destructive" @click="confirmDelete"
                        >Delete</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
