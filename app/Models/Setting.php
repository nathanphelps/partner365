<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    use HasUlids;

    protected $fillable = ['group', 'key', 'value', 'encrypted'];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    public static function get(string $group, string $key, mixed $fallback = null): mixed
    {
        $setting = static::where('group', $group)->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $fallback;
        }

        return $setting->encrypted ? Crypt::decryptString($setting->value) : $setting->value;
    }

    public static function set(string $group, string $key, ?string $value, bool $encrypted = false): void
    {
        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $encrypted && $value !== null ? Crypt::encryptString($value) : $value,
                'encrypted' => $encrypted,
            ]
        );
    }

    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->mapWithKeys(fn (self $s) => [
                $s->key => $s->encrypted ? Crypt::decryptString($s->value) : $s->value,
            ])
            ->toArray();
    }
}
