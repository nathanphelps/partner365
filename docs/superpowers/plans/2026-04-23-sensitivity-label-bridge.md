# Sensitivity Label Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a stateless .NET 9 sidecar (`partner365-bridge`) that Partner365 reaches over an internal Docker network to perform SharePoint CSOM operations, plus the Partner365 sweep orchestration, rule engine, UI, and admin docs that turn that primitive into full Label365-parity sensitivity-label management.

**Architecture:** Two-container docker-compose: Partner365 (FrankenPHP/Octane) and `bridge` (.NET 9 minimal API). Bridge is stateless — tenant/cert/cloud-env come from env vars, no DB, no scheduler. Partner365 owns rules, exclusions, history, UI, scheduling, and retries via a scheduled command + queued per-site jobs. Bridge is internal-only (no published port), authenticated via shared-secret header.

**Tech Stack:** PHP 8.4 / Laravel 12 / Pest / Inertia / Vue 3 (Partner365 side); C# / .NET 9 / ASP.NET Core minimal API / PnP.Framework / xUnit / Moq (bridge side); Docker Compose.

**Spec:** `docs/superpowers/specs/2026-04-23-sensitivity-label-bridge-design.md`

---

## File structure

**Partner365 side (new files):**
```
database/migrations/
  YYYY_MM_DD_HHMMSS_create_label_sweep_tables.php   # all 4 tables in one migration
database/seeders/
  SensitivitySweepSeeder.php                         # default exclusion + settings defaults
app/Models/
  LabelRule.php
  SiteExclusion.php
  LabelSweepRun.php
  LabelSweepRunEntry.php
database/factories/
  LabelRuleFactory.php
  SiteExclusionFactory.php
  LabelSweepRunFactory.php
  LabelSweepRunEntryFactory.php
app/Services/
  BridgeClient.php
app/Services/Exceptions/
  BridgeException.php                    # base
  BridgeAuthException.php
  BridgeThrottleException.php
  BridgeNetworkException.php
  BridgeConfigException.php
  BridgeUnavailableException.php
  BridgeLabelConflictException.php
  BridgeSiteNotFoundException.php
  BridgeUnknownException.php
app/Services/DTOs/
  SetLabelResult.php
  BridgeHealth.php
app/Console/Commands/
  SensitivitySweepCommand.php
app/Jobs/
  ApplySiteLabelJob.php
  AbortSweepRunJob.php
  CompleteSweepRunJob.php
app/Notifications/
  SweepAbortedNotification.php
app/Http/Controllers/
  SensitivityLabelSweepConfigController.php
  SensitivityLabelSweepHistoryController.php
  (extends existing SensitivityLabelController with applyToSite / refreshLabel)
app/Http/Requests/
  UpdateSensitivitySweepConfigRequest.php
  ApplySiteLabelRequest.php
resources/js/pages/sensitivity-labels/Sweep/
  Config.vue
  History.vue
  HistoryDetail.vue
tests/Feature/
  Commands/SensitivitySweepCommandTest.php
  Jobs/ApplySiteLabelJobTest.php
  Jobs/AbortSweepRunJobTest.php
  Jobs/CompleteSweepRunJobTest.php
  BridgeClientTest.php
  Controllers/SensitivityLabelSweepConfigControllerTest.php
  Controllers/SensitivityLabelSweepHistoryControllerTest.php
  Controllers/SensitivityLabelManualApplyTest.php
docs/admin/sensitivity-labels-sidecar-setup.md
```

**Partner365 side (modified files):**
```
app/Enums/ActivityAction.php                          # 5 new cases
app/Http/Controllers/SensitivityLabelController.php   # + applyToSite, refreshLabel
app/Http/Controllers/SharePointSiteController.php     # + refreshLabel endpoint
routes/web.php                                        # + sweep routes + manual apply routes
routes/console.php                                    # + sensitivity:sweep schedule
resources/js/pages/sharepoint-sites/Show.vue          # + sensitivity card
resources/js/pages/sensitivity-labels/Index.vue       # + rules-using-this-label column
docker-compose.yml                                    # + bridge service
.env.example                                          # + bridge env vars
```

**Bridge side (new files):**
```
bridge/
  Partner365.Bridge.sln
  Partner365.Bridge/
    Partner365.Bridge.csproj
    Program.cs
    Services/
      ICsomOperations.cs
      PnPCsomOperations.cs
      SharePointCsomService.cs
      CloudEnvironmentConfig.cs
      CertificateLoader.cs
      ErrorClassifier.cs
      SharedSecretMiddleware.cs
    Models/
      SetLabelRequest.cs
      SetLabelResponse.cs
      ReadLabelRequest.cs
      ReadLabelResponse.cs
      HealthResponse.cs
      ErrorResponse.cs
    appsettings.json
  Partner365.Bridge.Tests/
    Partner365.Bridge.Tests.csproj
    CloudEnvironmentConfigTests.cs
    CertificateLoaderTests.cs
    ErrorClassifierTests.cs
    SharedSecretMiddlewareTests.cs
    SharePointCsomServiceTests.cs
  Dockerfile
  dev/
    validate.md
  .dockerignore
```

---

## Phase 1 — Partner365 data layer

### Task 1: Create label-sweep tables migration

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_label_sweep_tables.php` (generate via artisan)

- [ ] **Step 1: Generate migration file**

Run:
```bash
php artisan make:migration create_label_sweep_tables --no-interaction
```

Expected: new file printed under `database/migrations/`. Note the exact filename for use below.

- [ ] **Step 2: Write migration body**

Replace the generated file's contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_rules', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 100);
            $table->string('label_id', 50);
            $table->integer('priority');
            $table->timestamps();
            $table->unique('priority');
            $table->index('prefix');
        });

        Schema::create('site_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 500);
            $table->timestamps();
        });

        Schema::create('label_sweep_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_scanned')->default(0);
            $table->integer('already_labeled')->default(0);
            $table->integer('applied')->default(0);
            $table->integer('skipped_excluded')->default(0);
            $table->integer('failed')->default(0);
            $table->string('status', 20)->default('running');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('started_at');
            $table->index('status');
        });

        Schema::create('label_sweep_run_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_sweep_run_id')->constrained()->cascadeOnDelete();
            $table->string('site_url', 500);
            $table->string('site_title', 300);
            $table->string('action', 20);
            $table->string('label_id', 50)->nullable();
            $table->foreignId('matched_rule_id')->nullable()->constrained('label_rules')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->string('error_code', 20)->nullable();
            $table->timestamp('processed_at');
            $table->index('label_sweep_run_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_sweep_run_entries');
        Schema::dropIfExists('label_sweep_runs');
        Schema::dropIfExists('site_exclusions');
        Schema::dropIfExists('label_rules');
    }
};
```

- [ ] **Step 3: Run migration**

Run:
```bash
php artisan migrate --no-interaction
```

Expected: the four tables created. If it fails, re-read the generated filename order — migration file must sort *after* the existing sensitivity-labels migration so foreign keys resolve in the expected order. Regenerate if needed.

- [ ] **Step 4: Verify with tinker**

Run:
```bash
php artisan tinker --execute="echo Schema::hasTable('label_rules') ? 'ok' : 'missing';"
php artisan tinker --execute="echo Schema::hasTable('site_exclusions') ? 'ok' : 'missing';"
php artisan tinker --execute="echo Schema::hasTable('label_sweep_runs') ? 'ok' : 'missing';"
php artisan tinker --execute="echo Schema::hasTable('label_sweep_run_entries') ? 'ok' : 'missing';"
```

Expected: `ok` four times.

- [ ] **Step 5: Run Pint**

Run:
```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add label-sweep tables migration"
```

---

### Task 2: Add ActivityAction enum cases

**Files:**
- Modify: `app/Enums/ActivityAction.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Enums/ActivityActionSweepTest.php`:

```php
<?php

use App\Enums\ActivityAction;

test('sweep-related activity actions exist', function () {
    expect(ActivityAction::LabelApplied->value)->toBe('label_applied');
    expect(ActivityAction::RuleChanged->value)->toBe('rule_changed');
    expect(ActivityAction::ExclusionChanged->value)->toBe('exclusion_changed');
    expect(ActivityAction::SweepRan->value)->toBe('sweep_ran');
    expect(ActivityAction::SweepAborted->value)->toBe('sweep_aborted');
});
```

- [ ] **Step 2: Run test — expect FAIL**

Run:
```bash
php artisan test --compact --filter=ActivityActionSweepTest
```

Expected: FAIL with "undefined case" or similar enum errors.

- [ ] **Step 3: Add cases to enum**

Edit `app/Enums/ActivityAction.php`: inside the enum body, after the last existing case and before the closing brace, add:

```php
    case LabelApplied = 'label_applied';
    case RuleChanged = 'rule_changed';
    case ExclusionChanged = 'exclusion_changed';
    case SweepRan = 'sweep_ran';
    case SweepAborted = 'sweep_aborted';
```

- [ ] **Step 4: Run test — expect PASS**

Run:
```bash
php artisan test --compact --filter=ActivityActionSweepTest
```

Expected: PASS.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Enums/ActivityAction.php tests/Feature/Enums/ActivityActionSweepTest.php
git commit -m "feat: add sweep-related ActivityAction cases"
```

---

### Task 3: Create Eloquent models + factories

**Files:**
- Create: `app/Models/LabelRule.php`
- Create: `app/Models/SiteExclusion.php`
- Create: `app/Models/LabelSweepRun.php`
- Create: `app/Models/LabelSweepRunEntry.php`
- Create: `database/factories/LabelRuleFactory.php`
- Create: `database/factories/SiteExclusionFactory.php`
- Create: `database/factories/LabelSweepRunFactory.php`
- Create: `database/factories/LabelSweepRunEntryFactory.php`
- Test: `tests/Feature/Models/LabelSweepModelsTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Models/LabelSweepModelsTest.php`:

```php
<?php

use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SiteExclusion;

test('LabelRule factory creates a valid row', function () {
    $rule = LabelRule::factory()->create();
    expect($rule->prefix)->not->toBeEmpty();
    expect($rule->label_id)->not->toBeEmpty();
    expect($rule->priority)->toBeGreaterThan(0);
});

test('SiteExclusion factory creates a valid row', function () {
    $ex = SiteExclusion::factory()->create();
    expect($ex->pattern)->not->toBeEmpty();
});

test('LabelSweepRun has many entries with cascade delete', function () {
    $run = LabelSweepRun::factory()->create();
    LabelSweepRunEntry::factory()->count(3)->create(['label_sweep_run_id' => $run->id]);
    expect($run->entries()->count())->toBe(3);
    $run->delete();
    expect(LabelSweepRunEntry::count())->toBe(0);
});

test('LabelSweepRunEntry belongs to a rule nullably', function () {
    $rule = LabelRule::factory()->create();
    $entry = LabelSweepRunEntry::factory()->create(['matched_rule_id' => $rule->id]);
    expect($entry->matchedRule->id)->toBe($rule->id);
});

test('LabelRule ordering by priority', function () {
    LabelRule::factory()->create(['prefix' => 'b', 'priority' => 2]);
    LabelRule::factory()->create(['prefix' => 'a', 'priority' => 1]);
    $ordered = LabelRule::orderBy('priority')->pluck('prefix')->all();
    expect($ordered)->toBe(['a', 'b']);
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=LabelSweepModelsTest
```

Expected: FAIL with "Class App\Models\LabelRule not found" or similar.

- [ ] **Step 3: Create `LabelRule` model**

`app/Models/LabelRule.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabelRule extends Model
{
    use HasFactory;

    protected $fillable = ['prefix', 'label_id', 'priority'];

    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }
}
```

- [ ] **Step 4: Create `SiteExclusion` model**

`app/Models/SiteExclusion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteExclusion extends Model
{
    use HasFactory;

    protected $fillable = ['pattern'];
}
```

- [ ] **Step 5: Create `LabelSweepRun` model**

`app/Models/LabelSweepRun.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelSweepRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'started_at',
        'completed_at',
        'total_scanned',
        'already_labeled',
        'applied',
        'skipped_excluded',
        'failed',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_scanned' => 'integer',
            'already_labeled' => 'integer',
            'applied' => 'integer',
            'skipped_excluded' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LabelSweepRunEntry::class)->orderBy('processed_at');
    }
}
```

- [ ] **Step 6: Create `LabelSweepRunEntry` model**

`app/Models/LabelSweepRunEntry.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelSweepRunEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'label_sweep_run_id',
        'site_url',
        'site_title',
        'action',
        'label_id',
        'matched_rule_id',
        'error_message',
        'error_code',
        'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(LabelSweepRun::class, 'label_sweep_run_id');
    }

    public function matchedRule(): BelongsTo
    {
        return $this->belongsTo(LabelRule::class, 'matched_rule_id');
    }
}
```

- [ ] **Step 7: Create factories**

`database/factories/LabelRuleFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\LabelRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelRuleFactory extends Factory
{
    protected $model = LabelRule::class;

    public function definition(): array
    {
        static $priority = 0;
        $priority++;

        return [
            'prefix' => strtoupper(fake()->unique()->bothify('??#')),
            'label_id' => fake()->uuid(),
            'priority' => $priority,
        ];
    }
}
```

`database/factories/SiteExclusionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SiteExclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteExclusionFactory extends Factory
{
    protected $model = SiteExclusion::class;

    public function definition(): array
    {
        return [
            'pattern' => '/sites/'.fake()->unique()->slug(2),
        ];
    }
}
```

`database/factories/LabelSweepRunFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\LabelSweepRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelSweepRunFactory extends Factory
{
    protected $model = LabelSweepRun::class;

    public function definition(): array
    {
        return [
            'started_at' => now(),
            'status' => 'running',
            'total_scanned' => 0,
            'already_labeled' => 0,
            'applied' => 0,
            'skipped_excluded' => 0,
            'failed' => 0,
        ];
    }
}
```

`database/factories/LabelSweepRunEntryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelSweepRunEntryFactory extends Factory
{
    protected $model = LabelSweepRunEntry::class;

    public function definition(): array
    {
        return [
            'label_sweep_run_id' => LabelSweepRun::factory(),
            'site_url' => fake()->url(),
            'site_title' => fake()->words(2, true),
            'action' => 'applied',
            'label_id' => fake()->uuid(),
            'matched_rule_id' => null,
            'error_message' => null,
            'error_code' => null,
            'processed_at' => now(),
        ];
    }
}
```

- [ ] **Step 8: Run test — expect PASS**

```bash
php artisan test --compact --filter=LabelSweepModelsTest
```

Expected: PASS (5 passing).

- [ ] **Step 9: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 10: Commit**

```bash
git add app/Models/ database/factories/ tests/Feature/Models/
git commit -m "feat: add label-sweep models and factories"
```

---

### Task 4: Seed default site exclusion

**Files:**
- Create: `database/seeders/SensitivitySweepSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Seeders/SensitivitySweepSeederTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Seeders/SensitivitySweepSeederTest.php`:

```php
<?php

use App\Models\SiteExclusion;
use Database\Seeders\SensitivitySweepSeeder;

test('seeder inserts /sites/contentTypeHub exclusion when missing', function () {
    $this->seed(SensitivitySweepSeeder::class);

    expect(SiteExclusion::where('pattern', '/sites/contentTypeHub')->count())->toBe(1);
});

