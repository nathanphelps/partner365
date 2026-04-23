<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelSweepRunEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'label_sweep_run_id',
        'site_url',
        'site_title',
        'action',
        'label_id',
        'matched_rule_id',
        'error_message',
        'error_code',
        'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(LabelSweepRun::class, 'label_sweep_run_id');
    }

    public function matchedRule(): BelongsTo
    {
        return $this->belongsTo(LabelRule::class, 'matched_rule_id');
    }
}
