<?php

namespace App\Http\Controllers;

use App\Services\TradeCopier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    /**
     * Manually close an open position at the current midpoint.
     */
    public function close(Request $request, TradeCopier $copier): JsonResponse
    {
        $assetId = $request->input('asset_id', '');
        if (! $assetId) {
            return response()->json(['error' => 'Missing asset_id'], 400);
        }

        $result = $copier->closePosition($assetId);
        $status = isset($result['ok']) ? 200 : 400;

        return response()->json($result, $status);
    }
}
