<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    protected $fillable = [
        'slug', 'name', 'tagline', 'logo_path',
        'primary_color', 'secondary_color', 'accent_color',
        'address', 'phone', 'email', 'timezone', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /** Single tenant per ora — Le Cercle è id=1 */
    public static function current(): self
    {
        return static::firstOrFail();
    }
}
