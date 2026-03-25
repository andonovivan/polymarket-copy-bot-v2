<?php

namespace App\Http\Controllers;

use App\Models\BotMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalPauseController extends Controller
{
    /**
     * Toggle global bot pause state.
     */
    public function toggle(Request $request): JsonResponse
    {
        $paused = (bool) $request->input('paused', true);

        BotMeta::setValue('global_paused', $paused ? '1' : '0');

        return response()->json([
            'ok' => true,
            'global_paused' => $paused,
        ]);
    }
}
