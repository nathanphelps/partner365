<?php

namespace App\Models;

use App\Enums\AccessPackageResourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPackageResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_package_id', 'resource_type', 'resource_id',
        'resource_display_name', 'graph_id',
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => AccessPackageResourceType::class,
        ];
    }

    public function accessPackage(): BelongsTo
    {
        return $this->belongsTo(AccessPackage::class);
    }
}
