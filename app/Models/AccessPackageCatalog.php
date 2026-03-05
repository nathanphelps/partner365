<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPackageCatalog extends Model
{
    use HasFactory;

    protected $fillable = [
        'graph_id', 'display_name', 'description', 'is_default', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function accessPackages(): HasMany
    {
        return $this->hasMany(AccessPackage::class, 'catalog_id');
    }
}
