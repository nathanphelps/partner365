<?php

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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

function makeRun(): LabelSweepRun
{
    return LabelSweepRun::factory()->create(['status' => 'running']);
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
    expect($entry->action)->toBe('applied');
    expect($entry->label_id)->toBe('lbl');

    $updated = SharePointSite::where('url', 'https://a/sites/x')->first();
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
