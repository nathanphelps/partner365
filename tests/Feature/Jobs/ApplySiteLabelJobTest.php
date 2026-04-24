<?php

use App\Enums\SweepEntryAction;
use App\Enums\SweepRunStatus;
use App\Jobs\ApplySiteLabelJob;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Services\BridgeClient;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnknownException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

function makeRun(): LabelSweepRun
{
    return LabelSweepRun::factory()->create(['status' => SweepRunStatus::Running]);
}

test('job writes applied entry and updates SharePointSite on success', function () {
    $run = makeRun();
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'X', 'protection_type' => 'none']);

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')
        ->once()
        ->with('https://a/sites/x', 'lbl', false)
        ->andReturn(new SetLabelResult('https://a/sites/x', 'lbl', fastPath: false));
    $this->app->instance(BridgeClient::class, $mock);

    SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'X',
        'url' => 'https://a/sites/x',
        'sensitivity_label_id' => null,
        'synced_at' => now(),
    ]);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    expect(LabelSweepRunEntry::count())->toBe(1);
    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe(SweepEntryAction::Applied);
    expect($entry->label_id)->toBe('lbl');

    $updated = SharePointSite::where('url', 'https://a/sites/x')->first();
    expect($updated->sensitivity_label_id)->toBe($label->id);
});

test('when label GUID is not in local catalog, entry is applied but site not mirrored (logs warning)', function () {
    $run = makeRun();
    // Note: no SensitivityLabel row for 'orphan-lbl'.

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')
        ->andReturn(new SetLabelResult('https://a/sites/orphan', 'orphan-lbl', fastPath: false));
    $this->app->instance(BridgeClient::class, $mock);

    SharePointSite::create([
        'site_id' => 'site-orphan',
        'display_name' => 'Orphan',
        'url' => 'https://a/sites/orphan',
        'sensitivity_label_id' => null,
        'synced_at' => now(),
    ]);

    Log::spy();

    (new ApplySiteLabelJob($run->id, 'https://a/sites/orphan', 'Orphan', 'orphan-lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe(SweepEntryAction::Applied);

    // Silent no-op produces a warning breadcrumb so operators can diagnose stale catalogs.
    Log::shouldHaveReceived('warning')
        ->with(\Mockery::pattern('/not in local catalog/'), \Mockery::any())
        ->once();
});

test('job writes skipped_labeled on 409 and does not retry', function () {
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeLabelConflictException('conflict'));
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe(SweepEntryAction::SkippedLabeled);
});

test('job writes failed entry with error_code=not_found on 404', function () {
    $run = makeRun();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeSiteNotFoundException('gone', 'not_found', 'r'));
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe(SweepEntryAction::Failed);
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
    expect($entry->action)->toBe(SweepEntryAction::Failed);
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
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Aborted]);

    $mock = mock(BridgeClient::class);
    $mock->shouldNotReceive('setLabel');
    $this->app->instance(BridgeClient::class, $mock);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))->handle($mock);

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe(SweepEntryAction::SkippedAborted);
});

test('failed() handler writes failed entry when no prior entry exists', function () {
    $run = makeRun();

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))
        ->failed(new BridgeThrottleException('retry exhausted', 'throttle', 'r'));

    $entry = LabelSweepRunEntry::first();
    expect($entry->action)->toBe(SweepEntryAction::Failed);
    expect($entry->error_code)->toBe('throttle');
});

test('failed() is idempotent — does not duplicate an existing entry', function () {
    $run = makeRun();

    // Simulate handle() having already written an entry on a prior attempt's terminal branch.
    LabelSweepRunEntry::create([
        'label_sweep_run_id' => $run->id,
        'site_url' => 'https://a/sites/x',
        'site_title' => 'X',
        'action' => SweepEntryAction::Failed,
        'error_code' => 'network',
        'processed_at' => now(),
    ]);

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))
        ->failed(new \RuntimeException('after the fact'));

    expect(LabelSweepRunEntry::count())->toBe(1);
});

test('failed() with non-Bridge exception uses error_code=unknown', function () {
    $run = makeRun();

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))
        ->failed(new \RuntimeException('boom'));

    $entry = LabelSweepRunEntry::first();
    expect($entry->error_code)->toBe('unknown');
});

test('failed() with BridgeUnknownException propagates that code', function () {
    $run = makeRun();

    (new ApplySiteLabelJob($run->id, 'https://a/sites/x', 'X', 'lbl', null))
        ->failed(new BridgeUnknownException('weird', 'weird-code', 'r'));

    $entry = LabelSweepRunEntry::first();
    expect($entry->error_code)->toBe('weird-code');
});
