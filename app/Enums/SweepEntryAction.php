<?php

namespace App\Enums;

/**
 * Outcome of processing a single site during a sweep run.
 *
 * String values are persisted to `label_sweep_run_entries.action`; do not rename.
 * CompleteSweepRunJob aggregates counts by GROUP BY on this column, so typos
 * would cause silent data corruption — always construct via the enum.
 */
enum SweepEntryAction: string
{
    case Applied = 'applied';
    case SkippedLabeled = 'skipped_labeled';
    case SkippedExcluded = 'skipped_excluded';
    case SkippedNoMatch = 'skipped_no_match';
    case SkippedAborted = 'skipped_aborted';
    case Failed = 'failed';
}
