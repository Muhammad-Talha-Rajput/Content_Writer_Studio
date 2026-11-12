<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseAvailable = true;

        try {
            DB::select('SELECT 1');
        } catch (Throwable $exception) {
            report($exception);
            $databaseAvailable = false;
        }

        return response()->json([
            'app' => config('app.name'),
            'status' => $databaseAvailable ? 'ok' : 'degraded',
            'checks' => ['database' => $databaseAvailable ? 'ok' : 'unavailable'],
            'checked_at' => now()->toIso8601String(),
        ], $databaseAvailable ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
