<?php

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\PartnerTemplate;
use App\Models\User;

test('updating a template logs TemplateUpdated', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $template = PartnerTemplate::factory()->create();

    $this->actingAs($user)->put(route('templates.update', $template), [
        'name' => 'Updated Template',
        'policy_config' => [
            'mfa_trust_enabled' => true,
            'device_trust_enabled' => false,
            'b2b_inbound_enabled' => true,
            'b2b_outbound_enabled' => true,
            'direct_connect_inbound_enabled' => false,
            'direct_connect_outbound_enabled' => false,
        ],
    ]);

    $log = ActivityLog::where('action', ActivityAction::TemplateUpdated)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->subject_id)->toBe($template->id);
    expect($log->details['name'])->toBe('Updated Template');
});

test('deleting a template logs TemplateDeleted', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $template = PartnerTemplate::factory()->create();
    $templateName = $template->name;

    $this->actingAs($user)->delete(route('templates.destroy', $template));

    $log = ActivityLog::where('action', ActivityAction::TemplateDeleted)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->details['name'])->toBe($templateName);
});
