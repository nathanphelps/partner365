<?php

use App\Enums\UserRole;
use App\Models\LabelRule;
use App\Models\SensitivityLabel;
use App\Models\User;

test('labels index includes rule_count per label', function () {
    SensitivityLabel::create(['label_id' => 'lbl1', 'name' => 'X', 'protection_type' => 'none']);
    SensitivityLabel::create(['label_id' => 'lbl2', 'name' => 'Y', 'protection_type' => 'none']);
    LabelRule::create(['prefix' => 'A', 'label_id' => 'lbl1', 'priority' => 1]);
    LabelRule::create(['prefix' => 'B', 'label_id' => 'lbl1', 'priority' => 2]);

    $viewer = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('sensitivity-labels.index'))
        ->assertInertia(fn ($p) => $p->component('sensitivity-labels/Index')
            ->where('labels.data', function ($data) {
                foreach ($data as $label) {
                    expect($label)->toHaveKey('rule_count');
                    expect($label['rule_count'])->toBeInt();
                    if ($label['label_id'] === 'lbl1') {
                        expect($label['rule_count'])->toBe(2);
                    }
                    if ($label['label_id'] === 'lbl2') {
                        expect($label['rule_count'])->toBe(0);
                    }
                }

                return true;
            })
        );
});
