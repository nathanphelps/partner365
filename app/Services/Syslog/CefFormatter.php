<?php

namespace App\Services\Syslog;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;

class CefFormatter
{
    private const SEVERITY_MAP = [
        // Low (3)
        'sync_completed' => 3,
        'sync_triggered' => 3,
        'access_review_created' => 3,
        'template_created' => 3,
        'sensitivity_labels_synced' => 3,

        // Medium (5)
        'partner_created' => 5,
        'partner_updated' => 5,
        'guest_invited' => 5,
        'guest_updated' => 5,
        'guest_enabled' => 5,
        'template_updated' => 5,
        'profile_updated' => 5,
        'user_logged_in' => 5,
        'user_logged_out' => 5,
        'access_package_created' => 5,
        'access_package_updated' => 5,
        'assignment_requested' => 5,
        'assignment_approved' => 5,
        'policy_changed' => 5,
        'access_review_decision_made' => 5,
        'access_review_completed' => 5,
        'conditional_access_policies_synced' => 5,
        'user_approved' => 5,

        // High (7)
        'partner_deleted' => 7,
        'guest_removed' => 7,
        'guest_disabled' => 7,
        'template_deleted' => 7,
        'user_deleted' => 7,
        'assignment_revoked' => 7,
        'assignment_denied' => 7,
        'access_review_remediation_applied' => 7,
        'access_package_deleted' => 7,
        'account_deleted' => 7,

        // Very High (8)
        'login_failed' => 8,
        'account_locked' => 8,
        'password_changed' => 8,
        'two_factor_disabled' => 8,
        'settings_updated' => 8,
        'user_role_changed' => 8,
        'consent_granted' => 8,
        'graph_connection_tested' => 8,
        'two_factor_enabled' => 8,
    ];

    public function format(ActivityLog $log): string
    {
        $action = $log->action->value;
        $label = $this->actionLabel($log->action);
        $severity = $this->severity($log->action);
        $username = $log->user?->name ?? 'System';
        $details = $log->details ? json_encode($log->details) : '';
        $timestamp = $log->created_at?->getTimestampMs();

        $extension = implode(' ', array_filter([
            'suser='.$this->escapeExtensionValue($username),
            $details ? 'msg='.$this->escapeExtensionValue($details) : null,
            $log->subject_type ? 'cs1='.$this->escapeExtensionValue(class_basename($log->subject_type)) : null,
            $log->subject_type ? 'cs1Label=SubjectType' : null,
            $log->subject_id ? 'cs2='.$log->subject_id : null,
            $log->subject_id ? 'cs2Label=SubjectId' : null,
            $timestamp ? 'rt='.$timestamp : null,
        ]));

        return sprintf(
            'CEF:0|%s|%s|1.0|%s|%s|%d|%s',
            $this->escapeHeaderField('Partner365'),
            $this->escapeHeaderField('Partner365'),
            $this->escapeHeaderField($action),
            $this->escapeHeaderField($label),
            $severity,
            $extension,
        );
    }

    public function severity(ActivityAction $action): int
    {
        return self::SEVERITY_MAP[$action->value] ?? 5;
    }

    private function actionLabel(ActivityAction $action): string
    {
        return str_replace('_', ' ', ucfirst($action->value));
    }

    private function escapeHeaderField(string $value): string
    {
        return str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
    }

    private function escapeExtensionValue(string $value): string
    {
        return str_replace(['\\', '|', '=', "\n"], ['\\\\', '\\|', '\\=', '\\n'], $value);
    }
}