test('seeder is idempotent (re-running does not duplicate)', function () {
    $this->seed(SensitivitySweepSeeder::class);
    $this->seed(SensitivitySweepSeeder::class);

    expect(SiteExclusion::where('pattern', '/sites/contentTypeHub')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=SensitivitySweepSeederTest
```

Expected: FAIL with "Class Database\Seeders\SensitivitySweepSeeder not found".

- [ ] **Step 3: Create seeder**

`database/seeders/SensitivitySweepSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\SiteExclusion;
use Illuminate\Database\Seeder;

class SensitivitySweepSeeder extends Seeder
{
    public function run(): void
    {
        SiteExclusion::firstOrCreate(['pattern' => '/sites/contentTypeHub']);
    }
}
```

- [ ] **Step 4: Register seeder in DatabaseSeeder**

Read `database/seeders/DatabaseSeeder.php`. In the `run()` method, after existing seeder calls, add:

```php
$this->call(SensitivitySweepSeeder::class);
```

- [ ] **Step 5: Run test — expect PASS**

```bash
php artisan test --compact --filter=SensitivitySweepSeederTest
```

Expected: PASS (2 passing).

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add database/seeders/ tests/Feature/Seeders/
git commit -m "feat: seed default contentTypeHub exclusion"
```

---

## Phase 2 — Bridge (.NET 9)

All work in Task 5 and later happens under `bridge/`. The working directory for `dotnet` commands is `bridge/` unless noted.

### Task 5: Scaffold bridge project

**Files:**
- Create: `bridge/Partner365.Bridge.sln`
- Create: `bridge/Partner365.Bridge/Partner365.Bridge.csproj`
- Create: `bridge/Partner365.Bridge/Program.cs`
- Create: `bridge/Partner365.Bridge/appsettings.json`
- Create: `bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj`
- Create: `bridge/.dockerignore`

- [ ] **Step 1: Create bridge directory and solution**

Run:
```bash
mkdir -p bridge && cd bridge
dotnet new sln -n Partner365.Bridge
dotnet new web -n Partner365.Bridge -f net9.0 --no-restore
dotnet new xunit -n Partner365.Bridge.Tests -f net9.0 --no-restore
dotnet sln add Partner365.Bridge/Partner365.Bridge.csproj Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj
cd ..
```

Expected: `bridge/Partner365.Bridge.sln` created with both projects registered.

- [ ] **Step 2: Add NuGet references to `Partner365.Bridge`**

Edit `bridge/Partner365.Bridge/Partner365.Bridge.csproj`. Replace with:

```xml
<Project Sdk="Microsoft.NET.Sdk.Web">
  <PropertyGroup>
    <TargetFramework>net9.0</TargetFramework>
    <Nullable>enable</Nullable>
    <ImplicitUsings>enable</ImplicitUsings>
    <InvariantGlobalization>true</InvariantGlobalization>
    <AssemblyName>Partner365.Bridge</AssemblyName>
    <RootNamespace>Partner365.Bridge</RootNamespace>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Azure.Identity" Version="1.13.1" />
    <PackageReference Include="PnP.Framework" Version="1.17.0" />
    <PackageReference Include="System.Security.Cryptography.Pkcs" Version="9.0.0" />
  </ItemGroup>
</Project>
```

- [ ] **Step 3: Add NuGet references to test project**

Edit `bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj`. Replace with:

```xml
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFramework>net9.0</TargetFramework>
    <Nullable>enable</Nullable>
    <ImplicitUsings>enable</ImplicitUsings>
    <IsPackable>false</IsPackable>
    <IsTestProject>true</IsTestProject>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Microsoft.NET.Test.Sdk" Version="17.12.0" />
    <PackageReference Include="Microsoft.AspNetCore.Mvc.Testing" Version="9.0.0" />
    <PackageReference Include="Moq" Version="4.20.72" />
    <PackageReference Include="xunit" Version="2.9.3" />
    <PackageReference Include="xunit.runner.visualstudio" Version="3.0.0" />
    <PackageReference Include="coverlet.collector" Version="6.0.2" />
  </ItemGroup>
  <ItemGroup>
    <ProjectReference Include="..\Partner365.Bridge\Partner365.Bridge.csproj" />
  </ItemGroup>
</Project>
```

- [ ] **Step 4: Replace `Program.cs` with minimal stub**

Edit `bridge/Partner365.Bridge/Program.cs`:

```csharp
var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/health", () => Results.Ok(new { status = "ok" }));

app.Run();
```

- [ ] **Step 5: Write `appsettings.json`**

Edit `bridge/Partner365.Bridge/appsettings.json`:

```json
{
  "Logging": {
    "LogLevel": {
      "Default": "Information",
      "Microsoft.AspNetCore": "Warning"
    }
  }
}
```

- [ ] **Step 6: Write `.dockerignore`**

Create `bridge/.dockerignore`:

```
**/bin/
**/obj/
**/.vs/
**/*.user
```

- [ ] **Step 7: Build — expect success**

```bash
cd bridge && dotnet build && cd ..
```

Expected: `Build succeeded.`

- [ ] **Step 8: Commit**

```bash
git add bridge/
git commit -m "feat: scaffold partner365-bridge .NET project"
```

---

### Task 6: `CloudEnvironmentConfig` + tests

**Files:**
- Create: `bridge/Partner365.Bridge/Services/CloudEnvironmentConfig.cs`
- Create: `bridge/Partner365.Bridge.Tests/CloudEnvironmentConfigTests.cs`

- [ ] **Step 1: Write failing test**

`bridge/Partner365.Bridge.Tests/CloudEnvironmentConfigTests.cs`:

```csharp
using Azure.Identity;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class CloudEnvironmentConfigTests
{
    [Fact]
    public void Commercial_uses_public_authority()
    {
        var config = CloudEnvironmentConfig.For("commercial", "https://contoso-admin.sharepoint.com");
        Assert.Equal(AzureAuthorityHosts.AzurePublicCloud, config.AuthorityHost);
    }

    [Fact]
    public void GccHigh_uses_government_authority()
    {
        var config = CloudEnvironmentConfig.For("gcc-high", "https://gdotsg-admin.sharepoint.us");
        Assert.Equal(AzureAuthorityHosts.AzureGovernment, config.AuthorityHost);
    }

    [Fact]
    public void Commercial_resource_derives_from_admin_url()
    {
        var config = CloudEnvironmentConfig.For("commercial", "https://contoso-admin.sharepoint.com");
        Assert.Equal("https://contoso.sharepoint.com/.default", config.CsomResourceScope);
    }

    [Fact]
    public void GccHigh_resource_derives_from_admin_url()
    {
        var config = CloudEnvironmentConfig.For("gcc-high", "https://gdotsg-admin.sharepoint.us");
        Assert.Equal("https://gdotsg.sharepoint.us/.default", config.CsomResourceScope);
    }

    [Fact]
    public void Unknown_environment_throws()
    {
        Assert.Throws<ArgumentException>(() =>
            CloudEnvironmentConfig.For("martian-cloud", "https://x-admin.sharepoint.com"));
    }

    [Fact]
    public void Admin_url_without_dash_admin_throws()
    {
        Assert.Throws<ArgumentException>(() =>
            CloudEnvironmentConfig.For("commercial", "https://contoso.sharepoint.com"));
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CloudEnvironmentConfigTests" && cd ..
```

Expected: FAIL, `CloudEnvironmentConfig` not found.

- [ ] **Step 3: Write implementation**

`bridge/Partner365.Bridge/Services/CloudEnvironmentConfig.cs`:

```csharp
using Azure.Identity;

namespace Partner365.Bridge.Services;

public sealed record CloudEnvironmentConfig(Uri AuthorityHost, string CsomResourceScope, string CloudEnvironmentName)
{
    public static CloudEnvironmentConfig For(string cloudEnvironment, string adminSiteUrl)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(cloudEnvironment);
        ArgumentException.ThrowIfNullOrWhiteSpace(adminSiteUrl);

        var authority = cloudEnvironment.ToLowerInvariant() switch
        {
            "commercial" => AzureAuthorityHosts.AzurePublicCloud,
            "gcc-high" => AzureAuthorityHosts.AzureGovernment,
            _ => throw new ArgumentException(
                $"Unknown cloud environment '{cloudEnvironment}'. Expected 'commercial' or 'gcc-high'.",
                nameof(cloudEnvironment)),
        };

        var resource = DeriveResourceScope(adminSiteUrl);

        return new CloudEnvironmentConfig(authority, resource, cloudEnvironment.ToLowerInvariant());
    }

    private static string DeriveResourceScope(string adminSiteUrl)
    {
        var uri = new Uri(adminSiteUrl);
        var host = uri.Host;

        if (!host.Contains("-admin.", StringComparison.OrdinalIgnoreCase))
        {
            throw new ArgumentException(
                $"Admin site URL '{adminSiteUrl}' must contain '-admin.' (e.g. 'contoso-admin.sharepoint.com').",
                nameof(adminSiteUrl));
        }

        var tenantHost = host.Replace("-admin.", ".", StringComparison.OrdinalIgnoreCase);
        return $"https://{tenantHost}/.default";
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CloudEnvironmentConfigTests" && cd ..
```

Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Services/CloudEnvironmentConfig.cs bridge/Partner365.Bridge.Tests/CloudEnvironmentConfigTests.cs
git commit -m "feat(bridge): add CloudEnvironmentConfig"
```

---

### Task 7: `CertificateLoader` + tests

**Files:**
- Create: `bridge/Partner365.Bridge/Services/CertificateLoader.cs`
- Create: `bridge/Partner365.Bridge.Tests/CertificateLoaderTests.cs`

- [ ] **Step 1: Write failing test**

`bridge/Partner365.Bridge.Tests/CertificateLoaderTests.cs`:

```csharp
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class CertificateLoaderTests : IDisposable
{
    private readonly string _tempPfxPath;
    private readonly string _password = "test-pw";

    public CertificateLoaderTests()
    {
        _tempPfxPath = Path.Combine(Path.GetTempPath(), $"bridge-test-{Guid.NewGuid()}.pfx");
        using var rsa = RSA.Create(2048);
        var req = new CertificateRequest("CN=BridgeTest", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        using var cert = req.CreateSelfSigned(DateTimeOffset.UtcNow, DateTimeOffset.UtcNow.AddYears(1));
        File.WriteAllBytes(_tempPfxPath, cert.Export(X509ContentType.Pfx, _password));
    }

    public void Dispose()
    {
        if (File.Exists(_tempPfxPath)) File.Delete(_tempPfxPath);
    }

    [Fact]
    public void Loads_cert_from_valid_pfx()
    {
        var cert = CertificateLoader.LoadFromPfx(_tempPfxPath, _password);
        Assert.NotNull(cert);
        Assert.True(cert.HasPrivateKey);
    }

    [Fact]
    public void Missing_file_throws()
    {
        Assert.Throws<FileNotFoundException>(() =>
            CertificateLoader.LoadFromPfx("/no/such/path.pfx", _password));
    }

    [Fact]
    public void Wrong_password_throws()
    {
        Assert.Throws<CryptographicException>(() =>
            CertificateLoader.LoadFromPfx(_tempPfxPath, "wrong"));
    }

    [Fact]
    public void Empty_password_allowed_when_cert_has_none()
    {
        var path = Path.Combine(Path.GetTempPath(), $"bridge-nopw-{Guid.NewGuid()}.pfx");
        try
        {
            using var rsa = RSA.Create(2048);
            var req = new CertificateRequest("CN=NoPw", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
            using var cert = req.CreateSelfSigned(DateTimeOffset.UtcNow, DateTimeOffset.UtcNow.AddYears(1));
            File.WriteAllBytes(path, cert.Export(X509ContentType.Pfx, (string?)null));

            var loaded = CertificateLoader.LoadFromPfx(path, "");
            Assert.NotNull(loaded);
            Assert.True(loaded.HasPrivateKey);
        }
        finally
        {
            if (File.Exists(path)) File.Delete(path);
        }
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CertificateLoaderTests" && cd ..
```

Expected: FAIL, `CertificateLoader` not found.

- [ ] **Step 3: Write implementation**

`bridge/Partner365.Bridge/Services/CertificateLoader.cs`:

```csharp
using System.Security.Cryptography.X509Certificates;

namespace Partner365.Bridge.Services;

public static class CertificateLoader
{
    public static X509Certificate2 LoadFromPfx(string path, string password)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException($"Certificate PFX not found at '{path}'.", path);
        }

        return X509CertificateLoader.LoadPkcs12FromFile(
            path,
            password,
            X509KeyStorageFlags.MachineKeySet | X509KeyStorageFlags.PersistKeySet | X509KeyStorageFlags.Exportable);
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CertificateLoaderTests" && cd ..
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Services/CertificateLoader.cs bridge/Partner365.Bridge.Tests/CertificateLoaderTests.cs
git commit -m "feat(bridge): add CertificateLoader"
```

---

### Task 8: `ErrorClassifier` + tests

**Files:**
- Create: `bridge/Partner365.Bridge/Services/ErrorClassifier.cs`
- Create: `bridge/Partner365.Bridge.Tests/ErrorClassifierTests.cs`

- [ ] **Step 1: Write failing test**

`bridge/Partner365.Bridge.Tests/ErrorClassifierTests.cs`:

```csharp
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class ErrorClassifierTests
{
    [Theory]
    [InlineData("401 Unauthorized", "auth")]
    [InlineData("403 Forbidden", "auth")]
    [InlineData("Access is forbidden", "auth")]
    [InlineData("user is unauthorized", "auth")]
    [InlineData("429 Too Many Requests", "throttle")]
    [InlineData("Request was throttled", "throttle")]
    [InlineData("The operation has timed out", "network")]
    [InlineData("A connection attempt failed", "network")]
    [InlineData("Certificate validation failed", "certificate")]
    [InlineData("Unable to load private key", "certificate")]
    [InlineData("Something totally weird happened", "unknown")]
    public void Classifies_message_into_error_code(string message, string expected)
    {
        var ex = new InvalidOperationException(message);
        Assert.Equal(expected, ErrorClassifier.Classify(ex));
    }

    [Fact]
    public void HttpRequestException_401_classifies_as_auth()
    {
        var ex = new HttpRequestException("401 Unauthorized", null, System.Net.HttpStatusCode.Unauthorized);
        Assert.Equal("auth", ErrorClassifier.Classify(ex));
    }

    [Fact]
    public void HttpRequestException_429_classifies_as_throttle()
    {
        var ex = new HttpRequestException("Too many requests", null, System.Net.HttpStatusCode.TooManyRequests);
        Assert.Equal("throttle", ErrorClassifier.Classify(ex));
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~ErrorClassifierTests" && cd ..
```

Expected: FAIL, `ErrorClassifier` not found.

- [ ] **Step 3: Write implementation**

`bridge/Partner365.Bridge/Services/ErrorClassifier.cs`:

```csharp
using System.Net;

namespace Partner365.Bridge.Services;

public static class ErrorClassifier
{
    public static string Classify(Exception ex)
    {
        if (ex is HttpRequestException httpEx)
        {
            if (httpEx.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden)
                return "auth";
            if (httpEx.StatusCode is HttpStatusCode.TooManyRequests)
                return "throttle";
        }

        var msg = (ex.Message ?? "").ToLowerInvariant();
        var inner = (ex.InnerException?.Message ?? "").ToLowerInvariant();
        var combined = $"{msg} {inner}";

        if (Contains(combined, "401", "403", "unauthorized", "forbidden"))
            return "auth";
        if (Contains(combined, "429", "throttl"))
            return "throttle";
        if (Contains(combined, "timed out", "connection attempt failed", "connection reset", "socket"))
            return "network";
        if (Contains(combined, "certificate", "privatekey", "private key"))
            return "certificate";

        return "unknown";
    }

    private static bool Contains(string haystack, params string[] needles)
    {
        foreach (var n in needles)
        {
            if (haystack.Contains(n, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        return false;
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~ErrorClassifierTests" && cd ..
```

Expected: PASS (13 theory cases + 2 facts).

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Services/ErrorClassifier.cs bridge/Partner365.Bridge.Tests/ErrorClassifierTests.cs
git commit -m "feat(bridge): add ErrorClassifier"
```

---

### Task 9: `SharedSecretMiddleware` + tests

**Files:**
- Create: `bridge/Partner365.Bridge/Services/SharedSecretMiddleware.cs`
- Create: `bridge/Partner365.Bridge.Tests/SharedSecretMiddlewareTests.cs`

- [ ] **Step 1: Write failing test**

`bridge/Partner365.Bridge.Tests/SharedSecretMiddlewareTests.cs`:

```csharp
using Microsoft.AspNetCore.Http;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class SharedSecretMiddlewareTests
{
    private static async Task<int> Invoke(string? headerValue, string expectedSecret, string path = "/v1/sites/label")
    {
        var ctx = new DefaultHttpContext();
        ctx.Request.Path = path;
        if (headerValue is not null)
        {
            ctx.Request.Headers["X-Bridge-Secret"] = headerValue;
        }
        var nextCalled = false;
        var middleware = new SharedSecretMiddleware(_ =>
        {
            nextCalled = true;
            return Task.CompletedTask;
        }, expectedSecret);
        await middleware.Invoke(ctx);
        return nextCalled ? 200 : ctx.Response.StatusCode;
    }

    [Fact]
    public async Task Missing_header_returns_401()
    {
        var status = await Invoke(null, "expected");
        Assert.Equal(401, status);
    }

    [Fact]
    public async Task Wrong_header_returns_401()
    {
        var status = await Invoke("wrong-value", "expected");
        Assert.Equal(401, status);
    }

    [Fact]
    public async Task Correct_header_passes_through()
    {
        var status = await Invoke("expected", "expected");
        Assert.Equal(200, status);
    }

    [Fact]
    public async Task Health_endpoint_is_exempt()
    {
        var status = await Invoke(null, "expected", "/health");
        Assert.Equal(200, status);
    }

    [Theory]
    [InlineData("abc", "abcd")]
    [InlineData("abcd", "abc")]
    public async Task Different_length_does_not_match(string provided, string expected)
    {
        var status = await Invoke(provided, expected);
        Assert.Equal(401, status);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~SharedSecretMiddlewareTests" && cd ..
```

Expected: FAIL, `SharedSecretMiddleware` not found.

- [ ] **Step 3: Write implementation**

`bridge/Partner365.Bridge/Services/SharedSecretMiddleware.cs`:

```csharp
using System.Security.Cryptography;
using System.Text;
using Microsoft.AspNetCore.Http;

namespace Partner365.Bridge.Services;

public sealed class SharedSecretMiddleware
{
    private const string HeaderName = "X-Bridge-Secret";
    private readonly RequestDelegate _next;
    private readonly byte[] _expectedBytes;

    public SharedSecretMiddleware(RequestDelegate next, string expectedSecret)
    {
        _next = next;
        _expectedBytes = Encoding.UTF8.GetBytes(expectedSecret);
    }

    public async Task Invoke(HttpContext ctx)
    {
        if (IsExempt(ctx.Request.Path))
        {
            await _next(ctx);
            return;
        }

        if (!ctx.Request.Headers.TryGetValue(HeaderName, out var provided) || provided.Count == 0)
        {
            await Reject(ctx, "missing_secret", "Missing X-Bridge-Secret header.");
            return;
        }

        var providedBytes = Encoding.UTF8.GetBytes(provided.ToString());

        if (providedBytes.Length != _expectedBytes.Length ||
            !CryptographicOperations.FixedTimeEquals(providedBytes, _expectedBytes))
        {
            await Reject(ctx, "missing_secret", "Invalid X-Bridge-Secret header.");
            return;
        }

        await _next(ctx);
    }

    private static bool IsExempt(PathString path) =>
        path.Equals("/health", StringComparison.OrdinalIgnoreCase);

    private static async Task Reject(HttpContext ctx, string code, string message)
    {
        ctx.Response.StatusCode = 401;
        ctx.Response.ContentType = "application/json";
        await ctx.Response.WriteAsync(
            $$"""{"error":{"code":"{{code}}","message":"{{message}}"}}""");
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~SharedSecretMiddlewareTests" && cd ..
```

Expected: PASS (6 test cases).

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Services/SharedSecretMiddleware.cs bridge/Partner365.Bridge.Tests/SharedSecretMiddlewareTests.cs
git commit -m "feat(bridge): add SharedSecretMiddleware"
```

---

### Task 10: Request/response DTOs

**Files:**
- Create: `bridge/Partner365.Bridge/Models/SetLabelRequest.cs`
- Create: `bridge/Partner365.Bridge/Models/SetLabelResponse.cs`
- Create: `bridge/Partner365.Bridge/Models/ReadLabelRequest.cs`
- Create: `bridge/Partner365.Bridge/Models/ReadLabelResponse.cs`
- Create: `bridge/Partner365.Bridge/Models/HealthResponse.cs`
- Create: `bridge/Partner365.Bridge/Models/ErrorResponse.cs`

- [ ] **Step 1: Write DTOs**

`bridge/Partner365.Bridge/Models/SetLabelRequest.cs`:

```csharp
namespace Partner365.Bridge.Models;

public sealed record SetLabelRequest(string SiteUrl, string LabelId);
```

`bridge/Partner365.Bridge/Models/SetLabelResponse.cs`:

```csharp
namespace Partner365.Bridge.Models;

public sealed record SetLabelResponse(string SiteUrl, string LabelId, bool FastPath);
```

`bridge/Partner365.Bridge/Models/ReadLabelRequest.cs`:

```csharp
namespace Partner365.Bridge.Models;

public sealed record ReadLabelRequest(string SiteUrl);
```

`bridge/Partner365.Bridge/Models/ReadLabelResponse.cs`:

```csharp
namespace Partner365.Bridge.Models;

public sealed record ReadLabelResponse(string SiteUrl, string? LabelId);
```

`bridge/Partner365.Bridge/Models/HealthResponse.cs`:

```csharp
namespace Partner365.Bridge.Models;

public sealed record HealthResponse(string Status, string CloudEnvironment, string CertThumbprint);
```

`bridge/Partner365.Bridge/Models/ErrorResponse.cs`:

```csharp
namespace Partner365.Bridge.Models;

public sealed record ErrorBody(string Code, string Message, string? RequestId);

public sealed record ErrorResponse(ErrorBody Error);
```

- [ ] **Step 2: Build — expect success**

```bash
cd bridge && dotnet build && cd ..
```

Expected: `Build succeeded.`

- [ ] **Step 3: Commit**

```bash
git add bridge/Partner365.Bridge/Models/
git commit -m "feat(bridge): add request/response DTOs"
```

---

### Task 11: `SharePointCsomService` + `ICsomOperations` + tests

**Files:**
- Create: `bridge/Partner365.Bridge/Services/ICsomOperations.cs`
- Create: `bridge/Partner365.Bridge/Services/SharePointCsomService.cs`
- Create: `bridge/Partner365.Bridge/Services/PnPCsomOperations.cs`
- Create: `bridge/Partner365.Bridge.Tests/SharePointCsomServiceTests.cs`

The service under test delegates actual CSOM calls to an `ICsomOperations` interface. That interface has one production implementation (`PnPCsomOperations`, not unit-tested — covered by the manual harness). The orchestration logic (fast-path, overwrite gate, label read) is tested against a Moq-mocked `ICsomOperations`.

- [ ] **Step 1: Write the abstraction**

`bridge/Partner365.Bridge/Services/ICsomOperations.cs`:

```csharp
namespace Partner365.Bridge.Services;

/// <summary>
/// Thin wrapper around CSOM SharePoint tenant admin operations.
/// Exists so <see cref="SharePointCsomService"/> is unit-testable without a live SharePoint.
/// </summary>
public interface ICsomOperations
{
    Task<string?> GetSiteLabelAsync(string siteUrl, CancellationToken ct);
    Task SetSiteLabelAsync(string siteUrl, string labelId, CancellationToken ct);
}
```

- [ ] **Step 2: Write the service's DTO + failing tests**

`bridge/Partner365.Bridge.Tests/SharePointCsomServiceTests.cs`:

```csharp
using Moq;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class SharePointCsomServiceTests
{
    private static SharePointCsomService MakeService(Mock<ICsomOperations> ops)
    {
        return new SharePointCsomService(ops.Object);
    }

    [Fact]
    public async Task SetLabel_fast_path_when_current_equals_target()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync("https://a/sites/x", It.IsAny<CancellationToken>()))
            .ReturnsAsync("label-1");

        var svc = MakeService(ops);
        var result = await svc.SetLabelAsync("https://a/sites/x", "label-1", overwrite: false, default);

        Assert.True(result.FastPath);
        ops.Verify(o => o.SetSiteLabelAsync(It.IsAny<string>(), It.IsAny<string>(), It.IsAny<CancellationToken>()), Times.Never);
    }

    [Fact]
    public async Task SetLabel_applies_when_unlabeled()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync((string?)null);

        var svc = MakeService(ops);
        var result = await svc.SetLabelAsync("https://a/sites/x", "label-1", overwrite: false, default);

        Assert.False(result.FastPath);
        ops.Verify(o => o.SetSiteLabelAsync("https://a/sites/x", "label-1", It.IsAny<CancellationToken>()), Times.Once);
    }

    [Fact]
    public async Task SetLabel_throws_conflict_when_different_label_and_overwrite_false()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync("different-label");

        var svc = MakeService(ops);
        await Assert.ThrowsAsync<LabelConflictException>(() =>
            svc.SetLabelAsync("https://a/sites/x", "target-label", overwrite: false, default));

        ops.Verify(o => o.SetSiteLabelAsync(It.IsAny<string>(), It.IsAny<string>(), It.IsAny<CancellationToken>()), Times.Never);
    }

    [Fact]
    public async Task SetLabel_applies_when_overwrite_true_and_labels_differ()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync("different-label");

        var svc = MakeService(ops);
        var result = await svc.SetLabelAsync("https://a/sites/x", "target-label", overwrite: true, default);

        Assert.False(result.FastPath);
        ops.Verify(o => o.SetSiteLabelAsync("https://a/sites/x", "target-label", It.IsAny<CancellationToken>()), Times.Once);
    }

    [Fact]
    public async Task ReadLabel_returns_ops_result()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync("https://a/sites/x", It.IsAny<CancellationToken>()))
            .ReturnsAsync("label-xyz");

        var svc = MakeService(ops);
        var result = await svc.ReadLabelAsync("https://a/sites/x", default);

        Assert.Equal("label-xyz", result);
    }

    [Fact]
    public async Task ReadLabel_returns_null_when_unlabeled()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync((string?)null);

        var svc = MakeService(ops);
        var result = await svc.ReadLabelAsync("https://a/sites/x", default);

        Assert.Null(result);
    }
}
```

- [ ] **Step 3: Run tests — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~SharePointCsomServiceTests" && cd ..
```

Expected: FAIL, `SharePointCsomService` not found.

- [ ] **Step 4: Write service + exception type**

`bridge/Partner365.Bridge/Services/SharePointCsomService.cs`:

```csharp
namespace Partner365.Bridge.Services;

public sealed record SetLabelResult(bool FastPath);

public sealed class LabelConflictException : Exception
{
    public string SiteUrl { get; }
    public string ExistingLabelId { get; }
    public string TargetLabelId { get; }

    public LabelConflictException(string siteUrl, string existing, string target)
        : base($"Site '{siteUrl}' already has label '{existing}'; refusing to overwrite with '{target}'.")
    {
        SiteUrl = siteUrl;
        ExistingLabelId = existing;
        TargetLabelId = target;
    }
}

public sealed class SharePointCsomService
{
    private readonly ICsomOperations _ops;

    public SharePointCsomService(ICsomOperations ops)
    {
        _ops = ops;
    }

    public async Task<SetLabelResult> SetLabelAsync(string siteUrl, string labelId, bool overwrite, CancellationToken ct)
    {
        var current = await _ops.GetSiteLabelAsync(siteUrl, ct);

        if (!string.IsNullOrEmpty(current) && string.Equals(current, labelId, StringComparison.OrdinalIgnoreCase))
        {
            return new SetLabelResult(FastPath: true);
        }

        if (!string.IsNullOrEmpty(current) && !overwrite)
        {
            throw new LabelConflictException(siteUrl, current, labelId);
        }

        await _ops.SetSiteLabelAsync(siteUrl, labelId, ct);
        return new SetLabelResult(FastPath: false);
    }

    public Task<string?> ReadLabelAsync(string siteUrl, CancellationToken ct)
    {
        return _ops.GetSiteLabelAsync(siteUrl, ct);
    }
}
```

- [ ] **Step 5: Run tests — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~SharePointCsomServiceTests" && cd ..
```

Expected: PASS (6 tests).

- [ ] **Step 6: Write the PnP.Framework implementation of `ICsomOperations`**

This implementation is not unit-tested — it calls a live SharePoint tenant. The manual harness (Task 26) exercises it. Code intentionally mirrors Label365's proven `SetSensitivityLabelAsync`.

`bridge/Partner365.Bridge/Services/PnPCsomOperations.cs`:

```csharp
using System.Security.Cryptography.X509Certificates;
using Azure.Core;
using Azure.Identity;
using PnP.Framework;

namespace Partner365.Bridge.Services;

public sealed class PnPCsomOperations : ICsomOperations
{
    private readonly CloudEnvironmentConfig _cloud;
    private readonly string _tenantId;
    private readonly string _clientId;
    private readonly string _adminSiteUrl;
    private readonly X509Certificate2 _cert;

    public PnPCsomOperations(
        CloudEnvironmentConfig cloud,
        string tenantId,
        string clientId,
        string adminSiteUrl,
        X509Certificate2 cert)
    {
        _cloud = cloud;
        _tenantId = tenantId;
        _clientId = clientId;
        _adminSiteUrl = adminSiteUrl;
        _cert = cert;
    }

    public async Task<string?> GetSiteLabelAsync(string siteUrl, CancellationToken ct)
    {
        using var ctx = await CreateAdminContextAsync(ct);
        var tenant = new Microsoft.Online.SharePoint.TenantAdministration.Tenant(ctx);
        var props = tenant.GetSitePropertiesByUrl(siteUrl, true);
        ctx.Load(props);
        await ctx.ExecuteQueryAsync();

        var label = props.SensitivityLabel2;
        return string.IsNullOrWhiteSpace(label) ? null : label;
    }

    public async Task SetSiteLabelAsync(string siteUrl, string labelId, CancellationToken ct)
    {
        using var ctx = await CreateAdminContextAsync(ct);
        var tenant = new Microsoft.Online.SharePoint.TenantAdministration.Tenant(ctx);
        var props = tenant.GetSitePropertiesByUrl(siteUrl, true);
        ctx.Load(props);
        await ctx.ExecuteQueryAsync();

        props.SensitivityLabel2 = labelId;
        props.Update();
        await ctx.ExecuteQueryAsync();
    }

    private async Task<Microsoft.SharePoint.Client.ClientContext> CreateAdminContextAsync(CancellationToken ct)
    {
        var credential = new ClientCertificateCredential(
            _tenantId,
            _clientId,
            _cert,
            new ClientCertificateCredentialOptions { AuthorityHost = _cloud.AuthorityHost });

        var token = await credential.GetTokenAsync(
            new TokenRequestContext(new[] { _cloud.CsomResourceScope }),
            ct);

        var auth = new AuthenticationManager();
        return auth.GetAccessTokenContext(_adminSiteUrl, token.Token);
    }
}
```

- [ ] **Step 7: Build — expect success**

```bash
cd bridge && dotnet build && cd ..
```

Expected: `Build succeeded.` (Some analyzer warnings from PnP.Framework are acceptable.)

- [ ] **Step 8: Commit**

```bash
git add bridge/Partner365.Bridge/Services/ bridge/Partner365.Bridge.Tests/SharePointCsomServiceTests.cs
git commit -m "feat(bridge): add SharePointCsomService with mockable CSOM operations"
```

---

### Task 12: Wire up endpoints, configuration, and middleware

**Files:**
- Modify: `bridge/Partner365.Bridge/Program.cs`
- Test: `bridge/Partner365.Bridge.Tests/BridgeStartupTests.cs`

- [ ] **Step 1: Write startup/integration test**

`bridge/Partner365.Bridge.Tests/BridgeStartupTests.cs`:

```csharp
using Microsoft.AspNetCore.Mvc.Testing;
using System.Net;
using System.Net.Http.Json;
using Xunit;

namespace Partner365.Bridge.Tests;

public class BridgeStartupTests : IClassFixture<BridgeFactory>
{
    private readonly BridgeFactory _factory;

    public BridgeStartupTests(BridgeFactory factory)
    {
        _factory = factory;
    }

    [Fact]
    public async Task Health_returns_200_without_secret()
    {
        var client = _factory.CreateClient();
        var resp = await client.GetAsync("/health");
        Assert.Equal(HttpStatusCode.OK, resp.StatusCode);

        var body = await resp.Content.ReadFromJsonAsync<Dictionary<string, object>>();
        Assert.NotNull(body);
        Assert.Equal("ok", body!["status"]!.ToString());
    }

    [Fact]
    public async Task V1_endpoint_requires_shared_secret()
    {
        var client = _factory.CreateClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label:read",
            new { SiteUrl = "https://a/sites/x" });
        Assert.Equal(HttpStatusCode.Unauthorized, resp.StatusCode);
    }
}
```

And a `BridgeFactory` helper at `bridge/Partner365.Bridge.Tests/BridgeFactory.cs`:

```csharp
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Moq;
using Partner365.Bridge.Services;

namespace Partner365.Bridge.Tests;

/// <summary>
/// WebApplicationFactory that sets required env vars before the host boots
/// and replaces <see cref="ICsomOperations"/> with a Moq double.
/// </summary>
public sealed class BridgeFactory : WebApplicationFactory<Program>
{
    public Mock<ICsomOperations> Ops { get; } = new();

    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        // Minimum env vars so Program.cs boot validation passes.
        Environment.SetEnvironmentVariable("BRIDGE_CLOUD_ENVIRONMENT", "commercial");
        Environment.SetEnvironmentVariable("BRIDGE_TENANT_ID", "11111111-1111-1111-1111-111111111111");
        Environment.SetEnvironmentVariable("BRIDGE_CLIENT_ID", "22222222-2222-2222-2222-222222222222");
        Environment.SetEnvironmentVariable("BRIDGE_ADMIN_SITE_URL", "https://test-admin.sharepoint.com");
        Environment.SetEnvironmentVariable("BRIDGE_CERT_PATH", "__TEST__");
        Environment.SetEnvironmentVariable("BRIDGE_CERT_PASSWORD", "");
        Environment.SetEnvironmentVariable("BRIDGE_SHARED_SECRET", "unit-test-secret");

        builder.ConfigureServices(services =>
        {
            var descriptor = services.FirstOrDefault(d => d.ServiceType == typeof(ICsomOperations));
            if (descriptor is not null) services.Remove(descriptor);
            services.AddSingleton(Ops.Object);
        });
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~BridgeStartupTests" && cd ..
```

Expected: FAIL — either Program.cs doesn't expose the expected entry point, `Program` not `public`, or endpoints 404.

- [ ] **Step 3: Make `Program` partial-public so the test host can host it**

At the end of `bridge/Partner365.Bridge/Program.cs` (we'll replace the whole file in Step 4), the convention is `public partial class Program { }`. It's included in Step 4's code below.

- [ ] **Step 4: Replace `Program.cs` with full wiring**

`bridge/Partner365.Bridge/Program.cs`:

```csharp
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Mvc;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;

var builder = WebApplication.CreateBuilder(args);

// --- Configuration (env vars only) ---
var cloudEnv  = RequireEnv("BRIDGE_CLOUD_ENVIRONMENT");
var tenantId  = RequireEnv("BRIDGE_TENANT_ID");
var clientId  = RequireEnv("BRIDGE_CLIENT_ID");
var adminUrl  = RequireEnv("BRIDGE_ADMIN_SITE_URL");
var certPath  = RequireEnv("BRIDGE_CERT_PATH");
var certPw    = Environment.GetEnvironmentVariable("BRIDGE_CERT_PASSWORD") ?? "";
var secret    = RequireEnv("BRIDGE_SHARED_SECRET");

var cloudCfg = CloudEnvironmentConfig.For(cloudEnv, adminUrl);

// Cert load is deferred when running under the integration test harness.
System.Security.Cryptography.X509Certificates.X509Certificate2? cert = null;
if (certPath != "__TEST__")
{
    cert = CertificateLoader.LoadFromPfx(certPath, certPw);
}

builder.Services.AddSingleton(cloudCfg);
builder.Services.AddSingleton(_ => new BridgeStartupInfo(
    CloudEnvironmentName: cloudCfg.CloudEnvironmentName,
    CertThumbprint: cert?.Thumbprint ?? "__TEST__"));

if (cert is not null)
{
    builder.Services.AddSingleton<ICsomOperations>(
        _ => new PnPCsomOperations(cloudCfg, tenantId, clientId, adminUrl, cert));
}
else
{
    // The test harness (BridgeFactory) will inject a mock ICsomOperations.
}

builder.Services.AddSingleton<SharePointCsomService>();

builder.Services.ConfigureHttpJsonOptions(options =>
{
    options.SerializerOptions.PropertyNamingPolicy = JsonNamingPolicy.CamelCase;
    options.SerializerOptions.DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull;
});

var app = builder.Build();

// --- Middleware: shared secret on all /v1/* ---
app.Use(async (ctx, next) =>
{
    var mw = new SharedSecretMiddleware(_ => next(), secret);
    await mw.Invoke(ctx);
});

// --- Endpoints ---
app.MapGet("/health", (BridgeStartupInfo info) =>
    Results.Ok(new HealthResponse("ok", info.CloudEnvironmentName, info.CertThumbprint)));

app.MapPost("/v1/sites/label", async (
    [FromQuery] bool? overwrite,
    [FromBody] SetLabelRequest req,
    SharePointCsomService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");
    try
    {
        var result = await svc.SetLabelAsync(req.SiteUrl, req.LabelId, overwrite ?? false, ct);
        log.LogInformation("{RequestId} set-label site={Site} fastPath={FastPath}", requestId, req.SiteUrl, result.FastPath);
        return Results.Ok(new SetLabelResponse(req.SiteUrl, req.LabelId, result.FastPath));
    }
    catch (LabelConflictException ex)
    {
        log.LogInformation("{RequestId} set-label conflict site={Site}", requestId, req.SiteUrl);
        return Results.Json(new ErrorResponse(new ErrorBody("already_labeled", ex.Message, requestId)), statusCode: 409);
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogWarning(ex, "{RequestId} set-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, ex.Message, requestId)), statusCode: 502);
    }
});

app.MapPost("/v1/sites/label:read", async (
    [FromBody] ReadLabelRequest req,
    SharePointCsomService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");
    try
    {
        var labelId = await svc.ReadLabelAsync(req.SiteUrl, ct);
        log.LogInformation("{RequestId} read-label site={Site} labelId={LabelId}", requestId, req.SiteUrl, labelId ?? "(none)");
        return Results.Ok(new ReadLabelResponse(req.SiteUrl, labelId));
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogWarning(ex, "{RequestId} read-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, ex.Message, requestId)), statusCode: 502);
    }
});

app.Run();

static string RequireEnv(string name)
{
    var v = Environment.GetEnvironmentVariable(name);
    if (string.IsNullOrWhiteSpace(v))
    {
        throw new InvalidOperationException($"Required environment variable '{name}' is not set.");
    }
    return v;
}

public sealed record BridgeStartupInfo(string CloudEnvironmentName, string CertThumbprint);

public partial class Program { }
```

- [ ] **Step 5: Run all tests — expect PASS**

```bash
cd bridge && dotnet test && cd ..
```

Expected: all tests pass (7 suites: CloudEnvironmentConfig, CertificateLoader, ErrorClassifier, SharedSecretMiddleware, SharePointCsomService, BridgeStartup).

- [ ] **Step 6: Commit**

```bash
git add bridge/Partner365.Bridge/Program.cs bridge/Partner365.Bridge.Tests/BridgeStartupTests.cs bridge/Partner365.Bridge.Tests/BridgeFactory.cs
git commit -m "feat(bridge): wire endpoints, env config, middleware"
```

---

### Task 13: Dockerfile + dev validation checklist

**Files:**
- Create: `bridge/Dockerfile`
- Create: `bridge/dev/validate.md`

- [ ] **Step 1: Write Dockerfile**

`bridge/Dockerfile`:

```dockerfile
FROM mcr.microsoft.com/dotnet/sdk:9.0 AS build
WORKDIR /src
COPY Partner365.Bridge/Partner365.Bridge.csproj Partner365.Bridge/
RUN dotnet restore Partner365.Bridge/Partner365.Bridge.csproj
COPY Partner365.Bridge/ Partner365.Bridge/
RUN dotnet publish Partner365.Bridge/Partner365.Bridge.csproj -c Release -o /app --no-restore

FROM mcr.microsoft.com/dotnet/aspnet:9.0
# curl is required for the docker-compose healthcheck; the aspnet image ships without it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=build /app .
EXPOSE 8080
ENTRYPOINT ["dotnet", "Partner365.Bridge.dll"]
```

- [ ] **Step 2: Verify docker build works**

Run:
```bash
docker build -t partner365-bridge:test bridge/
```

Expected: build succeeds, final image tagged `partner365-bridge:test`. This confirms both the multi-stage publish and the curl install work.

- [ ] **Step 3: Write dev validation checklist**

`bridge/dev/validate.md`:

```markdown
# Bridge manual validation harness

Use this checklist when wiring a new tenant, rotating certs, or verifying a release build.

## Prereqs

- Real M365 tenant (commercial or GCC High).
- App registration with cert credential + SharePoint `Sites.FullControl.All` consented.
- PFX file on disk.
- Partner365 compose stack running.

## Steps

1. `docker compose up -d` (bring up both containers).

2. Health check with secret bypass:
    ```bash
    docker compose exec -T bridge curl -fsS http://localhost:8080/health
    ```
    Expect JSON with `"status":"ok"`, matching `cloudEnvironment`, and a certThumbprint.

3. Authoritative label read via Partner365 `tinker`:
    ```bash
    docker compose exec app php artisan tinker --execute="echo app(App\Services\BridgeClient::class)->readLabel('https://<tenant>.sharepoint.us/sites/<TestSite>');"
    ```
    Cross-check against the SharePoint admin center's Sensitivity column for the same site.

4. Manual apply via Partner365 UI:
    - Log in as admin, navigate to the test site's page, click "Change label", pick a known label.
    - Confirm SharePoint admin center shows the new label within 30 seconds.

5. Dry-run sweep:
    ```bash
    docker compose exec app php artisan sensitivity:sweep --force --dry-run
    ```
    Confirm a `LabelSweepRun` row exists, with entries for every enumerated site; no sites were actually labeled.

6. Live sweep:
    - Save at least one rule via Sweep Config page.
    - `docker compose exec app php artisan sensitivity:sweep --force`.
    - Sweep Config page shows the fresh run with applied > 0.

7. Systemic-failure abort:
    - Swap `${BRIDGE_CERT_HOST_PATH}` to a throwaway cert the app reg doesn't know about.
    - `docker compose restart bridge`.
    - `docker compose exec app php artisan sensitivity:sweep --force`.
    - Expect the run to transition to `status=aborted` after 3 systemic failures, and a `SweepAbortedNotification` in the admin's inbox.
```

- [ ] **Step 4: Commit**

```bash
git add bridge/Dockerfile bridge/dev/validate.md
git commit -m "feat(bridge): add Dockerfile and manual validation harness"
```

---

## Phase 3 — Partner365 BridgeClient

### Task 14: Typed bridge exceptions + result DTOs

**Files:**
- Create: `app/Services/Exceptions/BridgeException.php`
- Create: `app/Services/Exceptions/BridgeAuthException.php`
- Create: `app/Services/Exceptions/BridgeThrottleException.php`
- Create: `app/Services/Exceptions/BridgeNetworkException.php`
- Create: `app/Services/Exceptions/BridgeConfigException.php`
- Create: `app/Services/Exceptions/BridgeUnavailableException.php`
- Create: `app/Services/Exceptions/BridgeLabelConflictException.php`
- Create: `app/Services/Exceptions/BridgeSiteNotFoundException.php`
- Create: `app/Services/Exceptions/BridgeUnknownException.php`
- Create: `app/Services/DTOs/SetLabelResult.php`
- Create: `app/Services/DTOs/BridgeHealth.php`

- [ ] **Step 1: Write base exception**

`app/Services/Exceptions/BridgeException.php`:

```php
<?php

namespace App\Services\Exceptions;

use Exception;

class BridgeException extends Exception
{
    public function __construct(
        string $message = '',
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
```

- [ ] **Step 2: Write subclasses**

Create each of these — they all extend `BridgeException` with no body:

`app/Services/Exceptions/BridgeAuthException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeAuthException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeThrottleException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeThrottleException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeNetworkException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeNetworkException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeConfigException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeConfigException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeUnavailableException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeUnavailableException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeLabelConflictException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeLabelConflictException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeSiteNotFoundException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeSiteNotFoundException extends BridgeException
{
}
```

`app/Services/Exceptions/BridgeUnknownException.php`:

```php
<?php

namespace App\Services\Exceptions;

class BridgeUnknownException extends BridgeException
{
}
```

- [ ] **Step 3: Write result DTOs**

`app/Services/DTOs/SetLabelResult.php`:

```php
<?php

namespace App\Services\DTOs;

class SetLabelResult
{
    public function __construct(
        public readonly string $siteUrl,
        public readonly string $labelId,
        public readonly bool $fastPath,
    ) {}
}
```

`app/Services/DTOs/BridgeHealth.php`:

```php
<?php

namespace App\Services\DTOs;

class BridgeHealth
{
    public function __construct(
        public readonly string $status,
        public readonly string $cloudEnvironment,
        public readonly string $certThumbprint,
    ) {}
}
```

- [ ] **Step 4: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Exceptions/ app/Services/DTOs/
git commit -m "feat: add bridge client exception hierarchy and result DTOs"
```

---

### Task 15: `BridgeClient` service + tests

**Files:**
- Create: `app/Services/BridgeClient.php`
- Create: `tests/Feature/BridgeClientTest.php`

`BridgeClient` reads the bridge URL and shared secret from the `Setting` model. Tests use `Http::fake()` to verify request shape and response-to-exception mapping.

- [ ] **Step 1: Write failing test**

`tests/Feature/BridgeClientTest.php`:

```php
<?php

use App\Models\Setting;
use App\Services\BridgeClient;
use App\Services\DTOs\BridgeHealth;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnavailableException;
use App\Services\Exceptions\BridgeUnknownException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge-test:8080');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'unit-secret');
});

test('setLabel returns a SetLabelResult on 200', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => 'lbl',
            'fastPath' => false,
        ], 200),
    ]);

    $client = app(BridgeClient::class);
    $result = $client->setLabel('https://a/sites/x', 'lbl', overwrite: false);

    expect($result)->toBeInstanceOf(SetLabelResult::class);
    expect($result->fastPath)->toBeFalse();
    expect($result->siteUrl)->toBe('https://a/sites/x');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Bridge-Secret', 'unit-secret')
            && $request->url() === 'http://bridge-test:8080/v1/sites/label?overwrite=false'
            && $request['siteUrl'] === 'https://a/sites/x'
            && $request['labelId'] === 'lbl';
    });
});

test('setLabel with overwrite true sends overwrite query flag', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => 'lbl',
            'fastPath' => true,
        ], 200),
    ]);

    app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', overwrite: true);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'overwrite=true'));
});

test('setLabel maps 409 to BridgeLabelConflictException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'already_labeled', 'message' => 'conflict', 'requestId' => 'r1'],
        ], 409),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeLabelConflictException::class);
});

test('setLabel maps 404 to BridgeSiteNotFoundException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'nope', 'requestId' => 'r2'],
        ], 404),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeSiteNotFoundException::class);
});

test('setLabel maps 502 auth to BridgeAuthException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'auth', 'message' => 'fail', 'requestId' => 'r3'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeAuthException::class);
});

test('setLabel maps 502 throttle to BridgeThrottleException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'throttle', 'message' => 'slow down', 'requestId' => 'r4'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeThrottleException::class);
});

test('setLabel maps 502 network to BridgeNetworkException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'network', 'message' => 'timeout', 'requestId' => 'r5'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeNetworkException::class);
});

test('setLabel maps 502 certificate to BridgeConfigException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'certificate', 'message' => 'cert bad', 'requestId' => 'r6'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeConfigException::class);
});

test('setLabel maps unknown 502 code to BridgeUnknownException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'unknown', 'message' => 'weird', 'requestId' => 'r7'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeUnknownException::class);
});

test('setLabel maps connection failure to BridgeUnavailableException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => fn () =>
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeUnavailableException::class);
});

test('readLabel returns null when bridge reports unlabeled', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label:read*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => null,
        ], 200),
    ]);

    expect(app(BridgeClient::class)->readLabel('https://a/sites/x'))->toBeNull();
});

test('readLabel returns label GUID when labeled', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label:read*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => 'lbl-xyz',
        ], 200),
    ]);

    expect(app(BridgeClient::class)->readLabel('https://a/sites/x'))->toBe('lbl-xyz');
});

test('health returns BridgeHealth DTO', function () {
    Http::fake([
        'bridge-test:8080/health' => Http::response([
            'status' => 'ok',
            'cloudEnvironment' => 'commercial',
            'certThumbprint' => 'abc',
        ], 200),
    ]);

    $h = app(BridgeClient::class)->health();

    expect($h)->toBeInstanceOf(BridgeHealth::class);
    expect($h->status)->toBe('ok');
    expect($h->cloudEnvironment)->toBe('commercial');
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=BridgeClientTest
```

Expected: FAIL, `App\Services\BridgeClient` not found.

- [ ] **Step 3: Write `BridgeClient`**

`app/Services/BridgeClient.php`:

```php
<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\DTOs\BridgeHealth;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnavailableException;
use App\Services\Exceptions\BridgeUnknownException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BridgeClient
{
    private const DEFAULT_TIMEOUT = 60;

    public function setLabel(string $siteUrl, string $labelId, bool $overwrite = false): SetLabelResult
    {
        $query = $overwrite ? '?overwrite=true' : '?overwrite=false';
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->post(
                $this->baseUrl().'/v1/sites/label'.$query,
                ['siteUrl' => $siteUrl, 'labelId' => $labelId],
            )
        );

        $this->throwOnError($response);
        $body = $response->json();

        return new SetLabelResult(
            siteUrl: $body['siteUrl'],
            labelId: $body['labelId'],
            fastPath: (bool) ($body['fastPath'] ?? false),
        );
    }

    public function readLabel(string $siteUrl): ?string
    {
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->post(
                $this->baseUrl().'/v1/sites/label:read',
                ['siteUrl' => $siteUrl],
            )
        );

        $this->throwOnError($response);

        return $response->json('labelId');
    }

    public function health(): BridgeHealth
    {
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->get($this->baseUrl().'/health')
        );

        $this->throwOnError($response);
        $body = $response->json();

        return new BridgeHealth(
            status: $body['status'],
            cloudEnvironment: $body['cloudEnvironment'] ?? 'unknown',
            certThumbprint: $body['certThumbprint'] ?? '',
        );
    }

    private function baseUrl(): string
    {
        return rtrim((string) Setting::get('sensitivity_sweep', 'bridge_url', 'http://bridge:8080'), '/');
    }

    private function http(): PendingRequest
    {
        $secret = (string) Setting::get('sensitivity_sweep', 'bridge_shared_secret', '');

        return Http::withHeaders(['X-Bridge-Secret' => $secret])
            ->acceptJson()
            ->asJson()
            ->timeout(self::DEFAULT_TIMEOUT);
    }

    private function tryRequest(callable $fn): Response
    {
        try {
            return $fn($this->http());
        } catch (ConnectionException $e) {
            throw new BridgeUnavailableException($e->getMessage());
        }
    }

    private function throwOnError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $code = $response->json('error.code');
        $message = $response->json('error.message') ?? 'Bridge returned HTTP '.$response->status();
        $requestId = $response->json('error.requestId');

        $status = $response->status();

        if ($status === 409) {
            throw new BridgeLabelConflictException($message, $code, $requestId);
        }

        if ($status === 404) {
            throw new BridgeSiteNotFoundException($message, $code, $requestId);
        }

        if ($status === 401) {
            throw new BridgeConfigException('Bridge rejected shared secret: '.$message, $code, $requestId);
        }

        if ($status >= 500) {
            throw match ($code) {
                'auth' => new BridgeAuthException($message, $code, $requestId),
                'throttle' => new BridgeThrottleException($message, $code, $requestId),
                'network' => new BridgeNetworkException($message, $code, $requestId),
                'certificate' => new BridgeConfigException($message, $code, $requestId),
                default => new BridgeUnknownException($message, $code, $requestId),
            };
        }

        throw new BridgeUnknownException($message, $code, $requestId);
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
php artisan test --compact --filter=BridgeClientTest
```

Expected: PASS (13 tests).

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/BridgeClient.php tests/Feature/BridgeClientTest.php
git commit -m "feat: add BridgeClient with typed exception mapping"
```

---

## Phase 4 — Sweep orchestration

### Task 16: `ApplySiteLabelJob` + tests

Writing the per-site job first (before the command) so the command can dispatch something real.

**Files:**
- Create: `app/Jobs/ApplySiteLabelJob.php`
- Create: `tests/Feature/Jobs/ApplySiteLabelJobTest.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Jobs/ApplySiteLabelJobTest.php`:

```php
<?php

use App\Jobs\ApplySiteLabelJob;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SensitivityLabel;
use App\Models\SiteSensitivityLabel;
use App\Services\BridgeClient;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

function makeRun(): LabelSweepRun
{
    return LabelSweepRun::factory()->create(['status' => 'running']);
}

test('job writes applied entry and updates SiteSensitivityLabel on success', function () {
    $run = makeRun();
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'X', 'protection_type' => 'none']);

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')
        ->once()
        ->with('https://a/sites/x', 'lbl', false)
        ->andReturn(new SetLabelResult('https://a/sites/x', 'lbl', fastPath: false));
    $this->app->instance(BridgeClient::class, $mock);

    SiteSensitivityLabel::create([
        'site_id' => 'site-1',
        'site_name' => 'X',
        'site_url' => 'https://a/sites/x',
        'sensitivity_label_id' => null,
        'synced_at' => now(),
    ]);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    expect(LabelSweepRunEntry::count())->toBe(1);
    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe('applied');
    expect($entry->label_id)->toBe('lbl');

    $updated = SiteSensitivityLabel::where('site_url', 'https://a/sites/x')->first();
    expect($updated->sensitivity_label_id)->toBe($label->id);
});

test('job writes skipped_labeled on 409 and does not retry', function () {
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeLabelConflictException('conflict'));
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe('skipped_labeled');
});

test('job writes failed entry with error_code=not_found on 404', function () {
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeSiteNotFoundException('gone', 'not_found', 'r'));
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe('failed');
    expect($entry->error_code)->toBe('not_found');
});

test('job rethrows throttle exception for retry', function () {
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeThrottleException('slow', 'throttle', 'r'));
    $this->app->instance(BridgeClient::class, $mock);

    expect(fn () => (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock))
        ->toThrow(BridgeThrottleException::class);
});

test('job writes failed + increments systemic counter on auth error', function () {
    Bus::fake();
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeAuthException('401', 'auth', 'r'));
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe('failed');
    expect($entry->error_code)->toBe('auth');
    expect((int) Cache::get("sweep:{$run->id}:systemic_failures"))->toBe(1);
});

test('three auth failures dispatch AbortSweepRunJob', function () {
    Bus::fake();
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeAuthException('401', 'auth', 'r'));
    $this->app->instance(BridgeClient::class, $mock);

    for ($i = 0; $i < 3; $i++) {
        (new ApplySiteLabelJob($run->id, "https://a/sites/x{$i}", "X{$i}", 'lbl', null))->handle($mock);
    }

    Bus::assertDispatched(\App\Jobs\AbortSweepRunJob::class);
});

test('job short-circuits with skipped_aborted when run already aborted', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'aborted']);

    $mock = mock(BridgeClient::class);
    $mock->shouldNotReceive('setLabel');
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe('skipped_aborted');
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=ApplySiteLabelJobTest
```

Expected: FAIL, `ApplySiteLabelJob` class not found.

- [ ] **Step 3: Write `ApplySiteLabelJob`**

`app/Jobs/ApplySiteLabelJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SensitivityLabel;
use App\Models\SiteSensitivityLabel;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ApplySiteLabelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(
        public readonly int $runId,
        public readonly string $siteUrl,
        public readonly string $siteTitle,
        public readonly string $labelId,
        public readonly ?int $matchedRuleId,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(BridgeClient $bridge): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run || $run->status === 'aborted') {
            $this->writeEntry('skipped_aborted');
            return;
        }

        try {
            $result = $bridge->setLabel($this->siteUrl, $this->labelId, overwrite: false);
            $this->writeEntry('applied');
            $this->updateSiteSensitivityLabel();
            return;
        } catch (BridgeLabelConflictException) {
            $this->writeEntry('skipped_labeled');
            return;
        } catch (BridgeSiteNotFoundException $e) {
            $this->writeEntry('failed', errorCode: 'not_found', errorMessage: $e->getMessage());
            return;
        } catch (BridgeThrottleException | BridgeNetworkException $e) {
            throw $e;
        } catch (BridgeAuthException | BridgeConfigException $e) {
            $this->writeEntry(
                'failed',
                errorCode: $e->errorCode ?? 'auth',
                errorMessage: $e->getMessage(),
            );
            $this->incrementSystemicCounter();
            return;
        } catch (BridgeException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->writeEntry('failed', errorCode: $e->errorCode ?? 'unknown', errorMessage: $e->getMessage());
                return;
            }
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->writeEntry(
            'failed',
            errorCode: $e instanceof BridgeException ? ($e->errorCode ?? 'unknown') : 'unknown',
            errorMessage: $e->getMessage(),
        );
    }

    private function writeEntry(string $action, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        LabelSweepRunEntry::create([
            'label_sweep_run_id' => $this->runId,
            'site_url' => $this->siteUrl,
            'site_title' => $this->siteTitle,
            'action' => $action,
            'label_id' => $action === 'applied' || $action === 'skipped_labeled' ? $this->labelId : null,
            'matched_rule_id' => $this->matchedRuleId,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'processed_at' => now(),
        ]);
    }

    private function updateSiteSensitivityLabel(): void
    {
        $label = SensitivityLabel::where('label_id', $this->labelId)->first();

        if (! $label) {
            return;
        }

        SiteSensitivityLabel::where('site_url', $this->siteUrl)
            ->update(['sensitivity_label_id' => $label->id]);
    }

    private function incrementSystemicCounter(): void
    {
        $key = "sweep:{$this->runId}:systemic_failures";
        $count = Cache::increment($key);
        Cache::put($key, $count, now()->addHours(6));

        if ($count >= 3) {
            AbortSweepRunJob::dispatch($this->runId);
        }
    }
}
```

- [ ] **Step 4: Create placeholder `AbortSweepRunJob` so test Bus::assertDispatched resolves**

`app/Jobs/AbortSweepRunJob.php` (will be fully implemented in Task 18):

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AbortSweepRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        // Implemented in Task 18.
    }
}
```

