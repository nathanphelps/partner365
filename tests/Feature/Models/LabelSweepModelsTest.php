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
    LabelRule::factory()->create(['prefix' => 'b', 'priority' => 1000]);
    LabelRule::factory()->create(['prefix' => 'a', 'priority' => 999]);
    $ordered = LabelRule::orderBy('priority')->whereIn('prefix', ['a', 'b'])->pluck('prefix')->all();
    expect($ordered)->toBe(['a', 'b']);
});
