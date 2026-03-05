<?php

use App\Enums\UserRole;
use App\Models\User;

test('unauthenticated users cannot access docs', function () {
    $this->get('/docs')->assertRedirect(route('login'));
});

test('authenticated users can access docs index', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('docs/Show')
            ->has('sidebar')
            ->has('content')
            ->has('currentPage')
        );
});

test('authenticated users can access a specific doc page', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs/getting-started/overview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('docs/Show')
            ->has('content')
            ->where('currentPage', 'getting-started/overview')
        );
});

test('invalid doc page returns 404', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs/nonexistent/page')
        ->assertNotFound();
});

test('sidebar contains expected sections', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('docs/Show')
            ->has('sidebar', fn ($sidebar) => $sidebar
                ->each(fn ($section) => $section
                    ->has('name')
                    ->has('admin')
                    ->has('pages')
                )
            )
        );
});

test('path traversal is blocked', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs/../../etc/passwd')
        ->assertNotFound();
});