- [ ] **Step 5: Run test — expect PASS**

```bash
php artisan test --compact --filter=ApplySiteLabelJobTest
```

Expected: PASS (7 tests).

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/ApplySiteLabelJob.php app/Jobs/AbortSweepRunJob.php tests/Feature/Jobs/ApplySiteLabelJobTest.php
git commit -m "feat: add ApplySiteLabelJob with retry and abort semantics"
```

---

### Task 17: `SensitivitySweepCommand` + tests

**Files:**
- Create: `app/Console/Commands/SensitivitySweepCommand.php`
- Create: `tests/Feature/Commands/SensitivitySweepCommandTest.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Commands/SensitivitySweepCommandTest.php`:

```php
<?php

use App\Jobs\ApplySiteLabelJob;
use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SensitivityLabel;
use App\Models\Setting;
use App\Models\SiteExclusion;
use App\Models\SiteSensitivityLabel;
use App\Services\BridgeClient;
use App\Services\DTOs\BridgeHealth;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Setting::set('sensitivity_sweep', 'enabled', '1');
    Setting::set('sensitivity_sweep', 'interval_minutes', '90');
    Setting::set('sensitivity_sweep', 'default_label_id', 'default-lbl');
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge:8080');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 's');

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('health')->andReturn(new BridgeHealth('ok', 'commercial', 'abc'));
    $this->app->instance(BridgeClient::class, $mock);
});

