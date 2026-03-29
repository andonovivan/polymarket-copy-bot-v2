<?php

namespace App\Http\Controllers;

use App\Services\ResolutionSniper;
use Illuminate\Http\JsonResponse;

class SnipeController extends Controller
{
    public function index(ResolutionSniper $sniper): JsonResponse
    {
        $candidates = $sniper->scan();

        return response()->json([
            'candidates' => $candidates,
            'total' => count($candidates),
        ]);
    }
}
