<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Developer extends Model
{
    protected $fillable = [
        'name','phone','under_construction_count','built_count',
        'founded_year','total_projects','logo_path','website','facebook','instagram','telegram','moderation_status','description'
    ];

    public function newBuildings(): HasMany
    {
        return $this->hasMany(NewBuilding::class);
    }
}
