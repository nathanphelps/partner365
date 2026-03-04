<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'policy_config', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'policy_config' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
