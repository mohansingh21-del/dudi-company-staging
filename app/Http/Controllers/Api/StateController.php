<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Traits\ApiResponse;
use App\Models\State;
use Throwable;
use Illuminate\Support\Facades\Log;

class StateController extends Controller
{
    public function getStates()
    {
        try {
            $states = State::query()
                ->where('country_id', 101)
                ->orderBy('name')
                ->get(['id', 'name']);

            return response()->json([
                'status' => 200,
                'message' => 'States fetched successfully',
                'data' => $states,
                // 'total' => count($states),
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error fetching states', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'Internal server error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
