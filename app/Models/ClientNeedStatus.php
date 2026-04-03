<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientNeedStatus extends Model
{
    use HasFactory;

    public const SLUG_NEW = 'new';

    protected $fillable = [
        'name',
        'slug',
        'is_closed',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function needs()
    {
        return $this->hasMany(ClientNeed::class, 'status_id');
    }

    public static function defaultId(): int
    {
        $id = static::query()
            ->where('slug', static::SLUG_NEW)
            ->value('id');

        abort_unless($id, 500, 'Default client need status is not configured.');

        return (int) $id;
    }
}