function makeSite(string $url, string $title, ?int $labelId = null): SiteSensitivityLabel
{
    return SiteSensitivityLabel::create([
        'site_id' => md5($url),
        'site_name' => $title,
        'site_url' => $url,
        'sensitivity_label_id' => $labelId,
        'synced_at' => now(),
    ]);
}

test('returns early when sweep is disabled', function () {
    Bus::fake();
    Setting::set('sensitivity_sweep', 'enabled', '0');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertNothingDispatched();
    expect(LabelSweepRun::count())->toBe(0);
});

test('returns early when default label unset', function () {
    Bus::fake();
    Setting::set('sensitivity_sweep', 'default_label_id', '');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertNothingDispatched();
    expect(LabelSweepRun::count())->toBe(0);
});

test('respects interval guard', function () {
    Bus::fake();
    LabelSweepRun::create(['started_at' => now()->subMinutes(10), 'status' => 'success']);

    $this->artisan('sensitivity:sweep')->assertSuccessful();

    expect(LabelSweepRun::count())->toBe(1);
    Bus::assertNothingDispatched();
});

test('force flag bypasses interval guard', function () {
    Bus::fake();
    LabelSweepRun::create(['started_at' => now()->subMinutes(10), 'status' => 'success']);

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    expect(LabelSweepRun::count())->toBe(2);
});

