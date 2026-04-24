<?php

namespace App\Enums;

/**
 * Lifecycle states for a sensitivity-label sweep run.
 *
 * String values are persisted to `label_sweep_runs.status`; do not rename.
 */
enum SweepRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case PartialFailure = 'partial_failure';
    case Failed = 'failed';
    case Aborted = 'aborted';

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
