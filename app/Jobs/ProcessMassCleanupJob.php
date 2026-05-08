<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;

/**
 * FASE 1: Recolecta TODOS los AccountCode+Sequence de SAP en paralelo
 * usando GuzzlePool, luego despacha DeleteBatchJob por cada chunk de 100.
 *
 * Flujo:
 *   1. GET $inlinecount → saber total de registros
 *   2. GuzzlePool con skip=0,20,40... concurrente (10 a la vez) → recopilar todos los IDs
 *   3. chunk(100) → dispatch DeleteBatchJob × N (procesados por 7 workers en paralelo)
 */
class ProcessMassCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;
    public $tries   = 1;

    protected array  $filters;
    protected string $token;
    protected string $cacheKey;

    public function __construct(array $filters, string $token, string $cacheKey)
    {
        $this->filters  = $filters;
        $this->token    = $token;
        $this->cacheKey = $cacheKey;
    }

    public function handle(): void
    {
        $baseUrl  = config('services.sap.url') ?? env('SAP_SERVICE_LAYER_URL');
        $endpoint = "{$baseUrl}/BankPages";
        $host     = parse_url($baseUrl, PHP_URL_HOST);

        // --- Construir filtro OData ---
        $filterParts = [];
        if (!empty($this->filters['account_code'])) {
            $filterParts[] = "AccountCode eq '{$this->filters['account_code']}'";
        }
        if (!empty($this->filters['date_from']) && !empty($this->filters['date_to'])) {
            $filterParts[] = "DueDate ge '{$this->filters['date_from']}' and DueDate le '{$this->filters['date_to']}'";
        }
        if (!empty($this->filters['account_name'])) {
            $filterParts[] = "substringof('{$this->filters['account_name']}', AccountName)";
        }
        $filterString = implode(' and ', $filterParts);

        $guzzle = new Client([
            'verify'  => false,
            'timeout' => 30,
            'headers' => ['Cookie' => "B1SESSION={$this->token}"],
        ]);

        // ── PASO 1: Obtener el total de registros ──────────────────────────
        $countParams = http_build_query([
            '$top'         => 1,
            '$select'      => 'Sequence',
            '$inlinecount' => 'allpages',
        ]);
        if ($filterString) {
            $countParams .= '&' . http_build_query(['$filter' => $filterString]);
        }

        try {
            $countRes = $guzzle->get("{$endpoint}?{$countParams}");
            $countJson = json_decode($countRes->getBody(), true);
            $total     = (int) ($countJson['odata.count'] ?? $countJson['@odata.count'] ?? 0);
        } catch (\Exception $e) {
            Log::error("[Cleanup] No se pudo obtener el total: " . $e->getMessage());
            $this->markFailed("Error obteniendo total: " . $e->getMessage());
            return;
        }

        if ($total === 0) {
            Log::info("[Cleanup] No hay registros que borrar.");
            Cache::put($this->cacheKey . ':status',  'done',  now()->addHours(4));
            Cache::put($this->cacheKey . ':total',   0,       now()->addHours(4));
            return;
        }

        Log::info("[Cleanup] Total de registros a borrar: {$total}");
        Cache::put($this->cacheKey . ':total',   $total,     now()->addHours(4));
        Cache::put($this->cacheKey . ':deleted', 0,          now()->addHours(4));
        Cache::put($this->cacheKey . ':failed',  0,          now()->addHours(4));
        Cache::put($this->cacheKey . ':status',  'collecting', now()->addHours(4));

        // ── PASO 2: Recolección paralela de IDs ───────────────────────────
        // Calculamos todos los valores de $skip necesarios
        $skipValues = range(0, $total - 1, 20);
        $allRecords = [];
        $mutex      = new \stdClass(); // Objeto compartido para merge thread-safe en PHP

        $baseQuery = ['$top' => 20, '$select' => 'AccountCode,Sequence'];
        if ($filterString) {
            $baseQuery['$filter'] = $filterString;
        }

        $requests = function () use ($skipValues, $endpoint, $baseQuery) {
            foreach ($skipValues as $skip) {
                $query = array_merge($baseQuery, ['$skip' => $skip]);
                $url   = $endpoint . '?' . http_build_query($query);
                yield new GuzzleRequest('GET', $url);
            }
        };

        $pool = new Pool($guzzle, $requests(), [
            'concurrency' => 10, // 10 GETs simultáneos para recolección
            'fulfilled'   => function ($response) use (&$allRecords) {
                $json = json_decode($response->getBody(), true);
                foreach ($json['value'] ?? [] as $record) {
                    if (isset($record['AccountCode'], $record['Sequence'])) {
                        $allRecords[] = $record;
                    }
                }
            },
            'rejected'    => function ($reason, $index) {
                Log::warning("[Cleanup] GET fallido para skip index {$index}: " . $reason->getMessage());
            },
        ]);

        $pool->promise()->wait();

        $collected = count($allRecords);
        Log::info("[Cleanup] Recolección completa. Registros recopilados: {$collected}");

        if ($collected === 0) {
            Cache::put($this->cacheKey . ':status', 'done', now()->addHours(4));
            return;
        }

        Cache::put($this->cacheKey . ':status', 'deleting', now()->addHours(4));
        Cache::put($this->cacheKey . ':total',  $collected, now()->addHours(4));

        // ── PASO 3: Dispatch DeleteBatchJob por cada chunk de 50 ─────────
        // Con 3 workers en paralelo, estos jobs se procesan concurrentemente
        $chunks    = array_chunk($allRecords, 50);
        $totalJobs = count($chunks);

        foreach ($chunks as $index => $chunk) {
            DeleteBatchJob::dispatch($chunk, $this->token, $this->cacheKey, $totalJobs);
        }

        Log::info("[Cleanup] Despachados {$totalJobs} DeleteBatchJobs de 100 registros c/u. Workers: 7.");
    }

    public function failed(\Throwable $e): void
    {
        $this->markFailed($e->getMessage());
    }

    private function markFailed(string $msg): void
    {
        Cache::put($this->cacheKey . ':status', 'error',  now()->addHours(4));
        Cache::put($this->cacheKey . ':error',  $msg,     now()->addHours(4));
        Log::error("[Cleanup] Job FALLIDO: {$msg}");
    }
}