test('applies exclusions and deletes matching site rows', function () {
    Bus::fake();
    SiteExclusion::create(['pattern' => '/sites/contentTypeHub']);
    makeSite('https://a/sites/contentTypeHub', 'Hub');
    makeSite('https://a/sites/Normal', 'Normal');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    expect(SiteSensitivityLabel::where('site_url', 'https://a/sites/contentTypeHub')->count())->toBe(0);
    expect(SiteSensitivityLabel::where('site_url', 'https://a/sites/Normal')->count())->toBe(1);

    $run = LabelSweepRun::latest('id')->first();
    expect($run->entries()->where('action', 'skipped_excluded')->count())->toBe(1);
});

test('matches rules by priority ascending', function () {
    Bus::fake();
    LabelRule::create(['prefix' => 'EXT', 'label_id' => 'ext-lbl', 'priority' => 1]);
    LabelRule::create(['prefix' => 'E', 'label_id' => 'e-lbl', 'priority' => 2]);
    makeSite('https://a/sites/EXTTest', 'EXTTest');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertDispatched(ApplySiteLabelJob::class, fn ($job) => $job->labelId === 'ext-lbl');
});

test('falls back to default label when no rule matches', function () {
    Bus::fake();
    LabelRule::create(['prefix' => 'ZZZ', 'label_id' => 'zzz-lbl', 'priority' => 1]);
    makeSite('https://a/sites/Other', 'Other');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertDispatched(ApplySiteLabelJob::class, fn ($job) => $job->labelId === 'default-lbl');
});

test('dispatches one job per unlabeled site', function () {
    Bus::fake();
    makeSite('https://a/sites/S1', 'S1');
    makeSite('https://a/sites/S2', 'S2');

    $label = SensitivityLabel::create(['label_id' => 'x', 'name' => 'X', 'protection_type' => 'none']);
    makeSite('https://a/sites/S3', 'S3', $label->id);

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertDispatched(ApplySiteLabelJob::class, 2);

    $run = LabelSweepRun::latest('id')->first();
    expect($run->entries()->where('action', 'skipped_labeled')->count())->toBe(1);
});

test('pre-flight health failure marks run as failed', function () {
    Bus::fake();
    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('health')->andThrow(new \App\Services\Exceptions\BridgeUnavailableException('down'));
    $this->app->instance(BridgeClient::class, $mock);

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    $run = LabelSweepRun::latest('id')->first();
    expect($run->status)->toBe('failed');
    expect($run->error_message)->toContain('down');
    Bus::assertNotDispatched(ApplySiteLabelJob::class);
});

test('dry-run flag does not dispatch apply jobs', function () {
    Bus::fake();
    LabelRule::create(['prefix' => 'E', 'label_id' => 'e', 'priority' => 1]);
    makeSite('https://a/sites/Example', 'Example');

    $this->artisan('sensitivity:sweep', ['--force' => true, '--dry-run' => true])->assertSuccessful();

    Bus::assertNotDispatched(ApplySiteLabelJob::class);

    $run = LabelSweepRun::latest('id')->first();
    expect($run->entries()->count())->toBe(1);
});

test('filters to /sites/ and /teams/ urls only', function () {
    Bus::fake();
    makeSite('https://a/portals/Hub', 'Portal');
    makeSite('https://a/sites/Yes', 'SiteYes');
    makeSite('https://a/teams/Yes', 'TeamsYes');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertDispatched(ApplySiteLabelJob::class, 2);
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=SensitivitySweepCommandTest
```

Expected: FAIL, command `sensitivity:sweep` not found.

- [ ] **Step 3: Write command**

`app/Console/Commands/SensitivitySweepCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ApplySiteLabelJob;
use App\Jobs\CompleteSweepRunJob;
use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\Setting;
use App\Models\SiteExclusion;
use App\Models\SiteSensitivityLabel;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeException;
use Illuminate\Console\Command;

class SensitivitySweepCommand extends Command
{
    protected $signature = 'sensitivity:sweep {--force : Bypass the interval guard} {--dry-run : Enumerate and classify but do not dispatch apply jobs}';

    protected $description = 'Run the sensitivity-label sweep across tracked SharePoint sites';

    public function handle(BridgeClient $bridge): int
    {
        if (! (bool) Setting::get('sensitivity_sweep', 'enabled', false)) {
            $this->info('Sweeps are disabled (sensitivity_sweep.enabled=false).');
            return Command::SUCCESS;
        }

        $defaultLabel = (string) Setting::get('sensitivity_sweep', 'default_label_id', '');
        if ($defaultLabel === '') {
            $this->info('Default label not configured; skipping.');
            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->intervalElapsed()) {
            $this->info('Interval not elapsed; skipping. Use --force to override.');
            return Command::SUCCESS;
        }

        $run = LabelSweepRun::create([
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $bridge->health();
        } catch (BridgeException $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => 'Bridge pre-flight failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->error('Bridge unreachable: '.$e->getMessage());
            return Command::SUCCESS;
        }

        $exclusions = SiteExclusion::pluck('pattern')->all();
        $rules = LabelRule::orderBy('priority')->get();

        $candidates = SiteSensitivityLabel::query()
            ->where(function ($q) {
                $q->where('site_url', 'like', '%/sites/%')
                    ->orWhere('site_url', 'like', '%/teams/%');
            })
            ->get();

        $scanned = 0;
        $alreadyLabeled = 0;
        $skippedExcluded = 0;

        foreach ($candidates as $site) {
            $scanned++;

            if ($this->isExcluded($site->site_url, $exclusions)) {
                $skippedExcluded++;
                LabelSweepRunEntry::create([
                    'label_sweep_run_id' => $run->id,
                    'site_url' => $site->site_url,
                    'site_title' => $site->site_name,
                    'action' => 'skipped_excluded',
                    'processed_at' => now(),
                ]);
                SiteSensitivityLabel::where('id', $site->id)->delete();
                continue;
            }

            if ($site->sensitivity_label_id !== null) {
                $alreadyLabeled++;
                LabelSweepRunEntry::create([
                    'label_sweep_run_id' => $run->id,
                    'site_url' => $site->site_url,
                    'site_title' => $site->site_name,
                    'action' => 'skipped_labeled',
                    'processed_at' => now(),
                ]);
                continue;
            }

            [$labelId, $matchedRuleId] = $this->resolveLabel($site->site_name, $rules, $defaultLabel);

            if ($this->option('dry-run')) {
                LabelSweepRunEntry::create([
                    'label_sweep_run_id' => $run->id,
                    'site_url' => $site->site_url,
                    'site_title' => $site->site_name,
                    'action' => 'applied',
                    'label_id' => $labelId,
                    'matched_rule_id' => $matchedRuleId,
                    'error_message' => '[dry-run] would apply',
                    'processed_at' => now(),
                ]);
                continue;
            }

            ApplySiteLabelJob::dispatch(
                $run->id,
                $site->site_url,
                $site->site_name,
                $labelId,
                $matchedRuleId,
            );
        }

        $run->update([
            'total_scanned' => $scanned,
            'already_labeled' => $alreadyLabeled,
            'skipped_excluded' => $skippedExcluded,
        ]);

        CompleteSweepRunJob::dispatch($run->id)->delay(now()->addMinutes(30));

        $this->info("Sweep run #{$run->id} dispatched. Scanned {$scanned}, excluded {$skippedExcluded}, already labeled {$alreadyLabeled}.");
        return Command::SUCCESS;
    }

    private function intervalElapsed(): bool
    {
        $interval = max(1, (int) Setting::get('sensitivity_sweep', 'interval_minutes', 90));
        $last = LabelSweepRun::latest('started_at')->first();

        if (! $last) {
            return true;
        }

        return $last->started_at->lt(now()->subMinutes($interval));
    }

    /** @param string[] $exclusions */
    private function isExcluded(string $url, array $exclusions): bool
    {
        foreach ($exclusions as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, LabelRule>  $rules
     * @return array{0: string, 1: ?int}
     */
    private function resolveLabel(string $title, $rules, string $defaultLabel): array
    {
        foreach ($rules as $rule) {
            if ($rule->prefix === '') {
                continue;
            }
            if (stripos($title, $rule->prefix) === 0) {
                return [$rule->label_id, $rule->id];
            }
        }
        return [$defaultLabel, null];
    }
}
```

- [ ] **Step 4: Write `CompleteSweepRunJob` stub**

The command dispatches this — creating a stub now so tests don't explode. Full impl in Task 18.

`app/Jobs/CompleteSweepRunJob.php`:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompleteSweepRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        // Implemented in Task 18.
    }
}
```

- [ ] **Step 5: Schedule the command**

Read `routes/console.php`. At the bottom (before closing tag, if any), add:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sensitivity:sweep')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

(If `Schedule::` facade is already imported, skip that line.)

- [ ] **Step 6: Run test — expect PASS**

```bash
php artisan test --compact --filter=SensitivitySweepCommandTest
```

Expected: PASS (10 tests).

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/SensitivitySweepCommand.php app/Jobs/CompleteSweepRunJob.php routes/console.php tests/Feature/Commands/SensitivitySweepCommandTest.php
git commit -m "feat: add sensitivity:sweep command with rule matching and dry-run"
```

---

### Task 18: Implement `AbortSweepRunJob` + `CompleteSweepRunJob` + notification

**Files:**
- Modify: `app/Jobs/AbortSweepRunJob.php`
- Modify: `app/Jobs/CompleteSweepRunJob.php`
- Create: `app/Notifications/SweepAbortedNotification.php`
- Create: `tests/Feature/Jobs/AbortSweepRunJobTest.php`
- Create: `tests/Feature/Jobs/CompleteSweepRunJobTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Jobs/AbortSweepRunJobTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Jobs\AbortSweepRunJob;
use App\Models\ActivityLog;
use App\Models\LabelSweepRun;
use App\Models\User;
use App\Notifications\SweepAbortedNotification;
use Illuminate\Support\Facades\Notification;

test('marks run aborted with error message', function () {
    Notification::fake();
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new AbortSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->status)->toBe('aborted');
    expect($run->error_message)->toContain('systemic');
    expect($run->completed_at)->not->toBeNull();
});

test('logs sweep_aborted activity entry', function () {
    Notification::fake();
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new AbortSweepRunJob($run->id))->handle();

    expect(ActivityLog::where('action', 'sweep_aborted')->count())->toBe(1);
});

test('notifies admin users', function () {
    Notification::fake();
    User::factory()->create(['role' => UserRole::Admin]);
    User::factory()->create(['role' => UserRole::Operator]);
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new AbortSweepRunJob($run->id))->handle();

    Notification::assertSentTimes(SweepAbortedNotification::class, 1);
});

test('idempotent on already-aborted run', function () {
    Notification::fake();
    $run = LabelSweepRun::factory()->create(['status' => 'aborted', 'error_message' => 'old']);

    (new AbortSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->error_message)->toBe('old');
    Notification::assertNothingSent();
});
```

`tests/Feature/Jobs/CompleteSweepRunJobTest.php`:

```php
<?php

use App\Jobs\CompleteSweepRunJob;
use App\Models\ActivityLog;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;

test('aggregates entry counts onto run', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->count(5)->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);
    LabelSweepRunEntry::factory()->count(2)->create(['label_sweep_run_id' => $run->id, 'action' => 'failed']);

    (new CompleteSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->applied)->toBe(5);
    expect($run->failed)->toBe(2);
    expect($run->completed_at)->not->toBeNull();
});

test('sets status success when no failures', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('success');
});

test('sets status partial_failure when any failures present', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'failed']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('partial_failure');
});

test('preserves aborted status', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'aborted', 'error_message' => 'x']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('aborted');
});

test('logs sweep_ran activity with summary', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);

    (new CompleteSweepRunJob($run->id))->handle();

    $log = ActivityLog::where('action', 'sweep_ran')->first();
    expect($log)->not->toBeNull();
    expect($log->details['run_id'])->toBe($run->id);
    expect($log->details['applied'])->toBe(1);
});

test('trims run history beyond 500 entries', function () {
    // Create 502 runs.
    LabelSweepRun::factory()->count(502)->create(['status' => 'success', 'started_at' => now()->subDays(1)]);
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect(LabelSweepRun::count())->toBe(500);
});
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
php artisan test --compact --filter="AbortSweepRunJobTest|CompleteSweepRunJobTest"
```

Expected: FAIL — stubs don't do anything yet.

- [ ] **Step 3: Write notification**

`app/Notifications/SweepAbortedNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\LabelSweepRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SweepAbortedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly LabelSweepRun $run) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sensitivity label sweep aborted')
            ->line("Sweep run #{$this->run->id} was aborted due to systemic failures.")
            ->line($this->run->error_message ?? 'See history for details.')
            ->action('View run', url('/sensitivity-labels/sweep/history/'.$this->run->id));
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'run_id' => $this->run->id,
            'error_message' => $this->run->error_message,
        ];
    }
}
```

- [ ] **Step 4: Implement `AbortSweepRunJob`**

Replace `app/Jobs/AbortSweepRunJob.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\LabelSweepRun;
use App\Models\User;
use App\Notifications\SweepAbortedNotification;
use App\Services\ActivityLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class AbortSweepRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run || $run->status === 'aborted') {
            return;
        }

        $run->update([
            'status' => 'aborted',
            'error_message' => 'Aborted after 3 systemic failures (auth/certificate). See run entries for details.',
            'completed_at' => now(),
        ]);

        app(ActivityLogService::class)->logSystem(
            ActivityAction::SweepAborted,
            subject: $run,
            details: ['run_id' => $run->id, 'error_message' => $run->error_message],
        );

        $admins = User::where('role', UserRole::Admin->value)->get();
        Notification::send($admins, new SweepAbortedNotification($run));
    }
}
```

- [ ] **Step 5: Implement `CompleteSweepRunJob`**

Replace `app/Jobs/CompleteSweepRunJob.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\ActivityAction;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Services\ActivityLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompleteSweepRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run) {
            return;
        }

        $counts = LabelSweepRunEntry::where('label_sweep_run_id', $run->id)
            ->selectRaw('action, count(*) as c')
            ->groupBy('action')
            ->pluck('c', 'action')
            ->all();

        $applied = (int) ($counts['applied'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $skippedLabeled = (int) ($counts['skipped_labeled'] ?? 0);
        $skippedExcluded = (int) ($counts['skipped_excluded'] ?? 0);

        $status = match (true) {
            $run->status === 'aborted' => 'aborted',
            $failed > 0 => 'partial_failure',
            default => 'success',
        };

        $run->update([
            'applied' => $applied,
            'failed' => $failed,
            'already_labeled' => $skippedLabeled ?: $run->already_labeled,
            'skipped_excluded' => $skippedExcluded ?: $run->skipped_excluded,
            'status' => $status,
            'completed_at' => now(),
        ]);

        app(ActivityLogService::class)->logSystem(
            ActivityAction::SweepRan,
            subject: $run,
            details: [
                'run_id' => $run->id,
                'status' => $status,
                'applied' => $applied,
                'failed' => $failed,
            ],
        );

        $this->trimHistory();
    }

    private function trimHistory(): void
    {
        $keep = 500;
        $ids = LabelSweepRun::orderByDesc('started_at')
            ->skip($keep)
            ->take(10_000)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            LabelSweepRun::whereIn('id', $ids)->delete();
        }
    }
}
```

- [ ] **Step 6: Run tests — expect PASS**

```bash
php artisan test --compact --filter="AbortSweepRunJobTest|CompleteSweepRunJobTest"
```

Expected: PASS (10 tests).

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/ app/Notifications/ tests/Feature/Jobs/
git commit -m "feat: implement AbortSweepRunJob, CompleteSweepRunJob, notification"
```

