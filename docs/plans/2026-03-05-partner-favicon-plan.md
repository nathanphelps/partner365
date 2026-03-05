# Partner Favicon Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fetch and cache favicons for partner organizations, display them on index and show pages with initials fallback.

**Architecture:** A `FaviconService` fetches favicons by parsing homepage HTML for `<link rel="icon">` tags (falling back to `/favicon.ico`), stores files in `storage/app/public/favicons/`, and records the path in a new `favicon_path` column. A daily `sync:favicons` command drives the process. Frontend uses the existing Avatar component pattern.

**Tech Stack:** Laravel (HTTP client, Storage, Artisan command), Pest PHP tests, Vue 3 + shadcn-vue Avatar component, TypeScript.

---

### Task 1: Database Migration

**Files:**
- Create: `database/migrations/2026_03_05_XXXXXX_add_favicon_path_to_partner_organizations.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_favicon_path_to_partner_organizations --table=partner_organizations
```

**Step 2: Write migration content**

Edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};
```

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Update model fillable array**

In `app/Models/PartnerOrganization.php`, add `'favicon_path'` to the `$fillable` array after `'domain'`:

```php
protected $fillable = [
    'tenant_id', 'display_name', 'domain', 'favicon_path', 'category', 'owner_user_id', 'notes',
    // ... rest unchanged
];
```

**Step 5: Commit**

```bash
git add -A
git commit -m "feat: add favicon_path column to partner_organizations"
```

---

### Task 2: FaviconService - Tests

**Files:**
- Create: `tests/Feature/FaviconServiceTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Models\PartnerOrganization;
use App\Services\FaviconService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('it parses favicon from link rel icon tag', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/assets/logo.png"></head></html>'),
        'https://example.com/assets/logo.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it falls back to favicon.ico when no link tag', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><title>Hi</title></head></html>'),
        'https://example.com/favicon.ico' => Http::response('fake-ico-bytes', 200, ['Content-Type' => 'image/x-icon']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it handles failed fetch gracefully', function () {
    Http::fake([
        'https://unreachable.test' => Http::response('', 500),
        'https://unreachable.test/favicon.ico' => Http::response('', 500),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'unreachable.test', 'favicon_path' => null]);
    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->toBeNull();
});

test('it skips partners without domain', function () {
    $partner = PartnerOrganization::factory()->create(['domain' => null, 'favicon_path' => null]);
    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->toBeNull();
    Http::assertNothingSent();
});

