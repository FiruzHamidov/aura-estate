<?php

namespace App\Services\Residential;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Normalized image coordinates only: never accept SVG, markup, or executable geometry. */
final class PlanRegions
{
    public function validate(array $regions, string $key, array $allowedIds): array
    {
        $data = Validator::make(['regions' => $regions], [
            'regions' => 'array|max:500', 'regions.*' => 'array:'.$key.',points',
            'regions.*.'.$key => 'required|integer|distinct', 'regions.*.points' => 'required|array|min:3|max:100',
            'regions.*.points.*' => 'required|array|size:2', 'regions.*.points.*.*' => 'required|numeric|between:0,100',
        ])->validate()['regions'];
        foreach ($data as $region) {
            if (! in_array((int) $region[$key], $allowedIds, true)) {
                throw ValidationException::withMessages(['regions' => 'Область ссылается на объект вне выбранного корпуса, подъезда или этажа.']);
            }
            $points = $region['points'];
            $area = 0;
            foreach ($points as $index => $point) {
                $next = $points[($index + 1) % count($points)];
                $area += $point[0] * $next[1] - $next[0] * $point[1];
            }
            if (abs($area) < .001) {
                throw ValidationException::withMessages(['regions' => 'Область должна иметь ненулевую площадь.']);
            }
        }

        return $data;
    }
}
