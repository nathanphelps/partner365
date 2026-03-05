<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Pending Approval" />
    <div class="flex min-h-screen items-center justify-center bg-background p-4">
        <Card class="w-full max-w-md">
            <CardHeader class="text-center">
                <div class="mx-auto mb-4">
                    <AppLogoIcon class="h-10 w-10" />
                </div>
                <CardTitle>Account Pending Approval</CardTitle>
                <CardDescription>
                    Your account is awaiting administrator approval.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4 text-center">
                <p class="text-sm text-muted-foreground">
                    Signed in as <span class="font-medium text-foreground">{{ user.email }}</span>
                </p>
                <Link href="/logout" method="post" as="button" class="w-full">
                    <Button variant="outline" class="w-full">Log out</Button>
                </Link>
            </CardContent>
        </Card>
    </div>
</template>