test('it resolves relative icon href to absolute URL', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" type="image/png" href="favicon-32x32.png"></head></html>'),
        'https://example.com/favicon-32x32.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it prefers larger icon when multiple link tags exist', function () {
    $html = '<html><head>
        <link rel="icon" href="/icon-16.png" sizes="16x16">
        <link rel="icon" href="/icon-32.png" sizes="32x32">
        <link rel="icon" href="/icon-64.png" sizes="64x64">
    </head></html>';

    Http::fake([
        'https://example.com' => Http::response($html),
        'https://example.com/icon-64.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->toContain('favicons/');
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it deletes old favicon when re-fetching', function () {
    Storage::disk('public')->put('favicons/1.png', 'old-bytes');

    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/new-icon.png"></head></html>'),
        'https://example.com/new-icon.png' => Http::response('new-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'favicon_path' => 'favicons/1.png',
    ]);

    $service = app(FaviconService::class);
    $service->fetchForPartner($partner);

    $partner->refresh();
    Storage::disk('public')->assertMissing('favicons/1.png');
    Storage::disk('public')->assertExists($partner->favicon_path);
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=FaviconServiceTest
```

Expected: FAIL — `App\Services\FaviconService` class not found.

**Step 3: Commit**

```bash
git add tests/Feature/FaviconServiceTest.php
git commit -m "test: add FaviconService tests (red)"
```

---

### Task 3: FaviconService - Implementation

**Files:**
- Create: `app/Services/FaviconService.php`

**Step 1: Write the service**

```php
<?php

namespace App\Services;

use App\Models\PartnerOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FaviconService
{
    public function fetchForPartner(PartnerOrganization $partner): void
    {
        if (! $partner->domain) {
            return;
        }

        try {
            $iconUrl = $this->discoverIconUrl($partner->domain);

            if (! $iconUrl) {
                return;
            }

            $response = Http::timeout(10)->get($iconUrl);

            if (! $response->successful()) {
                return;
            }

            $extension = $this->guessExtension($response->header('Content-Type'), $iconUrl);
            $filename = "favicons/{$partner->id}.{$extension}";

            // Delete old favicon if exists
            if ($partner->favicon_path && Storage::disk('public')->exists($partner->favicon_path)) {
                Storage::disk('public')->delete($partner->favicon_path);
            }

            Storage::disk('public')->put($filename, $response->body());

            $partner->update(['favicon_path' => $filename]);
        } catch (\Throwable $e) {
            Log::warning("Favicon fetch failed for partner {$partner->id} ({$partner->domain}): {$e->getMessage()}");
        }
    }

    private function discoverIconUrl(string $domain): ?string
    {
        $baseUrl = "https://{$domain}";

        try {
            $response = Http::timeout(10)->get($baseUrl);

            if ($response->successful()) {
                $iconHref = $this->parseIconFromHtml($response->body());

                if ($iconHref) {
                    return $this->resolveUrl($iconHref, $baseUrl);
                }
            }
        } catch (\Throwable) {
            // Fall through to favicon.ico fallback
        }

        // Fallback: try /favicon.ico directly
        try {
            $fallback = Http::timeout(10)->get("{$baseUrl}/favicon.ico");

            if ($fallback->successful() && str_starts_with($fallback->header('Content-Type', ''), 'image/')) {
                return "{$baseUrl}/favicon.ico";
            }
        } catch (\Throwable) {
            // No favicon available
        }

        return null;
    }

    private function parseIconFromHtml(string $html): ?string
    {
        if (! preg_match_all('/<link\s[^>]*rel=["\'](?:shortcut )?icon["\'][^>]*>/i', $html, $matches)) {
            return null;
        }

        $best = null;
        $bestSize = 0;

        foreach ($matches[0] as $tag) {
            if (! preg_match('/href=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                continue;
            }

            $href = $hrefMatch[1];
            $size = 0;

            if (preg_match('/sizes=["\'](\d+)x\d+["\']/i', $tag, $sizeMatch)) {
                $size = (int) $sizeMatch[1];
            }

            if ($best === null || $size > $bestSize) {
                $best = $href;
                $bestSize = $size;
            }
        }

        return $best;
    }

    private function resolveUrl(string $href, string $baseUrl): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return "https:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$baseUrl}{$href}";
        }

        return "{$baseUrl}/{$href}";
    }

    private function guessExtension(string $contentType, string $url): string
    {
        $map = [
            'image/png' => 'png',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        foreach ($map as $mime => $ext) {
            if (str_starts_with($contentType, $mime)) {
                return $ext;
            }
        }

        // Try from URL
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if ($ext && in_array($ext, ['png', 'ico', 'svg', 'gif', 'jpg', 'jpeg', 'webp'])) {
            return $ext;
        }

        return 'ico';
    }
}
```

**Step 2: Run tests to verify they pass**

```bash
php artisan test --filter=FaviconServiceTest
```

Expected: All 7 tests PASS.

**Step 3: Commit**

```bash
git add app/Services/FaviconService.php
git commit -m "feat: add FaviconService for fetching partner favicons"
```

---

### Task 4: Artisan Command - Tests

**Files:**
- Create: `tests/Feature/SyncFaviconsCommandTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Models\PartnerOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('sync:favicons fetches favicons for partners missing them', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>'),
        'https://example.com/icon.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);

    $this->artisan('sync:favicons')
        ->assertExitCode(0);

    $partner = PartnerOrganization::first();
    expect($partner->favicon_path)->not->toBeNull();
});

test('sync:favicons skips partners that already have a favicon', function () {
    Storage::disk('public')->put('favicons/1.png', 'existing');

    PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => 'favicons/1.png']);

    $this->artisan('sync:favicons')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('sync:favicons --force re-fetches all', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>'),
        'https://example.com/icon.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    Storage::disk('public')->put('favicons/1.png', 'old-bytes');
    PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => 'favicons/1.png']);

    $this->artisan('sync:favicons --force')
        ->assertExitCode(0);

    Http::assertSentCount(2); // homepage + icon download
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=SyncFaviconsCommandTest
```

Expected: FAIL — command `sync:favicons` not registered.

**Step 3: Commit**

```bash
git add tests/Feature/SyncFaviconsCommandTest.php
git commit -m "test: add sync:favicons command tests (red)"
```

---

### Task 5: Artisan Command - Implementation

**Files:**
- Create: `app/Console/Commands/SyncFavicons.php`
- Modify: `routes/console.php`

**Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Services\FaviconService;
use Illuminate\Console\Command;

class SyncFavicons extends Command
{
    protected $signature = 'sync:favicons {--force : Re-fetch all favicons, not just missing ones}';

    protected $description = 'Fetch and cache favicons for partner organizations';

    public function handle(FaviconService $faviconService): int
    {
        $query = PartnerOrganization::whereNotNull('domain');

        if (! $this->option('force')) {
            $query->whereNull('favicon_path');
        }

        $partners = $query->get();

        if ($partners->isEmpty()) {
            $this->info('No partners need favicon updates.');

            return Command::SUCCESS;
        }

        $this->info("Fetching favicons for {$partners->count()} partner(s)...");

        $fetched = 0;
        foreach ($partners as $partner) {
            $faviconService->fetchForPartner($partner);
            $partner->refresh();

            if ($partner->favicon_path) {
                $fetched++;
                $this->line("  Fetched: {$partner->display_name}");
            } else {
                $this->warn("  Failed:  {$partner->display_name}");
            }
        }

        $this->info("Done. {$fetched}/{$partners->count()} favicons cached.");

        return Command::SUCCESS;
    }
}
```

**Step 2: Register in scheduler**

Add to `routes/console.php` after the existing schedule entries:

```php
Schedule::command('sync:favicons')->daily();
```

**Step 3: Run tests to verify they pass**

```bash
php artisan test --filter=SyncFaviconsCommandTest
```

Expected: All 3 tests PASS.

**Step 4: Commit**

```bash
git add app/Console/Commands/SyncFavicons.php routes/console.php
git commit -m "feat: add sync:favicons artisan command with daily schedule"
```

---

### Task 6: TypeScript Type Update

**Files:**
- Modify: `resources/js/types/partner.ts:1-6`

**Step 1: Add `favicon_path` to the PartnerOrganization type**

Add after the `domain` field (line 5):

```typescript
export type PartnerOrganization = {
    id: number;
    tenant_id: string;
    display_name: string;
    domain: string | null;
    favicon_path: string | null;
    category:
        // ... rest unchanged
```

**Step 2: Commit**

```bash
git add resources/js/types/partner.ts
git commit -m "feat: add favicon_path to PartnerOrganization TypeScript type"
```

---

### Task 7: PartnerAvatar Component

**Files:**
- Create: `resources/js/components/PartnerAvatar.vue`

**Step 1: Create the component**

This is a reusable component used on both index and show pages. It uses the existing Avatar + initials pattern from `resources/js/components/UserInfo.vue` and `resources/js/composables/useInitials.ts`.

```vue
<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';

const props = defineProps<{
    name: string;
    faviconPath: string | null;
    size?: 'sm' | 'lg';
}>();

const sizeClass = props.size === 'lg' ? 'size-10' : 'size-6';
const textClass = props.size === 'lg' ? 'text-sm' : 'text-xs';
</script>

<template>
    <Avatar :class="[sizeClass, 'shrink-0 overflow-hidden rounded']">
        <AvatarImage
            v-if="faviconPath"
            :src="`/storage/${faviconPath}`"
            :alt="name"
        />
        <AvatarFallback :class="['rounded text-foreground', textClass]">
            {{ getInitials(name) }}
        </AvatarFallback>
    </Avatar>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/PartnerAvatar.vue
git commit -m "feat: add PartnerAvatar component with initials fallback"
```

---

### Task 8: Index Page - Add Favicon

**Files:**
- Modify: `resources/js/pages/partners/Index.vue`

**Step 1: Add import**

Add after the existing imports (around line 5):

```typescript
import PartnerAvatar from '@/components/PartnerAvatar.vue';
```

**Step 2: Update the Name table cell**

Replace the Name `<td>` (lines 167-174) with:

```vue
<td class="px-4 py-3">
    <Link
        :href="partnerRoutes.show.url(partner.id)"
        class="inline-flex items-center gap-2 font-medium text-foreground hover:underline"
    >
        <PartnerAvatar
            :name="partner.display_name"
            :favicon-path="partner.favicon_path"
            size="sm"
        />
        {{ partner.display_name }}
    </Link>
</td>
```

**Step 3: Commit**

```bash
git add resources/js/pages/partners/Index.vue
git commit -m "feat: show partner favicon on index page"
```

---

### Task 9: Show Page - Add Favicon

**Files:**
- Modify: `resources/js/pages/partners/Show.vue`

**Step 1: Add import**

Add after existing imports (around line 7):

```typescript
import PartnerAvatar from '@/components/PartnerAvatar.vue';
```

**Step 2: Update the header**

Replace the `<div class="flex flex-wrap items-center gap-3">` section (lines 296-302) with:

```vue
<div class="flex flex-wrap items-center gap-3">
    <PartnerAvatar
        :name="partner.display_name"
        :favicon-path="partner.favicon_path"
        size="lg"
    />
    <h1 class="text-2xl font-semibold">
        {{ partner.display_name }}
    </h1>
    <Badge variant="secondary">{{
        categoryLabel[partner.category] ?? partner.category
    }}</Badge>
</div>
```

**Step 3: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: show partner favicon on show page header"
```

---

### Task 10: Storage Link & Verification

**Step 1: Ensure storage link exists**

```bash
php artisan storage:link
```

This creates `public/storage` -> `storage/app/public`. The link may already exist (check first). This is a one-time setup operation.

**Step 2: Run full test suite**

```bash
composer run test
```

Expected: All tests pass including the new FaviconService and SyncFavicons tests.

**Step 3: Run lint and type checks**

```bash
composer run ci:check
```

Expected: No lint or type errors.

**Step 4: Final commit (if any fixes needed)**

```bash
git add -A
git commit -m "chore: fix any lint/type issues from favicon feature"
```
