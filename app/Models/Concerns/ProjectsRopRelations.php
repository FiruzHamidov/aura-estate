<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\GroupAccess\GroupDataProjection;

/** Keep loaded cross-group references out of a permitted card's JSON. */
trait ProjectsRopRelations
{
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->hasRole('rop')) {
            return $attributes;
        }

        return app(GroupDataProjection::class)->attributes($this, $attributes, $actor);
    }

    public function relationsToArray()
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->hasRole('rop')) {
            return parent::relationsToArray();
        }

        return app(GroupDataProjection::class)->relations($this, $actor);
    }
}
