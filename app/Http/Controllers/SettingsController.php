<?php

namespace App\Http\Controllers;

use App\Services\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * GET /api/settings — Return all configurable settings with values, defaults, and metadata.
     */
    public function index(): JsonResponse
    {
        return response()->json(['settings' => Setting::all()]);
    }

    /**
     * PUT /api/settings — Bulk-update settings.
     * Accepts { key: value, ... }. Null/empty values clear the override.
     */
    public function update(Request $request): JsonResponse
    {
        $input = $request->input('settings', []);
        $updated = [];
        $errors = [];

        foreach ($input as $key => $value) {
            if (! isset(Setting::SCHEMA[$key])) {
                $errors[$key] = 'Unknown setting';

                continue;
            }

            $schema = Setting::SCHEMA[$key];

            // Allow null for nullable fields, or to clear an override.
            if ($value === null || $value === '') {
                Setting::set($key, null);
                $updated[] = $key;

                continue;
            }

            // Validate by type.
            $error = $this->validate_setting($key, $value, $schema);
            if ($error) {
                $errors[$key] = $error;

                continue;
            }

            // Cast booleans to "1"/"0" for BotMeta string storage.
            if ($schema['type'] === 'bool') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

            Setting::set($key, $value);
            $updated[] = $key;
        }

        if (! empty($errors)) {
            return response()->json([
                'ok' => false,
                'errors' => $errors,
                'updated' => $updated,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'updated' => $updated,
            'settings' => Setting::all(),
        ]);
    }

    /**
     * DELETE /api/settings/{key} — Reset a single setting to its env default.
     */
    public function destroy(string $key): JsonResponse
    {
        if (! isset(Setting::SCHEMA[$key])) {
            return response()->json(['error' => 'Unknown setting'], 404);
        }

        Setting::set($key, null);

        return response()->json([
            'ok' => true,
            'settings' => Setting::all(),
        ]);
    }

    private function validate_setting(string $key, mixed $value, array $schema): ?string
    {
        $type = $schema['type'];

        if ($type === 'float') {
            if (! is_numeric($value)) {
                return 'Must be a number';
            }
            if ((float) $value < 0) {
                return 'Must be non-negative';
            }
        } elseif ($type === 'int') {
            if (! is_numeric($value) || (int) $value != $value) {
                return 'Must be an integer';
            }
            if ((int) $value < 0) {
                return 'Must be non-negative';
            }
        } elseif ($type === 'bool') {
            // Accept anything truthy/falsy.
        }

        return null;
    }
}
