<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeLocation extends Model
{
    protected $fillable = ['name', 'latitude', 'longitude', 'radius_meters', 'is_active'];
    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'radius_meters' => 'integer', 'is_active' => 'boolean'];

    /** Great-circle distance in metres (haversine). */
    public function distanceTo(float $latitude, float $longitude): int
    {
        $earth = 6371000;
        $dLat = deg2rad($latitude - $this->latitude);
        $dLng = deg2rad($longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
