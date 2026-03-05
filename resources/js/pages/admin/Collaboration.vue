<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: {
        allow_invites_from: string;
        allowed_domains: string[];
        blocked_domains: string[];
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Collaboration', href: '/admin/collaboration' },
];

const inviteOptions = [
    { value: 'none', label: 'No one' },
    { value: 'adminsAndGuestInviters', label: 'Admins and Guest Inviters' },
    { value: 'adminsGuestInvitersAndAllMembers', label: 'Admins, Guest Inviters, and Members' },
    { value: 'everyone', label: 'Everyone (including guests)' },
];

function getInitialMode(): string {
    if (props.settings.allowed_domains.length > 0) return 'allowList';
    if (props.settings.blocked_domains.length > 0) return 'blockList';
    return 'none';
}

const form = useForm({
    allow_invites_from: props.settings.allow_invites_from,
    domain_restriction_mode: getInitialMode(),
    allowed_domains: [...props.settings.allowed_domains],
    blocked_domains: [...props.settings.blocked_domains],
});

const newDomain = ref('');

function addDomain(list: 'allowed_domains' | 'blocked_domains') {
    const domain = newDomain.value.trim().toLowerCase();
    if (domain && !form[list].includes(domain)) {
        form[list].push(domain);
    }
    newDomain.value = '';
}

function removeDomain(list: 'allowed_domains' | 'blocked_domains', index: number) {
    form[list].splice(index, 1);
}

const submit = () => {
    form.put('/admin/collaboration');
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="Collaboration Settings" />
        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="External Collaboration"
                description="Control who can invite guests and which domains are allowed or blocked."
            />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="allow_invites_from">Who can invite guests</Label>
                    <Select v-model="form.allow_invites_from">
                        <SelectTrigger id="allow_invites_from">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in inviteOptions"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.allow_invites_from" />
                </div>

                <div class="grid gap-2">
                    <Label for="domain_mode">Domain restrictions</Label>
                    <Select v-model="form.domain_restriction_mode">
                        <SelectTrigger id="domain_mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Allow all domains</SelectItem>
                            <SelectItem value="allowList">Allow only specific domains</SelectItem>
                            <SelectItem value="blockList">Block specific domains</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="form.domain_restriction_mode === 'allowList'" class="grid gap-2">
                    <Label>Allowed domains</Label>
                    <div class="flex gap-2">
                        <Input
                            v-model="newDomain"
                            placeholder="contoso.com"
                            @keydown.enter.prevent="addDomain('allowed_domains')"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            @click="addDomain('allowed_domains')"
                        >
                            Add
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="(domain, i) in form.allowed_domains"
                            :key="domain"
                            variant="secondary"
                            class="cursor-pointer"
                            @click="removeDomain('allowed_domains', i)"
                        >
                            {{ domain }} &times;
                        </Badge>
                    </div>
                    <InputError :message="form.errors.allowed_domains" />
                </div>

                <div v-if="form.domain_restriction_mode === 'blockList'" class="grid gap-2">
                    <Label>Blocked domains</Label>
                    <div class="flex gap-2">
                        <Input
                            v-model="newDomain"
                            placeholder="contoso.com"
                            @keydown.enter.prevent="addDomain('blocked_domains')"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            @click="addDomain('blocked_domains')"
                        >
                            Add
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="(domain, i) in form.blocked_domains"
                            :key="domain"
                            variant="secondary"
                            class="cursor-pointer"
                            @click="removeDomain('blocked_domains', i)"
                        >
                            {{ domain }} &times;
                        </Badge>
                    </div>
                    <InputError :message="form.errors.blocked_domains" />
                </div>

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save Settings' }}
                </Button>
            </form>
        </div>
    </AdminLayout>
</template>
