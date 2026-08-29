<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentProgram extends Model
{
    use \App\Models\Concerns\NormalizesVerificationDate;

    protected $fillable = ['new_building_id', 'title', 'type', 'bank_name', 'currency', 'scope', 'calculation_method', 'period_months', 'term_min_months', 'term_max_months', 'min_down_percent', 'annual_rate', 'upfront_fee_percent', 'upfront_fee_fixed', 'min_principal', 'max_principal', 'fees_verified', 'valid_from', 'valid_until', 'conditions', 'source_url', 'confirmation_reference', 'data_verified_at', 'verified_by', 'created_by', 'publication_status', 'version'];

    protected $casts = ['min_down_percent' => 'decimal:2', 'annual_rate' => 'decimal:3', 'upfront_fee_percent' => 'decimal:2', 'upfront_fee_fixed' => 'decimal:2', 'min_principal' => 'decimal:2', 'max_principal' => 'decimal:2', 'fees_verified' => 'boolean', 'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'data_verified_at' => 'datetime', 'version' => 'integer', 'period_months' => 'integer', 'term_min_months' => 'integer', 'term_max_months' => 'integer'];

    public function newBuilding(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }

    public function blocks(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(NewBuildingBlock::class, 'payment_program_blocks', 'payment_program_id', 'block_id');
    }

    public function units(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(DeveloperUnit::class, 'payment_program_units', 'payment_program_id', 'unit_id');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('publication_status', 'published')->whereNotNull('data_verified_at')->whereNotNull('verified_by')
            ->where('valid_from', '<=', now()->toDateString())->where('valid_until', '>=', now()->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('new_building_id')->orWhereHas('newBuilding', fn (Builder $building) => $building->published()));
    }
}
