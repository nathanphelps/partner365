<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabelRule extends Model
{
    use HasFactory;

    protected $fillable = ['prefix', 'label_id', 'priority'];

    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }
}
