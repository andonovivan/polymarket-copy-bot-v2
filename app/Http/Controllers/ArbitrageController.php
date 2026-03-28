<?php

namespace App\Http\Controllers;

use App\Services\ArbitrageScanner;
use Illuminate\Http\JsonResponse;

class ArbitrageController extends Controller
{
    public function index(ArbitrageScanner $scanner): JsonResponse
    {
        $opportunities = $scanner->scan();

        return response()->json([
            'opportunities' => $opportunities,
            'total' => count($opportunities),
        ]);
    }
}