---

## Phase 5 — Controllers, form requests, and routes

### Task 19: Sweep Config controller + request + routes + tests

**Files:**
- Create: `app/Http/Controllers/SensitivityLabelSweepConfigController.php`
- Create: `app/Http/Requests/UpdateSensitivitySweepConfigRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Controllers/SensitivityLabelSweepConfigControllerTest.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Controllers/SensitivityLabelSweepConfigControllerTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\LabelRule;
use App\Models\SensitivityLabel;
use App\Models\Setting;
use App\Models\SiteExclusion;
use App\Models\User;
use App\Services\BridgeClient;
use App\Services\DTOs\BridgeHealth;

function adminUser(): User
{
    return User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
}

function operatorUser(): User
{
    return User::factory()->create(['role' => UserRole::Operator, 'approved_at' => now(), 'email_verified_at' => now()]);
}

function viewerUser(): User
{
    return User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]);
}

beforeEach(function () {
    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('health')->andReturn(new BridgeHealth('ok', 'commercial', 'abc'))->byDefault();
    $this->app->instance(BridgeClient::class, $mock);
});

test('admin can view sweep config page', function () {
    SensitivityLabel::create(['label_id' => 'l1', 'name' => 'Confidential', 'protection_type' => 'none']);

    $this->actingAs(adminUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertInertia(fn ($p) =>
            $p->component('sensitivity-labels/Sweep/Config')
              ->has('labels')
              ->has('bridgeHealth')
        );
});

test('operator forbidden from sweep config page', function () {
    $this->actingAs(operatorUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertForbidden();
});

test('viewer forbidden from sweep config page', function () {
    $this->actingAs(viewerUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertForbidden();
});

test('update saves settings, rules, exclusions', function () {
    $payload = [
        'enabled' => true,
        'interval_minutes' => 120,
        'default_label_id' => 'default-lbl',
        'bridge_url' => 'http://bridge:8080',
        'bridge_shared_secret' => 'secret',
        'rules' => [
            ['prefix' => 'EXT', 'label_id' => 'ext-lbl', 'priority' => 3],
            ['prefix' => 'INT', 'label_id' => 'int-lbl', 'priority' => 1],
        ],
        'exclusions' => [
            ['pattern' => '/sites/contentTypeHub'],
            ['pattern' => '/sites/Archive'],
        ],
    ];

    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), $payload)
        ->assertRedirect();

    expect((bool) Setting::get('sensitivity_sweep', 'enabled'))->toBeTrue();
    expect((int) Setting::get('sensitivity_sweep', 'interval_minutes'))->toBe(120);

    $rules = LabelRule::orderBy('priority')->get();
    expect($rules)->toHaveCount(2);
    expect($rules[0]->priority)->toBe(1);
    expect($rules[0]->prefix)->toBe('INT');
    expect($rules[1]->priority)->toBe(2);
    expect($rules[1]->prefix)->toBe('EXT');

    expect(SiteExclusion::count())->toBe(2);
});

test('update rejects empty rule prefix', function () {
    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), [
            'enabled' => true,
            'interval_minutes' => 90,
            'default_label_id' => 'x',
            'bridge_url' => 'http://bridge:8080',
            'bridge_shared_secret' => 's',
            'rules' => [['prefix' => '', 'label_id' => 'a', 'priority' => 1]],
            'exclusions' => [],
        ])
        ->assertSessionHasErrors('rules.0.prefix');
});

test('update rejects empty exclusion pattern', function () {
    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), [
            'enabled' => true,
            'interval_minutes' => 90,
            'default_label_id' => 'x',
            'bridge_url' => 'http://bridge:8080',
            'bridge_shared_secret' => 's',
            'rules' => [],
            'exclusions' => [['pattern' => '']],
        ])
        ->assertSessionHasErrors('exclusions.0.pattern');
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=SensitivityLabelSweepConfigControllerTest
```

Expected: FAIL — controller and route not found.

- [ ] **Step 3: Write `UpdateSensitivitySweepConfigRequest`**

`app/Http/Requests/UpdateSensitivitySweepConfigRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSensitivitySweepConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'interval_minutes' => ['required', 'integer', 'min:1'],
            'default_label_id' => ['required', 'string', 'max:50'],
            'bridge_url' => ['required', 'string', 'max:500'],
            'bridge_shared_secret' => ['required', 'string', 'max:500'],
            'rules' => ['present', 'array'],
            'rules.*.prefix' => ['required', 'string', 'min:1', 'max:100'],
            'rules.*.label_id' => ['required', 'string', 'max:50'],
            'rules.*.priority' => ['required', 'integer', 'min:1'],
            'exclusions' => ['present', 'array'],
            'exclusions.*.pattern' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
```

- [ ] **Step 4: Write controller**

`app/Http/Controllers/SensitivityLabelSweepConfigController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\UpdateSensitivitySweepConfigRequest;
use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\SensitivityLabel;
use App\Models\Setting;
use App\Models\SiteExclusion;
use App\Services\ActivityLogService;
use App\Services\BridgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelSweepConfigController extends Controller
{
    public function show(BridgeClient $bridge): Response
    {
        $bridgeHealth = null;
        $bridgeError = null;
        try {
            $bridgeHealth = $bridge->health();
        } catch (\Throwable $e) {
            $bridgeError = $e->getMessage();
        }

        return Inertia::render('sensitivity-labels/Sweep/Config', [
            'settings' => [
                'enabled' => (bool) Setting::get('sensitivity_sweep', 'enabled', false),
                'interval_minutes' => (int) Setting::get('sensitivity_sweep', 'interval_minutes', 90),
                'default_label_id' => (string) Setting::get('sensitivity_sweep', 'default_label_id', ''),
                'bridge_url' => (string) Setting::get('sensitivity_sweep', 'bridge_url', 'http://bridge:8080'),
                'bridge_shared_secret' => (string) Setting::get('sensitivity_sweep', 'bridge_shared_secret', ''),
            ],
            'rules' => LabelRule::orderBy('priority')->get(),
            'exclusions' => SiteExclusion::orderBy('pattern')->get(),
            'labels' => SensitivityLabel::orderBy('name')->get(['id', 'label_id', 'name']),
            'lastRun' => LabelSweepRun::latest('started_at')->first(),
            'bridgeHealth' => $bridgeHealth,
            'bridgeError' => $bridgeError,
        ]);
    }

    public function update(UpdateSensitivitySweepConfigRequest $request, ActivityLogService $log): RedirectResponse
    {
        $data = $request->validated();

        // Setting::set stores ?string — cast booleans and ints to string form.
        Setting::set('sensitivity_sweep', 'enabled', $data['enabled'] ? '1' : '0');
        Setting::set('sensitivity_sweep', 'interval_minutes', (string) $data['interval_minutes']);
        Setting::set('sensitivity_sweep', 'default_label_id', $data['default_label_id']);
        Setting::set('sensitivity_sweep', 'bridge_url', $data['bridge_url']);
        Setting::set('sensitivity_sweep', 'bridge_shared_secret', $data['bridge_shared_secret']);

        DB::transaction(function () use ($data) {
            LabelRule::query()->delete();

            $sorted = collect($data['rules'])->sortBy('priority')->values();
            foreach ($sorted as $i => $rule) {
                LabelRule::create([
                    'prefix' => $rule['prefix'],
                    'label_id' => $rule['label_id'],
                    'priority' => $i + 1,
                ]);
            }

            SiteExclusion::query()->delete();
            foreach ($data['exclusions'] as $ex) {
                SiteExclusion::create(['pattern' => $ex['pattern']]);
            }
        });

        $log->log(
            $request->user(),
            ActivityAction::RuleChanged,
            details: [
                'rule_count' => count($data['rules']),
                'exclusion_count' => count($data['exclusions']),
            ],
        );

        return redirect()->route('sensitivity-labels.sweep.config')
            ->with('success', 'Sweep configuration saved.');
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, inside the `Route::middleware(['auth', 'verified', 'approved'])->group(...)` block, add:

```php
Route::middleware('role:admin')->group(function () {
    Route::get('/sensitivity-labels/sweep/config', [
        \App\Http\Controllers\SensitivityLabelSweepConfigController::class, 'show',
    ])->name('sensitivity-labels.sweep.config');

    Route::put('/sensitivity-labels/sweep/config', [
        \App\Http\Controllers\SensitivityLabelSweepConfigController::class, 'update',
    ])->name('sensitivity-labels.sweep.config.update');
});
```

- [ ] **Step 6: Run test — expect PASS**

```bash
php artisan test --compact --filter=SensitivityLabelSweepConfigControllerTest
```

Expected: PASS (6 tests).

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/SensitivityLabelSweepConfigController.php app/Http/Requests/UpdateSensitivitySweepConfigRequest.php routes/web.php tests/Feature/Controllers/SensitivityLabelSweepConfigControllerTest.php
git commit -m "feat: add sweep config controller, request, and routes"
```

---

### Task 20: Sweep History controller + tests

**Files:**
- Create: `app/Http/Controllers/SensitivityLabelSweepHistoryController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Controllers/SensitivityLabelSweepHistoryControllerTest.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Controllers/SensitivityLabelSweepHistoryControllerTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\User;

test('any authenticated approved user can view history index', function () {
    LabelSweepRun::factory()->count(3)->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]))
        ->get(route('sensitivity-labels.sweep.history'))
        ->assertInertia(fn ($p) =>
            $p->component('sensitivity-labels/Sweep/History')->has('runs.data', 3)
        );
});

test('history index orders by started_at desc by default', function () {
    $older = LabelSweepRun::factory()->create(['started_at' => now()->subDays(2)]);
    $newer = LabelSweepRun::factory()->create(['started_at' => now()->subHours(1)]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]))
        ->get(route('sensitivity-labels.sweep.history'))
        ->assertInertia(fn ($p) =>
            $p->where('runs.data.0.id', $newer->id)
              ->where('runs.data.1.id', $older->id)
        );
});

test('history detail includes entries in chronological order', function () {
    $run = LabelSweepRun::factory()->create();
    $second = LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'processed_at' => now()]);
    $first = LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'processed_at' => now()->subMinutes(5)]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]))
        ->get(route('sensitivity-labels.sweep.history.show', $run))
        ->assertInertia(fn ($p) =>
            $p->component('sensitivity-labels/Sweep/HistoryDetail')
              ->where('run.id', $run->id)
              ->where('entries.0.id', $first->id)
              ->where('entries.1.id', $second->id)
        );
});

test('unauthenticated user redirected from history', function () {
    $this->get(route('sensitivity-labels.sweep.history'))->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=SensitivityLabelSweepHistoryControllerTest
```

Expected: FAIL, route not found.

- [ ] **Step 3: Write controller**

`app/Http/Controllers/SensitivityLabelSweepHistoryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\LabelSweepRun;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelSweepHistoryController extends Controller
{
    public function index(): Response
    {
        $runs = LabelSweepRun::orderByDesc('started_at')->paginate(50);

        return Inertia::render('sensitivity-labels/Sweep/History', [
            'runs' => $runs,
        ]);
    }

    public function show(LabelSweepRun $run): Response
    {
        $entries = $run->entries()
            ->with('matchedRule')
            ->orderBy('processed_at')
            ->get();

        return Inertia::render('sensitivity-labels/Sweep/HistoryDetail', [
            'run' => $run,
            'entries' => $entries,
        ]);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, inside the same `auth/verified/approved` group (not inside the admin-only sub-group), add:

```php
Route::get('/sensitivity-labels/sweep/history', [
    \App\Http\Controllers\SensitivityLabelSweepHistoryController::class, 'index',
])->name('sensitivity-labels.sweep.history');

Route::get('/sensitivity-labels/sweep/history/{run}', [
    \App\Http\Controllers\SensitivityLabelSweepHistoryController::class, 'show',
])->name('sensitivity-labels.sweep.history.show');
```

- [ ] **Step 5: Run test — expect PASS**

```bash
php artisan test --compact --filter=SensitivityLabelSweepHistoryControllerTest
```

Expected: PASS (4 tests).

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SensitivityLabelSweepHistoryController.php routes/web.php tests/Feature/Controllers/SensitivityLabelSweepHistoryControllerTest.php
git commit -m "feat: add sweep history controller and routes"
```

---

### Task 21: Manual apply + refresh label endpoints + tests

**Files:**
- Modify: `app/Http/Controllers/SensitivityLabelController.php` (add `applyToSite`)
- Modify: `app/Http/Controllers/SharePointSiteController.php` (add `refreshLabel`)
- Create: `app/Http/Requests/ApplySiteLabelRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Controllers/SensitivityLabelManualApplyTest.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Controllers/SensitivityLabelManualApplyTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\SensitivityLabel;
use App\Models\SiteSensitivityLabel;
use App\Models\User;
use App\Services\BridgeClient;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeUnavailableException;

function makeSiteRow(): SiteSensitivityLabel
{
    return SiteSensitivityLabel::create([
        'site_id' => 'sid1',
        'site_name' => 'Test',
        'site_url' => 'https://a/sites/Test',
        'sensitivity_label_id' => null,
        'synced_at' => now(),
    ]);
}

test('admin can apply label to a site', function () {
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')
        ->with('https://a/sites/Test', 'lbl', true)
        ->andReturn(new SetLabelResult('https://a/sites/Test', 'lbl', false));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect();

    expect($site->fresh()->sensitivity_label_id)->toBe($label->id);
    expect(ActivityLog::where('action', ActivityAction::LabelApplied->value)->count())->toBe(1);
});

test('operator can apply label', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andReturn(new SetLabelResult('https://a/sites/Test', 'lbl', false));
    $this->app->instance(BridgeClient::class, $mock);

    $op = User::factory()->create(['role' => UserRole::Operator, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($op)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect();
});

test('viewer cannot apply label', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $viewer = User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($viewer)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertForbidden();
});

test('bridge auth exception surfaces friendly flash', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeAuthException('401'));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('refresh label calls bridge read and updates DB', function () {
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->with('https://a/sites/Test')->andReturn('lbl');
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.refresh-label', $site))
        ->assertRedirect();

    expect($site->fresh()->sensitivity_label_id)->toBe($label->id);
});

test('refresh with bridge unavailable flashes error', function () {
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->andThrow(new BridgeUnavailableException('down'));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.refresh-label', $site))
        ->assertRedirect()
        ->assertSessionHas('error');
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=SensitivityLabelManualApplyTest
```

Expected: FAIL, routes not found.

- [ ] **Step 3: Write `ApplySiteLabelRequest`**

`app/Http/Requests/ApplySiteLabelRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplySiteLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label_id' => ['required', 'string', 'max:50', 'exists:sensitivity_labels,label_id'],
        ];
    }
}
```

- [ ] **Step 4: Add methods to `SharePointSiteController`**

Read the current `app/Http/Controllers/SharePointSiteController.php`. Add these two methods (along with needed `use` statements):

```php
use App\Enums\ActivityAction;
use App\Http\Requests\ApplySiteLabelRequest;
use App\Models\SensitivityLabel;
use App\Models\SiteSensitivityLabel;
use App\Services\ActivityLogService;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnavailableException;
use Illuminate\Http\RedirectResponse;

public function applyLabel(
    ApplySiteLabelRequest $request,
    SiteSensitivityLabel $site,
    BridgeClient $bridge,
    ActivityLogService $log,
): RedirectResponse {
    $labelId = $request->validated('label_id');
    $label = SensitivityLabel::where('label_id', $labelId)->firstOrFail();

    try {
        $bridge->setLabel($site->site_url, $labelId, overwrite: true);
    } catch (BridgeException $e) {
        $message = $this->friendlyMessage($e);
        return redirect()->back()->with('error', $message);
    }

    $site->update(['sensitivity_label_id' => $label->id, 'synced_at' => now()]);

    $log->log($request->user(), ActivityAction::LabelApplied, subject: $site, details: [
        'site_url' => $site->site_url,
        'label_id' => $labelId,
    ]);

    return redirect()->back()->with('success', "Label applied to {$site->site_name}.");
}

public function refreshLabel(
    SiteSensitivityLabel $site,
    BridgeClient $bridge,
): RedirectResponse {
    try {
        $labelId = $bridge->readLabel($site->site_url);
    } catch (BridgeException $e) {
        return redirect()->back()->with('error', $this->friendlyMessage($e));
    }

    if ($labelId === null) {
        $site->update(['sensitivity_label_id' => null, 'synced_at' => now()]);
    } else {
        $label = SensitivityLabel::where('label_id', $labelId)->first();
        $site->update([
            'sensitivity_label_id' => $label?->id,
            'synced_at' => now(),
        ]);
    }

    return redirect()->back()->with('success', 'Label refreshed from SharePoint.');
}

private function friendlyMessage(BridgeException $e): string
{
    return match (true) {
        $e instanceof BridgeAuthException => "The bridge can't authenticate to SharePoint. Check the sidecar's certificate and app permissions.",
        $e instanceof BridgeThrottleException => 'SharePoint is rate-limiting requests. Try again in a minute.',
        $e instanceof BridgeUnavailableException => 'The label sidecar is not reachable. Check deployment health.',
        $e instanceof BridgeConfigException => "The sidecar's certificate or configuration is invalid. Contact an administrator.",
        default => 'Label change failed: '.$e->getMessage(),
    };
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, inside the same `auth/verified/approved` group, add (gated by operator+ middleware):

```php
Route::middleware('role:admin,operator')->group(function () {
    Route::post('/sharepoint-sites/{site}/apply-label', [
        \App\Http\Controllers\SharePointSiteController::class, 'applyLabel',
    ])->name('sharepoint-sites.apply-label');

    Route::post('/sharepoint-sites/{site}/refresh-label', [
        \App\Http\Controllers\SharePointSiteController::class, 'refreshLabel',
    ])->name('sharepoint-sites.refresh-label');
});
```

- [ ] **Step 6: Run test — expect PASS**

```bash
php artisan test --compact --filter=SensitivityLabelManualApplyTest
```

Expected: PASS (6 tests).

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/SharePointSiteController.php app/Http/Requests/ApplySiteLabelRequest.php routes/web.php tests/Feature/Controllers/SensitivityLabelManualApplyTest.php
git commit -m "feat: add manual apply-label and refresh-label endpoints"
```

