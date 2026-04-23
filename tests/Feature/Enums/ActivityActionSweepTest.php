<?php

use App\Enums\ActivityAction;

test('sweep-related activity actions exist', function () {
    expect(ActivityAction::LabelApplied->value)->toBe('label_applied');
    expect(ActivityAction::RuleChanged->value)->toBe('rule_changed');
    expect(ActivityAction::ExclusionChanged->value)->toBe('exclusion_changed');
    expect(ActivityAction::SweepRan->value)->toBe('sweep_ran');
    expect(ActivityAction::SweepAborted->value)->toBe('sweep_aborted');
});
