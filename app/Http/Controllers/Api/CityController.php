<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\City;


class CityController extends Controller
{
    public function getCities(Request $request, $id)
    {
        $cities = City::query()
            ->where('state_id', $id)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($cities->isEmpty()) {
            return response()->json([
                'status' => 404,
                "data" => [],
                // "total"=>count($cities)
            ]);
        }

        return response()->json([
            'status' => 200,
            "data" => $cities,
            // "total"=>count($cities)
        ]);
    }
}