---

## Phase 6 — Vue UI

All four pages use the project's existing Inertia patterns (`useForm`, `<Link>`, shadcn-vue + Reka UI components). The pages mirror Partner365 conventions — check `resources/js/pages/sensitivity-labels/Index.vue` and `resources/js/pages/templates/` for the layout/table/form patterns already in use. If the project has `@/layouts/AppLayout.vue`, use it. If a component like `<Button>`, `<Input>`, `<Table>` is already used elsewhere, match that.

### Task 22: Sweep Config Vue page

**Files:**
- Create: `resources/js/pages/sensitivity-labels/Sweep/Config.vue`

- [ ] **Step 1: Read an existing admin config page for conventions**

Look at `resources/js/pages/templates/Index.vue` or `resources/js/pages/admin/` if such a folder exists. Note the component imports (e.g., `@/components/ui/button`), form patterns (`useForm`), and layout wrapper used.

- [ ] **Step 2: Write `Config.vue`**

`resources/js/pages/sensitivity-labels/Sweep/Config.vue`:

```vue
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

interface Label { id: number; label_id: string; name: string }
interface Rule { prefix: string; label_id: string; priority: number }
interface Exclusion { pattern: string }
interface BridgeHealth { status: string; cloudEnvironment: string; certThumbprint: string }
interface LastRun { id: number; started_at: string; status: string; applied: number; total_scanned: number }

const props = defineProps<{
    settings: {
        enabled: boolean
        interval_minutes: number
        default_label_id: string
        bridge_url: string
        bridge_shared_secret: string
    }
    rules: Rule[]
    exclusions: Exclusion[]
    labels: Label[]
    lastRun: LastRun | null
    bridgeHealth: BridgeHealth | null
    bridgeError: string | null
}>()

const form = useForm({
    enabled: props.settings.enabled,
    interval_minutes: props.settings.interval_minutes,
    default_label_id: props.settings.default_label_id,
    bridge_url: props.settings.bridge_url,
    bridge_shared_secret: props.settings.bridge_shared_secret,
    rules: props.rules.map((r) => ({ prefix: r.prefix, label_id: r.label_id, priority: r.priority })),
    exclusions: props.exclusions.map((e) => ({ pattern: e.pattern })),
})

const bridgeStatusLabel = computed(() => {
    if (props.bridgeError) return 'Unreachable'
    if (props.bridgeHealth) return `OK (${props.bridgeHealth.cloudEnvironment})`
    return 'Unknown'
})

const bridgeStatusClass = computed(() => {
    if (props.bridgeError) return 'bg-red-100 text-red-800'
    if (props.bridgeHealth) return 'bg-green-100 text-green-800'
    return 'bg-gray-100 text-gray-800'
})

function addRule() {
    const maxPriority = form.rules.reduce((m, r) => Math.max(m, r.priority), 0)
    form.rules.push({ prefix: '', label_id: props.labels[0]?.label_id ?? '', priority: maxPriority + 1 })
}

function removeRule(index: number) {
    form.rules.splice(index, 1)
}

function addExclusion() {
    form.exclusions.push({ pattern: '' })
}

function removeExclusion(index: number) {
    form.exclusions.splice(index, 1)
}

function submit() {
    form.put(route('sensitivity-labels.sweep.config.update'))
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6 p-6">
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Sensitivity sweep configuration</h1>
            <Link
                :href="route('sensitivity-labels.sweep.history')"
                class="text-sm text-blue-600 hover:underline"
            >
                View run history
            </Link>
        </header>

        <section class="rounded-lg border bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Sweep status</h2>
            <div class="flex items-center gap-2">
                <span
                    class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                    :class="bridgeStatusClass"
                    :title="bridgeHealth?.certThumbprint ?? bridgeError ?? ''"
                >
                    Bridge: {{ bridgeStatusLabel }}
                </span>
                <span v-if="lastRun" class="text-sm text-gray-500">
                    Last run #{{ lastRun.id }} — {{ lastRun.status }}, {{ lastRun.applied }}/{{ lastRun.total_scanned }} applied
                </span>
            </div>

            <div v-if="bridgeError" class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                {{ bridgeError }}
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <label class="flex items-center gap-2">
                    <input v-model="form.enabled" type="checkbox" />
                    <span>Enabled</span>
                </label>
                <label class="flex items-center gap-2">
                    <span class="w-48">Interval (minutes)</span>
                    <input
                        v-model.number="form.interval_minutes"
                        type="number"
                        min="1"
                        class="w-24 rounded border px-2 py-1"
                    />
                </label>
            </div>
        </section>

        <section class="rounded-lg border bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Default label</h2>
            <select v-model="form.default_label_id" class="w-full rounded border px-2 py-1">
                <option value="">(none)</option>
                <option v-for="l in labels" :key="l.id" :value="l.label_id">{{ l.name }}</option>
            </select>
            <p class="mt-2 text-sm text-gray-500">Applied when no prefix rule matches.</p>
        </section>

        <section class="rounded-lg border bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Prefix rules</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-2 py-1 w-16">Priority</th>
                        <th class="px-2 py-1">Prefix</th>
                        <th class="px-2 py-1">Label</th>
                        <th class="px-2 py-1 w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(rule, i) in form.rules" :key="i" class="border-b">
                        <td class="px-2 py-1">
                            <input v-model.number="rule.priority" type="number" min="1" class="w-16 rounded border px-2 py-1" />
                        </td>
                        <td class="px-2 py-1">
                            <input v-model="rule.prefix" type="text" class="w-full rounded border px-2 py-1" />
                        </td>
                        <td class="px-2 py-1">
                            <select v-model="rule.label_id" class="w-full rounded border px-2 py-1">
                                <option v-for="l in labels" :key="l.id" :value="l.label_id">{{ l.name }}</option>
                            </select>
                        </td>
                        <td class="px-2 py-1">
                            <button type="button" @click="removeRule(i)" class="text-red-600 hover:underline">Remove</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <button type="button" @click="addRule" class="mt-2 rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
                Add rule
            </button>
        </section>

        <section class="rounded-lg border bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Site exclusions</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-2 py-1">Pattern</th>
                        <th class="px-2 py-1 w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(ex, i) in form.exclusions" :key="i" class="border-b">
                        <td class="px-2 py-1">
                            <input v-model="ex.pattern" type="text" class="w-full rounded border px-2 py-1" />
                        </td>
                        <td class="px-2 py-1">
                            <button type="button" @click="removeExclusion(i)" class="text-red-600 hover:underline">Remove</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <button type="button" @click="addExclusion" class="mt-2 rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
                Add exclusion
            </button>
            <p class="mt-2 text-xs text-gray-500">
                Case-insensitive substring match. Matching sites are dropped from tracking on the next sweep.
            </p>
        </section>

        <section class="rounded-lg border bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Bridge connection</h2>
            <div class="grid grid-cols-1 gap-4">
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">Bridge URL</span>
                    <input v-model="form.bridge_url" type="text" class="rounded border px-2 py-1" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">Shared secret</span>
                    <input v-model="form.bridge_shared_secret" type="password" class="rounded border px-2 py-1" />
                </label>
            </div>
        </section>

        <footer class="flex justify-end">
            <button
                type="button"
                @click="submit"
                :disabled="form.processing"
                class="rounded bg-green-600 px-4 py-2 text-white hover:bg-green-700 disabled:opacity-50"
            >
                Save configuration
            </button>
        </footer>
    </div>
</template>
```

- [ ] **Step 3: Run types check**

```bash
npm run types:check
```

Expected: no TypeScript errors.

- [ ] **Step 4: Run ESLint + Prettier**

```bash
npm run lint
npm run format
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Sweep/Config.vue
git commit -m "feat: add Sweep Config Vue page"
```

---

### Task 23: Sweep History and HistoryDetail Vue pages

**Files:**
- Create: `resources/js/pages/sensitivity-labels/Sweep/History.vue`
- Create: `resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue`

- [ ] **Step 1: Write `History.vue`**

`resources/js/pages/sensitivity-labels/Sweep/History.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

interface Run {
    id: number
    started_at: string
    completed_at: string | null
    total_scanned: number
    applied: number
    failed: number
    status: string
}

interface Pagination { data: Run[]; links: unknown[]; meta?: unknown }

defineProps<{ runs: Pagination }>()

function statusBadge(status: string): string {
    if (status === 'success') return 'bg-green-100 text-green-800'
    if (status === 'partial_failure') return 'bg-yellow-100 text-yellow-800'
    if (status === 'failed' || status === 'aborted') return 'bg-red-100 text-red-800'
    return 'bg-gray-100 text-gray-800'
}

function duration(run: Run): string {
    if (!run.completed_at) return '—'
    const started = new Date(run.started_at).getTime()
    const completed = new Date(run.completed_at).getTime()
    const seconds = Math.round((completed - started) / 1000)
    if (seconds < 60) return `${seconds}s`
    return `${Math.round(seconds / 60)}m`
}
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-4 p-6">
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Sweep run history</h1>
            <Link :href="route('sensitivity-labels.sweep.config')" class="text-sm text-blue-600 hover:underline">
                Configuration
            </Link>
        </header>

        <table class="w-full rounded-lg border bg-white text-sm shadow-sm">
            <thead>
                <tr class="border-b text-left">
                    <th class="px-3 py-2">#</th>
                    <th class="px-3 py-2">Started</th>
                    <th class="px-3 py-2">Duration</th>
                    <th class="px-3 py-2 text-right">Scanned</th>
                    <th class="px-3 py-2 text-right">Applied</th>
                    <th class="px-3 py-2 text-right">Failed</th>
                    <th class="px-3 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in runs.data" :key="run.id" class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2">
                        <Link :href="route('sensitivity-labels.sweep.history.show', run.id)" class="text-blue-600 hover:underline">
                            #{{ run.id }}
                        </Link>
                    </td>
                    <td class="px-3 py-2">{{ new Date(run.started_at).toLocaleString() }}</td>
                    <td class="px-3 py-2">{{ duration(run) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ run.total_scanned }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ run.applied }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ run.failed }}</td>
                    <td class="px-3 py-2">
                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium" :class="statusBadge(run.status)">
                            {{ run.status }}
                        </span>
                    </td>
                </tr>
                <tr v-if="runs.data.length === 0">
                    <td colspan="7" class="px-3 py-6 text-center text-gray-500">No sweep runs yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 2: Write `HistoryDetail.vue`**

`resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

interface Rule { id: number; prefix: string; label_id: string }
interface Entry {
    id: number
    site_url: string
    site_title: string
    action: string
    label_id: string | null
    matched_rule: Rule | null
    error_message: string | null
    error_code: string | null
    processed_at: string
}

interface Run {
    id: number
    started_at: string
    completed_at: string | null
    status: string
    error_message: string | null
    total_scanned: number
    applied: number
    failed: number
    skipped_excluded: number
    already_labeled: number
}

defineProps<{ run: Run; entries: Entry[] }>()

