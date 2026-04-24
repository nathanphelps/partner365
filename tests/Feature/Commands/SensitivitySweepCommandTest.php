<?php

use App\Jobs\ApplySiteLabelJob;
use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\SensitivityLabel;
use App\Models\Setting;
use App\Models\SharePointSite;
use App\Models\SiteExclusion;
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

function makeSite(string $url, string $title, ?int $labelId = null): SharePointSite
{
    return SharePointSite::create([
        'site_id' => md5($url),
        'display_name' => $title,
        'url' => $url,
        'sensitivity_label_id' => $labelId,
        'synced_at' => now(),
    ]);
}

test('returns early when sweep is disabled', function () {
    Bus::fake();
    Setting::set('sensitivity_sweep', 'enabled', '0');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertNothingBatched();
    expect(LabelSweepRun::count())->toBe(0);
});

test('returns early when default label unset', function () {
    Bus::fake();
    Setting::set('sensitivity_sweep', 'default_label_id', '');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertNothingBatched();
    expect(LabelSweepRun::count())->toBe(0);
});

test('respects interval guard', function () {
    Bus::fake();
    LabelSweepRun::create(['started_at' => now()->subMinutes(10), 'status' => 'success']);

    $this->artisan('sensitivity:sweep')->assertSuccessful();

    expect(LabelSweepRun::count())->toBe(1);
    Bus::assertNothingBatched();
});

test('force flag bypasses interval guard', function () {
    Bus::fake();
    LabelSweepRun::create(['started_at' => now()->subMinutes(10), 'status' => 'success']);

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    expect(LabelSweepRun::count())->toBe(2);
});

test('first-ever run with no prior runs dispatches without --force', function () {
    Bus::fake();
    makeSite('https://a/sites/X', 'X');

    // No existing LabelSweepRun rows — interval guard should let it run.
    $this->artisan('sensitivity:sweep')->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

test('applies exclusions by writing entry but preserves site row', function () {
    Bus::fake();
    SiteExclusion::create(['pattern' => '/sites/contentTypeHub']);
    makeSite('https://a/sites/contentTypeHub', 'Hub');
    makeSite('https://a/sites/Normal', 'Normal');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    // Excluded sites are NOT deleted — preserves permissions/audit history.
    expect(SharePointSite::where('url', 'https://a/sites/contentTypeHub')->count())->toBe(1);
    expect(SharePointSite::where('url', 'https://a/sites/Normal')->count())->toBe(1);

    $run = LabelSweepRun::latest('id')->first();
    expect($run->entries()->where('action', 'skipped_excluded')->count())->toBe(1);
});

test('matches rules by priority ascending', function () {
    Bus::fake();
    LabelRule::create(['prefix' => 'EXT', 'label_id' => 'ext-lbl', 'priority' => 1]);
    LabelRule::create(['prefix' => 'E', 'label_id' => 'e-lbl', 'priority' => 2]);
    makeSite('https://a/sites/EXTTest', 'EXTTest');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => collect($batch->jobs)
        ->contains(fn (ApplySiteLabelJob $job) => $job->labelId === 'ext-lbl'));
});

test('falls back to default label when no rule matches', function () {
    Bus::fake();
    LabelRule::create(['prefix' => 'ZZZ', 'label_id' => 'zzz-lbl', 'priority' => 1]);
    makeSite('https://a/sites/Other', 'Other');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => collect($batch->jobs)
        ->contains(fn (ApplySiteLabelJob $job) => $job->labelId === 'default-lbl'));
});

test('batches one job per unlabeled site', function () {
    Bus::fake();
    makeSite('https://a/sites/S1', 'S1');
    makeSite('https://a/sites/S2', 'S2');

    $label = SensitivityLabel::create(['label_id' => 'x', 'name' => 'X', 'protection_type' => 'none']);
    makeSite('https://a/sites/S3', 'S3', $label->id);

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);

    $run = LabelSweepRun::latest('id')->first();
    expect($run->entries()->where('action', 'skipped_labeled')->count())->toBe(1);
});

test('pre-flight health failure marks run as failed and returns FAILURE', function () {
    Bus::fake();
    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('health')->andThrow(new \App\Services\Exceptions\BridgeUnavailableException('down'));
    $this->app->instance(BridgeClient::class, $mock);

    // Non-zero exit code surfaces to schedule:run monitoring.
    $this->artisan('sensitivity:sweep', ['--force' => true])->assertFailed();

    $run = LabelSweepRun::latest('id')->first();
    expect($run->status)->toBe(\App\Enums\SweepRunStatus::Failed);
    expect($run->error_message)->toContain('down');
    Bus::assertNothingBatched();
});

test('dry-run flag does not batch apply jobs', function () {
    Bus::fake();
    LabelRule::create(['prefix' => 'E', 'label_id' => 'e', 'priority' => 1]);
    makeSite('https://a/sites/Example', 'Example');

    $this->artisan('sensitivity:sweep', ['--force' => true, '--dry-run' => true])->assertSuccessful();

    Bus::assertNothingBatched();

    $run = LabelSweepRun::latest('id')->first();
    expect($run->entries()->count())->toBe(1);
});

test('filters to /sites/ and /teams/ urls only', function () {
    Bus::fake();
    makeSite('https://a/portals/Hub', 'Portal');
    makeSite('https://a/sites/Yes', 'SiteYes');
    makeSite('https://a/teams/Yes', 'TeamsYes');

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
});

test('when zero apply jobs pending, no batch is created', function () {
    Bus::fake();
    $label = SensitivityLabel::create(['label_id' => 'x', 'name' => 'X', 'protection_type' => 'none']);
    makeSite('https://a/sites/Labeled', 'Labeled', $label->id);

    $this->artisan('sensitivity:sweep', ['--force' => true])->assertSuccessful();

    Bus::assertNothingBatched();
});
