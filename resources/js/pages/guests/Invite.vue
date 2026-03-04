<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import guests from '@/routes/guests';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Guest Users', href: guests.index.url() },
    { title: 'Invite Guest', href: guests.create.url() },
];

const form = useForm({
    email: '',
    redirect_url: '',
    message: '',
    send_email: true,
});

function submit() {
    form.post(guests.store.url());
}
</script>

<template>
    <Head title="Invite Guest" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 max-w-xl">
            <div>
                <h1 class="text-2xl font-semibold">Invite Guest User</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Send an invitation to an external user to join your M365 tenant as a guest.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Invitation Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="flex flex-col gap-5">
                        <!-- Email -->
                        <div class="flex flex-col gap-1.5">
                            <Label for="email">Email Address <span class="text-destructive">*</span></Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                placeholder="user@example.com"
                                required
                                :class="form.errors.email ? 'border-destructive' : ''"
                            />
                            <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                        </div>

                        <!-- Redirect URL -->
                        <div class="flex flex-col gap-1.5">
                            <Label for="redirect-url">Redirect URL</Label>
                            <Input
                                id="redirect-url"
                                v-model="form.redirect_url"
                                type="url"
                                placeholder="https://myapp.example.com"
                            />
                            <p class="text-xs text-muted-foreground">
                                Where the guest is redirected after accepting the invitation. Leave blank for the default.
                            </p>
                            <p v-if="form.errors.redirect_url" class="text-xs text-destructive">{{ form.errors.redirect_url }}</p>
                        </div>

                        <!-- Custom message -->
                        <div class="flex flex-col gap-1.5">
                            <Label for="message">Custom Message</Label>
                            <Textarea
                                id="message"
                                v-model="form.message"
                                placeholder="Add a personal message to include in the invitation email..."
                                class="min-h-[100px]"
                            />
                            <p v-if="form.errors.message" class="text-xs text-destructive">{{ form.errors.message }}</p>
                        </div>

                        <!-- Send email checkbox -->
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="send-email"
                                :checked="form.send_email"
                                @update:checked="(v: boolean) => { form.send_email = v; }"
                            />
                            <Label for="send-email" class="cursor-pointer">
                                Send invitation email to the guest
                            </Label>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2 pt-2">
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Sending…' : 'Send Invitation' }}
                            </Button>
                            <Link :href="guests.index.url()">
                                <Button type="button" variant="outline">Cancel</Button>
                            </Link>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
