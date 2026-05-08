<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SAPService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BankPageController extends Controller
{
    protected $sapService;

    public function __construct(SAPService $sapService)
    {
        $this->sapService = $sapService;
    }

    /**
     * Vista principal: muestra los primeros 20 registros y el total.
     */
    public function index(Request $request)
    {
        $records    = [];
        $totalCount = 0;
        $error      = null;
        $hasFilters = $request->filled('account_code') || $request->filled('date_from') || $request->filled('account_name');

        if ($hasFilters) {
            $token = session('b1_token');
            if (!$token) {
                return redirect()->route('login')->withErrors(['error' => 'Sesión expirada. Por favor inicie sesión nuevamente.']);
            }

            $baseUrl  = config('services.sap.url') ?? env('SAP_SERVICE_LAYER_URL');
            $endpoint = "{$baseUrl}/BankPages";

            $filterParts = [];
            if ($request->filled('account_code')) {
                $filterParts[] = "AccountCode eq '{$request->account_code}'";
            }
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $filterParts[] = "DueDate ge '{$request->date_from}' and DueDate le '{$request->date_to}'";
            }
            if ($request->filled('account_name')) {
                $filterParts[] = "substringof('{$request->account_name}', AccountName)";
            }

            $params = [
                '$top'         => 20,
                '$skip'        => 0,
                '$orderby'     => 'Sequence asc',
                '$inlinecount' => 'allpages',
            ];
            if ($filterParts) {
                $params['$filter'] = implode(' and ', $filterParts);
            }

            try {
                $response = Http::withOptions(['verify' => false])
                    ->timeout(15)  // Falla rápido — si SAP no responde en 15s, la sesión expiró
                    ->withCookies(['B1SESSION' => $token], parse_url($baseUrl, PHP_URL_HOST))
                    ->get($endpoint, $params);

                if ($response->status() === 401 || $response->status() === 403) {
                    // Sesión SAP expirada — limpiar y redirigir a login
                    session()->forget('b1_token');
                    return redirect()->route('login')
                        ->withErrors(['error' => 'Sesión SAP expirada (30 min). Por favor inicie sesión nuevamente.']);
                }

                if ($response->successful()) {
                    $json       = $response->json();
                    $records    = $json['value'] ?? [];
                    $totalCount = (int) ($json['odata.count'] ?? $json['@odata.count'] ?? count($records));
                } else {
                    $error = $response->json()['error']['message']['value'] ?? 'Error al consultar SAP.';
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Timeout = sesión SAP expirada o servidor caído
                session()->forget('b1_token');
                return redirect()->route('login')
                    ->withErrors(['error' => 'No se pudo conectar con SAP. La sesión puede haber expirado. Por favor inicie sesión nuevamente.']);
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        return view('bankpages.index', compact('records', 'error', 'totalCount'));
    }

    /**
     * Inicia la limpieza masiva en background.
     */
    public function cleanup(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $token = session('b1_token');
        if (!$token) {
            return back()->withErrors(['error' => 'Sesión de SAP no válida o expirada.']);
        }

        $filters = [
            'account_code' => $request->account_code,
            'date_from'    => $request->date_from,
            'date_to'      => $request->date_to,
            'account_name' => $request->account_name,
        ];

        // Clave única para rastrear el progreso de ESTA limpieza
        $cacheKey = 'sap_cleanup_' . md5(json_encode($filters) . time());
        Cache::put($cacheKey, ['status' => 'running', 'deleted' => 0, 'failed' => 0], now()->addHours(4));

        \App\Jobs\ProcessMassCleanupJob::dispatch($filters, $token, $cacheKey);

        session(['cleanup_cache_key' => $cacheKey]);

        return redirect()->route('bankpages.index', [
            'account_code' => $request->account_code,
            'account_name' => $request->account_name,
            'date_from'    => $request->date_from,
            'date_to'      => $request->date_to,
        ])->with('cleanup_started', true)->with('cleanup_cache_key', $cacheKey);
    }

    /**
     * Devuelve el estado actual de la limpieza (polling desde JS).
     */
    public function cleanupStatus(Request $request)
    {
        $key = $request->input('key');
        if (!$key) {
            return response()->json(['status' => 'not_found']);
        }

        $status  = Cache::get($key . ':status',  'not_found');
        $deleted = (int) Cache::get($key . ':deleted', 0);
        $failed  = (int) Cache::get($key . ':failed',  0);
        $total   = (int) Cache::get($key . ':total',   0);
        $error   = Cache::get($key . ':error');

        return response()->json(compact('status', 'deleted', 'failed', 'total', 'error'));
    }
}
