<?php

namespace App\Http\Controllers;

use App\Models\PnlSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PnlChartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->input('period', 'ALL');

        $cutoff = match ($period) {
            '1D' => now()->subDay(),
            '1W' => now()->subWeek(),
            '1M' => now()->subMonth(),
            default => null,
        };

        $query = PnlSnapshot::orderBy('recorded_at');
        if ($cutoff) {
            $query->where('recorded_at', '>=', $cutoff);
        }

        $snapshots = $query->get();

        return response()->json([
            'points' => $snapshots->map(fn ($s) => [
                'ts' => $s->recorded_at->timestamp,
                'combined' => $s->combined_pnl,
                'realized' => $s->realized_pnl,
                'unrealized' => $s->unrealized_pnl,
            ])->values()->all(),
            'period' => $period,
        ]);
    }
}
