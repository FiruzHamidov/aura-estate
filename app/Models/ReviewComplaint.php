<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewComplaint extends Model
{
    protected $fillable = ['review_id', 'user_id', 'reason', 'status', 'version', 'resolved_by', 'resolved_at', 'resolution'];

    protected $casts = ['version' => 'integer', 'resolved_at' => 'datetime'];

    public function review(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
