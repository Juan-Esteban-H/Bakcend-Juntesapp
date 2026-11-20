<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class LocationSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        if (! RateLimiter::attempt('nominatim-global-search', 1, fn (): bool => true, 1)) {
            abort(429, 'Espera un segundo antes de volver a buscar.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => 'JuntesApp/0.1 ('.config('app.url').')',
                    'Accept-Language' => 'es',
                ])
                ->timeout(8)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $validated['q'],
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 5,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            abort(503, 'No se pudo conectar con OpenStreetMap.');
        }

        if (! $response->successful()) {
            Log::warning('OpenStreetMap Nominatim search failed.', [
                'status' => $response->status(),
            ]);

            abort(502, 'OpenStreetMap no pudo completar la búsqueda.');
        }

        $results = $response->json();
        if (! is_array($results)) {
            abort(502, 'OpenStreetMap devolvió una respuesta inválida.');
        }

        return response()->json([
            'locations' => collect($results)
                ->filter(fn (mixed $result): bool => is_array($result)
                    && is_string($result['display_name'] ?? null)
                    && is_numeric($result['lat'] ?? null)
                    && is_numeric($result['lon'] ?? null))
                ->map(fn (array $result): array => [
                    'name' => is_string($result['name'] ?? null)
                        ? $result['name']
                        : $result['display_name'],
                    'address' => $result['display_name'],
                    'latitude' => (float) $result['lat'],
                    'longitude' => (float) $result['lon'],
                ])
                ->values(),
        ]);
    }
}
