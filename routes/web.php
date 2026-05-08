<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BankPageController;
use App\Http\Controllers\AuthController;

Route::get('/', function () {
    return redirect()->route('bankpages.index');
});

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::any('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['b1_session'])->group(function () {
    Route::get('/bankpages',               [BankPageController::class, 'index'])->name('bankpages.index');
    Route::post('/bankpages/cleanup',      [BankPageController::class, 'cleanup'])->name('bankpages.cleanup');
    Route::get('/bankpages/cleanup-status',[BankPageController::class, 'cleanupStatus'])->name('bankpages.cleanup.status');

    Route::get('/debug-delete/{accountCode}/{sequence}', function ($accountCode, $sequence) {
        $token = session('b1_token');
        if (!$token) return "No token";

        $sapService = app(\App\Services\SAPService::class);
        $baseUrl = config('services.sap.url') ?? env('SAP_SERVICE_LAYER_URL');
        $url = "{$baseUrl}/BankPages(AccountCode='{$accountCode}',Sequence={$sequence})";
        
        try {
            $deleteResponse = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])
                ->timeout(8)
                ->withCookies(['B1SESSION' => $token], parse_url($baseUrl, PHP_URL_HOST))
                ->delete($url);

            $getResponse = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])
                ->timeout(8)
                ->withCookies(['B1SESSION' => $token], parse_url($baseUrl, PHP_URL_HOST))
                ->get($url);

            return [
                'delete_status' => $deleteResponse->status(),
                'delete_body'   => $deleteResponse->json(),
                'still_exists_in_sap' => $getResponse->successful(),
                'get_status'    => $getResponse->status(),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'url' => $url];
        }
    });

    Route::get('/debug-pk/{accountCode}/{sequence}', function ($accountCode, $sequence) {
        $token = session('b1_token');
        if (!$token) return "No token";
        $baseUrl = config('services.sap.url') ?? env('SAP_SERVICE_LAYER_URL');
        
        $urlsToTest = [
            "Normal" => "{$baseUrl}/BankPages(AccountCode='{$accountCode}',Sequence={$sequence})",
            "Swapped" => "{$baseUrl}/BankPages(Sequence={$sequence},AccountCode='{$accountCode}')",
            "Con Espacio" => "{$baseUrl}/BankPages(AccountCode='{$accountCode}', Sequence={$sequence})",
            "Codificado" => "{$baseUrl}/BankPages(AccountCode='" . urlencode($accountCode) . "',Sequence={$sequence})",
            "Sin Comillas" => "{$baseUrl}/BankPages(AccountCode={$accountCode},Sequence={$sequence})"
        ];

        $results = [];
        foreach ($urlsToTest as $name => $url) {
            try {
                $response = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])
                    ->timeout(5)
                    ->withCookies(['B1SESSION' => $token], parse_url($baseUrl, PHP_URL_HOST))
                    ->get($url);

                $results[$name] = [
                    'status' => $response->status(),
                    'url'    => $url,
                    'found'  => $response->successful(),
                    'body'   => $response->json(),
                ];
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 0,
                    'url'    => $url,
                    'found'  => false,
                    'error'  => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    });
});