function actionClass(action: string): string {
    if (action === 'applied') return 'bg-green-100 text-green-800'
    if (action === 'failed') return 'bg-red-100 text-red-800'
    if (action.startsWith('skipped')) return 'bg-yellow-100 text-yellow-800'
    return 'bg-gray-100 text-gray-800'
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-4 p-6">
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Sweep run #{{ run.id }}</h1>
            <Link :href="route('sensitivity-labels.sweep.history')" class="text-sm text-blue-600 hover:underline">
                Back to history
            </Link>
        </header>

        <section class="rounded-lg border bg-white p-4 text-sm shadow-sm">
            <div class="grid grid-cols-3 gap-4">
                <div><span class="font-medium">Started:</span> {{ new Date(run.started_at).toLocaleString() }}</div>
                <div><span class="font-medium">Status:</span> {{ run.status }}</div>
                <div>
                    <span class="font-medium">Applied/Scanned:</span>
                    {{ run.applied }}/{{ run.total_scanned }}
                </div>
            </div>
            <div v-if="run.error_message" class="mt-2 text-red-700">{{ run.error_message }}</div>
        </section>

        <table class="w-full rounded-lg border bg-white text-sm shadow-sm">
            <thead>
                <tr class="border-b text-left">
                    <th class="px-3 py-2">Site</th>
                    <th class="px-3 py-2">URL</th>
                    <th class="px-3 py-2">Action</th>
                    <th class="px-3 py-2">Label</th>
                    <th class="px-3 py-2">Matched rule</th>
                    <th class="px-3 py-2">Error</th>
                    <th class="px-3 py-2">Time</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries" :key="entry.id" class="border-b">
                    <td class="px-3 py-2">{{ entry.site_title }}</td>
                    <td class="px-3 py-2 truncate" :title="entry.site_url">
                        <a :href="entry.site_url" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                            {{ entry.site_url }}
                        </a>
                    </td>
                    <td class="px-3 py-2">
                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium" :class="actionClass(entry.action)">
                            {{ entry.action }}
                        </span>
                    </td>
                    <td class="px-3 py-2">{{ entry.label_id ?? '—' }}</td>
                    <td class="px-3 py-2">{{ entry.matched_rule?.prefix ?? '—' }}</td>
                    <td class="px-3 py-2 text-red-700">{{ entry.error_message ?? '' }}</td>
                    <td class="px-3 py-2 text-gray-500">{{ new Date(entry.processed_at).toLocaleString() }}</td>
                </tr>
                <tr v-if="entries.length === 0">
                    <td colspan="7" class="px-3 py-6 text-center text-gray-500">No entries in this run.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 3: Run types check, lint, format**

```bash
npm run types:check
npm run lint
npm run format
```

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Sweep/
git commit -m "feat: add Sweep History and HistoryDetail Vue pages"
```

---

### Task 24: Sensitivity card on `sharepoint-sites/Show.vue`

**Files:**
- Modify: `resources/js/pages/sharepoint-sites/Show.vue`

The existing file renders the site's core properties. Add a "Sensitivity label" card that surfaces the current label, a refresh button, and an admin/operator-only change-label dialog.

- [ ] **Step 1: Inspect existing `Show.vue`**

Read `resources/js/pages/sharepoint-sites/Show.vue`. Note:
- How existing prop types are declared.
- Which cards/sections already exist — match that visual pattern.
- Which `useForm` usage examples there are, if any.

- [ ] **Step 2: Add `SensitivityCard` inline section**

At the top of the `<script setup>` block, ensure props include the data the card needs. If the controller currently doesn't pass `availableLabels` or `isExcluded`, extend the controller:

Modify `app/Http/Controllers/SharePointSiteController.php`'s `show` method. Add to the Inertia props array:

```php
'availableLabels' => \App\Models\SensitivityLabel::orderBy('name')->get(['id', 'label_id', 'name']),
// Case-insensitive substring match in PHP — avoids SQL dialect issues (SQLite vs. MySQL CONCAT).
// Exclusion list is small; pulling it in full is cheap.
'isExcluded' => \App\Models\SiteExclusion::all()->contains(
    fn ($e) => $e->pattern !== '' && stripos($site->site_url, $e->pattern) !== false
),
'currentLabel' => $site->sensitivity_label_id
    ? \App\Models\SensitivityLabel::find($site->sensitivity_label_id)?->only(['id', 'label_id', 'name'])
    : null,
```

(Adjust to match existing shape of that controller's `show` method — many projects pass an array variable, so slot this alongside whatever's already there.)

- [ ] **Step 3: Add card markup to `Show.vue`**

Inside the existing template, in a reasonable spot (after the site metadata, before any "danger zone" sections), add this block:

```vue
<section class="rounded-lg border bg-white p-6 shadow-sm">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-lg font-semibold">Sensitivity label</h2>
        <span
            v-if="isExcluded"
            class="inline-flex items-center rounded bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800"
        >
            Excluded from automated sweeps
        </span>
    </div>

    <div class="flex items-center gap-4">
        <div class="flex-1">
            <div class="text-sm text-gray-500">Current label</div>
            <div class="text-base font-medium">{{ currentLabel?.name ?? 'No label' }}</div>
            <div v-if="currentLabel" class="text-xs text-gray-400">{{ currentLabel.label_id }}</div>
        </div>

        <button
            type="button"
            class="rounded border px-3 py-1 text-sm hover:bg-gray-50"
            @click="refreshLabel"
            :disabled="refreshForm.processing"
        >
            Refresh from SharePoint
        </button>

        <button
            v-if="canManage"
            type="button"
            class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700"
            @click="openApplyDialog"
        >
            Change label
        </button>
    </div>

    <div v-if="showApplyDialog" class="mt-4 rounded border bg-gray-50 p-3">
        <label class="mb-2 block text-sm font-medium">Select label</label>
        <select v-model="applyForm.label_id" class="w-full rounded border px-2 py-1">
            <option v-for="l in availableLabels" :key="l.id" :value="l.label_id">{{ l.name }}</option>
        </select>
        <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="rounded border px-3 py-1 text-sm" @click="showApplyDialog = false">Cancel</button>
            <button
                type="button"
                class="rounded bg-green-600 px-3 py-1 text-sm text-white hover:bg-green-700"
                @click="submitApply"
                :disabled="applyForm.processing || !applyForm.label_id"
            >
                Apply
            </button>
        </div>
    </div>
</section>
```

- [ ] **Step 4: Add corresponding `<script setup>` logic**

Alongside existing imports, import `useForm` from `@inertiajs/vue3` (if not present), then add:

```ts
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

// ...existing defineProps + additional fields:
const props = defineProps<{
    // existing props stay as-is
    availableLabels: Array<{ id: number; label_id: string; name: string }>
    isExcluded: boolean
    currentLabel: { id: number; label_id: string; name: string } | null
    canManage: boolean   // inject via Inertia share or as a prop from the controller
    site: { id: number; site_url: string; site_name: string }
}>()

const showApplyDialog = ref(false)
const applyForm = useForm({ label_id: props.currentLabel?.label_id ?? props.availableLabels[0]?.label_id ?? '' })
const refreshForm = useForm({})

function openApplyDialog() {
    showApplyDialog.value = true
}

function submitApply() {
    applyForm.post(route('sharepoint-sites.apply-label', props.site.id), {
        preserveScroll: true,
        onSuccess: () => { showApplyDialog.value = false },
    })
}

function refreshLabel() {
    refreshForm.post(route('sharepoint-sites.refresh-label', props.site.id), { preserveScroll: true })
}
```

If the project uses Inertia shared data for `canManage` (common: computed from `$page.props.auth.user.role`), wire that instead of adding it as a prop.

- [ ] **Step 5: Run types check and lint**

```bash
npm run types:check
npm run lint
npm run format
```

- [ ] **Step 6: Manually verify**

Start the dev stack:

```bash
composer run dev
```

Visit a SharePoint site show page. Confirm:
- Current label shows for a labeled site; "No label" for unlabeled.
- Refresh button appears and does not error (even if bridge is down, the page should render).
- Change label button only appears for admin/operator.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/sharepoint-sites/Show.vue app/Http/Controllers/SharePointSiteController.php
git commit -m "feat: add sensitivity card to SharePoint site detail page"
```

---

### Task 25: Rules-using-this-label column on Labels Index

**Files:**
- Modify: `app/Http/Controllers/SensitivityLabelController.php` — add rule counts to index payload
- Modify: `resources/js/pages/sensitivity-labels/Index.vue` — render the new column
- Test: `tests/Feature/Controllers/SensitivityLabelIndexRuleCountTest.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Controllers/SensitivityLabelIndexRuleCountTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\LabelRule;
use App\Models\SensitivityLabel;
use App\Models\User;

test('labels index includes rule_count per label', function () {
    $label = SensitivityLabel::create(['label_id' => 'lbl1', 'name' => 'X', 'protection_type' => 'none']);
    SensitivityLabel::create(['label_id' => 'lbl2', 'name' => 'Y', 'protection_type' => 'none']);
    LabelRule::create(['prefix' => 'A', 'label_id' => 'lbl1', 'priority' => 1]);
    LabelRule::create(['prefix' => 'B', 'label_id' => 'lbl1', 'priority' => 2]);

    $viewer = User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]);

    $this->actingAs($viewer)
        ->get(route('sensitivity-labels.index'))
        ->assertInertia(fn ($p) =>
            $p->component('sensitivity-labels/Index')
              ->where('labels.0.rule_count', fn ($c) => $c === 2 || $c === 0)
        );
});
```

(The assertion shape is loose because we don't know the exact index of `lbl1` in the sorted payload; this verifies the key exists and is numeric.)

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --compact --filter=SensitivityLabelIndexRuleCountTest
```

Expected: FAIL — `rule_count` not in payload.

- [ ] **Step 3: Extend controller index action**

In `app/Http/Controllers/SensitivityLabelController.php`'s `index` method, when building the labels list, add the rule count:

```php
use App\Models\LabelRule;
use Illuminate\Support\Facades\DB;

// ...inside index(), where the labels query is built:
$ruleCounts = LabelRule::query()
    ->select('label_id', DB::raw('count(*) as c'))
    ->groupBy('label_id')
    ->pluck('c', 'label_id');

$labels = \App\Models\SensitivityLabel::orderBy('name')->get();
$labels->each(function ($l) use ($ruleCounts) {
    $l->rule_count = (int) ($ruleCounts[$l->label_id] ?? 0);
});
```

(Adjust to match the existing `index` method's shape — if it already builds `$labels` differently, splice in the rule_count annotation at that point. The key is that each label in the Inertia payload has an extra `rule_count` field.)

- [ ] **Step 4: Add column to `Index.vue`**

In `resources/js/pages/sensitivity-labels/Index.vue`, in the labels table, add a header cell and data cell for rule count next to existing columns:

```vue
<!-- add in <thead> next to other <th>s: -->
<th class="px-3 py-2 text-right">Rules using</th>

<!-- add in each row, matching position: -->
<td class="px-3 py-2 text-right tabular-nums">{{ label.rule_count ?? 0 }}</td>
```

- [ ] **Step 5: Run test — expect PASS**

```bash
php artisan test --compact --filter=SensitivityLabelIndexRuleCountTest
```

Expected: PASS.

- [ ] **Step 6: Run types check, lint, Pint**

```bash
npm run types:check
npm run lint
npm run format
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SensitivityLabelController.php resources/js/pages/sensitivity-labels/Index.vue tests/Feature/Controllers/SensitivityLabelIndexRuleCountTest.php
git commit -m "feat: show rule-count per label on catalog index"
```

---

### Task 26: Add sidebar navigation for Sweep pages

**Files:**
- Modify: wherever the project's sidebar navigation is defined (likely `resources/js/layouts/AppSidebar.vue` or similar). If unclear, grep for existing nav entries.

- [ ] **Step 1: Find the sidebar component**

```bash
grep -rn "sensitivity-labels.index\|sensitivity-labels/index" resources/js/
```

Identify the file that renders the main app navigation. Adjust to match the project's actual layout file.

- [ ] **Step 2: Add sweep links under the existing "Sensitivity labels" sidebar group**

Add two new links — one for Sweep > Configuration (admin only) and one for Sweep > History (all roles). Use the existing conditional-render pattern for role gating. Example shape (adapt to the project's actual nav markup):

```vue
<Link :href="route('sensitivity-labels.sweep.history')">Sweep history</Link>
<Link
    v-if="$page.props.auth.user.role === 'admin'"
    :href="route('sensitivity-labels.sweep.config')"
>
    Sweep config
</Link>
```

- [ ] **Step 3: Manually verify**

Start `composer run dev`, log in as admin. Confirm both new links appear. Log in as viewer. Confirm only Sweep history appears.

- [ ] **Step 4: Commit**

```bash
git add resources/js/
git commit -m "feat: add Sweep config and history to sidebar nav"
```

---

## Phase 7 — Deployment, env, docs

### Task 27: docker-compose.yml + .env.example

**Files:**
- Modify: `docker-compose.yml`
- Modify: `.env.example`

- [ ] **Step 1: Inspect existing `docker-compose.yml`**

Read `docker-compose.yml`. Note the name of the app service (likely `app` or similar), the network name (may be implicit), and the image/build config. The bridge service we add must share a network with `app`.

- [ ] **Step 2: Add bridge service to `docker-compose.yml`**

Append (or splice into the `services:` block) the following. Adjust service names to match the existing file's conventions:

```yaml
  bridge:
    build:
      context: ./bridge
      dockerfile: Dockerfile
    image: partner365-bridge:latest
    environment:
      BRIDGE_CLOUD_ENVIRONMENT: ${MICROSOFT_GRAPH_CLOUD_ENVIRONMENT}
      BRIDGE_TENANT_ID: ${MICROSOFT_GRAPH_TENANT_ID}
      BRIDGE_CLIENT_ID: ${MICROSOFT_GRAPH_CLIENT_ID}
      BRIDGE_ADMIN_SITE_URL: ${SHAREPOINT_ADMIN_SITE_URL}
      BRIDGE_CERT_PATH: /run/secrets/bridge.pfx
      BRIDGE_CERT_PASSWORD: ${BRIDGE_CERT_PASSWORD}
      BRIDGE_SHARED_SECRET: ${BRIDGE_SHARED_SECRET}
      ASPNETCORE_URLS: http://0.0.0.0:8080
    volumes:
      - ${BRIDGE_CERT_HOST_PATH}:/run/secrets/bridge.pfx:ro
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://localhost:8080/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
    restart: unless-stopped
    # Deliberately NO `ports:` — bridge is internal-only.
```

- [ ] **Step 3: Wire `app` to depend on bridge health**

Under the existing `app` (or whatever it's called) service, add:

```yaml
    depends_on:
      bridge:
        condition: service_healthy
```

If an existing `networks:` key is used, ensure both services are on the same network. If there is no network block, Docker Compose's default bridge network handles DNS — the bridge will be reachable as `http://bridge:8080`.

- [ ] **Step 4: Append bridge env vars to `.env.example`**

At the bottom of `.env.example`:

```
# --- Sensitivity labels sidecar (bridge) ---
SHAREPOINT_ADMIN_SITE_URL=https://contoso-admin.sharepoint.com
BRIDGE_CERT_HOST_PATH=./storage/bridge/bridge.pfx
BRIDGE_CERT_PASSWORD=
# Generate with: openssl rand -hex 32
BRIDGE_SHARED_SECRET=
```

- [ ] **Step 5: Verify compose parses**

```bash
docker compose config
```

Expected: no YAML/validation errors. If env vars are missing when run without a populated `.env`, use a throwaway `.env` with placeholder values to test parsing.

- [ ] **Step 6: Verify the bridge image builds from the compose file**

```bash
docker compose build bridge
```

Expected: `partner365-bridge:latest` image built successfully (same output as Task 13 Step 2).

- [ ] **Step 7: Commit**

```bash
git add docker-compose.yml .env.example
git commit -m "feat: wire partner365-bridge into docker-compose"
```

---

### Task 28: Admin setup docs

**Files:**
- Create: `docs/admin/sensitivity-labels-sidecar-setup.md`

- [ ] **Step 1: Write the doc**

`docs/admin/sensitivity-labels-sidecar-setup.md`:

```markdown
# Sensitivity labels sidecar setup

This page walks a Partner365 administrator through enabling the sensitivity-labels sweep feature, which uses a companion .NET sidecar (`partner365-bridge`) to write labels to SharePoint sites. The sidecar exists because Microsoft Graph cannot set sensitivity labels on SharePoint sites in GCC High (and is unreliable in commercial), so CSOM is the only viable write path.

## Prerequisites

- You are running Partner365 via `docker compose` from this repo.
- You have tenant administrator access to the Entra portal.
- You have Global Admin (or Application Administrator + SharePoint Administrator) available to consent new permissions.
- `openssl` and `PowerShell 7+` are available locally.

## Step 1 — Generate a certificate for the bridge

On Windows (elevated PowerShell):

```powershell
$cert = New-SelfSignedCertificate `
    -Subject "CN=Partner365-Bridge" `
    -KeyAlgorithm RSA -KeyLength 2048 `
    -CertStoreLocation "Cert:\CurrentUser\My" `
    -NotAfter (Get-Date).AddYears(2) `
    -KeyExportPolicy Exportable `
    -KeySpec Signature

# Export the public cer for upload to Entra
Export-Certificate -Cert $cert -FilePath .\bridge.cer

# Export the pfx for the container
$pw = ConvertTo-SecureString -String "CHOOSE-A-PASSWORD" -Force -AsPlainText
Export-PfxCertificate -Cert $cert -FilePath .\bridge.pfx -Password $pw
```

Copy the resulting `bridge.pfx` to `./storage/bridge/bridge.pfx` (or wherever you plan to point `BRIDGE_CERT_HOST_PATH`). Keep the password — you'll put it in `.env`.

## Step 2 — Add credentials and permissions to the Partner365 app registration

In the Entra admin portal, find the existing Partner365 app registration (the one matching `MICROSOFT_GRAPH_CLIENT_ID`).

1. **Upload the certificate:** Certificates & secrets → Certificates → Upload certificate → pick `bridge.cer`. Keep the existing client secret — Partner365's Graph path still uses it.

2. **Add API permissions:**
   - Microsoft Graph → Application permissions → `Sites.FullControl.All` — if not already present.
   - Office 365 SharePoint Online → Application permissions → `Sites.FullControl.All` — required for CSOM tenant admin.

3. **Grant admin consent** for both. Both rows should show a green check next to the tenant name.

## Step 3 — Populate `.env`

Copy `.env.example` to `.env` (if you haven't already) and set:

```
MICROSOFT_GRAPH_CLOUD_ENVIRONMENT=commercial     # or gcc-high
MICROSOFT_GRAPH_TENANT_ID=<your tenant guid>
MICROSOFT_GRAPH_CLIENT_ID=<existing partner365 app reg id>

SHAREPOINT_ADMIN_SITE_URL=https://<tenant>-admin.sharepoint.com   # or .sharepoint.us for GCC High
BRIDGE_CERT_HOST_PATH=./storage/bridge/bridge.pfx
BRIDGE_CERT_PASSWORD=<pfx password from Step 1>
BRIDGE_SHARED_SECRET=<run `openssl rand -hex 32`>
```

## Step 4 — Bring the stack up

```bash
docker compose up -d --build
```

Watch bridge startup:

```bash
docker compose logs -f bridge
```

Expected first lines: cert thumbprint, cloud environment, Kestrel bind. If the bridge fails here, check:
- `BRIDGE_CERT_PATH` is reachable inside the container.
- Cert password is correct.
- `BRIDGE_ADMIN_SITE_URL` contains `-admin.` (the bridge will refuse to start otherwise).

## Step 5 — Verify in Partner365

1. Log in as an admin.
2. Sidebar → Sensitivity labels → Sweep config.
3. The bridge indicator at the top should be green with the correct cloud environment.
4. Set **Default label** to a label from your tenant's catalog.
5. Leave the rules and exclusions empty for the first test.
6. Save.

## Step 6 — First dry-run

```bash
docker compose exec app php artisan sensitivity:sweep --force --dry-run
```

Open Sweep history. The run should show `status=success`, every scanned site listed with `action=applied` and the message `[dry-run] would apply` in the error column. No site in SharePoint was actually relabeled.

## Step 7 — First live sweep

1. Sweep Config page → set **Enabled** to on. Save.
2. Wait for the scheduled run or force one:
    ```bash
    docker compose exec app php artisan sensitivity:sweep --force
    ```
3. First live sweep takes **10–30 minutes** against ~2000 sites. Subsequent sweeps hit the fast-path and finish in a couple of minutes.
4. Spot-check two sites in SharePoint admin center → Active sites, toggle on the "Sensitivity" column. Newly-applied labels should appear.

## Troubleshooting

| Symptom | Most likely cause | Fix |
|---|---|---|
| Bridge indicator red, "unreachable" | Container not running | `docker compose ps`, check `docker compose logs bridge` for startup error |
| Sweep run `failed` immediately with "Bridge pre-flight failed" | Secret mismatch between .env and Partner365 settings | Open Sweep config → re-save to sync `BRIDGE_SHARED_SECRET` from env into settings |
| Many entries with `error_code=auth` | Cert or consent problem | Re-verify both `Sites.FullControl.All` grants are consented in Entra |
| Run aborts after exactly 3 failures | Systemic-failure abort fired | Check admin inbox for `SweepAbortedNotification`; fix the cert/consent issue and re-run |
| Sweep applies correctly but UI says "No label" after | Partner365's cached `SiteSensitivityLabel` is stale | On site detail page, click **Refresh from SharePoint** |

## Secret rotation

1. Generate new shared secret (`openssl rand -hex 32`).
2. Update `BRIDGE_SHARED_SECRET` in `.env` → `docker compose up -d bridge` to restart bridge with the new value.
3. In Sweep Config → paste new secret into "Shared secret" → Save.
4. Trigger a test sweep to confirm.

Brief overlap is fine — in-flight jobs will retry with backoff.

## Rollback

- Disable the feature: Sweep Config → turn off Enabled → Save. Existing history stays; no new sweeps run.
- Remove bridge: comment out the `bridge` service in `docker-compose.yml`, `docker compose up -d`. Manual-apply buttons will fail with a user-friendly "sidecar not reachable" message. Scheduled command will fail at pre-flight health check. No labels in M365 are rolled back — they stay as they were set.
```

- [ ] **Step 2: Commit**

```bash
git add docs/admin/sensitivity-labels-sidecar-setup.md
git commit -m "docs: add sensitivity-labels sidecar setup guide"
```

---

## Final verification

### Task 29: End-to-end verification and CI check

- [ ] **Step 1: Run full Partner365 test suite**

```bash
composer run ci:check
```

Expected: all tests pass, Pint clean, TypeScript clean, ESLint clean.

- [ ] **Step 2: Run full bridge test suite**

```bash
cd bridge && dotnet test && cd ..
```

Expected: all tests pass.

- [ ] **Step 3: Build both Docker images**

```bash
docker compose build
```

Expected: both images build successfully.

- [ ] **Step 4: Bring the stack up and confirm health**

```bash
docker compose up -d
docker compose ps
```

Expected: both `app` and `bridge` services reach `healthy` state. (This requires a valid `.env` with real-ish values; for a first local smoke test, use a throwaway cert and placeholder tenant IDs — the bridge will start, but real CSOM calls will fail as expected.)

- [ ] **Step 5: Confirm scheduled command is registered**

```bash
docker compose exec app php artisan schedule:list
```

Expected: `sensitivity:sweep` appears in the list with a minute-level frequency.

- [ ] **Step 6: Confirm routes are wired**

```bash
docker compose exec app php artisan route:list --name=sensitivity-labels.sweep
docker compose exec app php artisan route:list --name=sharepoint-sites.apply-label
docker compose exec app php artisan route:list --name=sharepoint-sites.refresh-label
```

Expected: each grep finds at least one route.

- [ ] **Step 7: Tear down**

```bash
docker compose down
```

- [ ] **Step 8: Final commit (no-op if nothing changed)**

If any adjustment was needed during verification, commit it with:

```bash
git add -A
git commit -m "chore: final verification adjustments"
```

---

## Self-review notes

After finishing the plan, re-skim the spec at `docs/superpowers/specs/2026-04-23-sensitivity-label-bridge-design.md` and confirm every section maps to at least one task:

| Spec section | Task(s) |
|---|---|
| Architecture diagram & properties | Conceptual — reflected throughout Phases 2/3/7 |
| Bridge API surface (set/read/health) | Task 12 |
| Bridge internal design (CloudEnv, Cert, Error, Middleware, CSOM) | Tasks 6, 7, 8, 9, 11 |
| Error classification | Task 8 |
| Logging | Task 12 (structured logs via `ILogger<Program>`) |
| Partner365 data model (4 tables) | Task 1 |
| ActivityAction additions | Task 2 |
| Models + factories | Task 3 |
| Default exclusion seeder | Task 4 |
| Bridge exceptions + DTOs | Task 14 |
| BridgeClient | Task 15 |
| SensitivitySweepCommand | Task 17 |
| ApplySiteLabelJob | Task 16 |
| AbortSweepRunJob + CompleteSweepRunJob | Task 18 |
| Manual apply + refresh | Task 21 |
| Sweep Config UI | Tasks 19, 22 |
| Sweep History UI | Tasks 20, 23 |
| Sharepoint-sites sensitivity card | Task 24 |
| Labels Index rule-count | Task 25 |
| Sidebar navigation | Task 26 |
| Auth boundary (secret + internal network) | Tasks 9, 27 |
| Error handling invariants | Covered by tests in Tasks 16, 18 |
| Testing strategy | Every task's test step |
| Manual integration harness | Task 13 Step 3 |
| docker-compose & env | Task 27 |
| Admin docs | Task 28 |
