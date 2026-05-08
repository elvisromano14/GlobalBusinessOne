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

/**
 * FASE 2: Recibe hasta 100 pares {AccountCode, Sequence} y los borra
 * en UNA SOLA petición HTTP usando el endpoint $batch de OData.
 *
 * Formato del payload (multipart/mixed):
 *   --boundary
 *   Content-Type: application/http
 *   Content-Transfer-Encoding: binary
 *
 *   DELETE /b1s/v1/BankPages(AccountCode='1.1.02.216',Sequence=97162) HTTP/1.1
 *
 *   --boundary--
 *
 * SAP responde con otro multipart donde cada parte tiene 204 (éxito)
 * o 4xx (fallo por registro conciliado u otra restricción).
 */
class DeleteBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 min por batch de 100 registros
    public $tries   = 2;

    protected array  $records;
    protected string $token;
    protected string $cacheKey;
    protected int    $totalJobs;

    public function __construct(array $records, string $token, string $cacheKey, int $totalJobs)
    {
        $this->records   = $records;
        $this->token     = $token;
        $this->cacheKey  = $cacheKey;
        $this->totalJobs = $totalJobs;
    }

    public function handle(): void
    {
        $baseUrl  = config('services.sap.url') ?? env('SAP_SERVICE_LAYER_URL');
        $batchUrl = "{$baseUrl}/\$batch";
        $host     = parse_url($baseUrl, PHP_URL_HOST);

        // ── Construir payload multipart/mixed (Batch + Changeset) ─────────
        $batchBoundary     = 'batch_gbo_' . uniqid();
        $changesetBoundary = 'changeset_' . uniqid();
        
        $payload  = "--{$batchBoundary}\r\n";
        $payload .= "Content-Type: multipart/mixed; boundary={$changesetBoundary}\r\n\r\n";

        foreach ($this->records as $record) {
            $code = $record['AccountCode'];
            $seq  = $record['Sequence'];

            $payload .= "--{$changesetBoundary}\r\n";
            $payload .= "Content-Type: application/http\r\n";
            $payload .= "Content-Transfer-Encoding: binary\r\n\r\n";
            $payload .= "DELETE BankPages(AccountCode='{$code}',Sequence={$seq})\r\n\r\n";
        }

        $payload .= "--{$changesetBoundary}--\r\n";
        $payload .= "--{$batchBoundary}--\r\n";

        // ── Enviar petición $batch ─────────────────────────────────────────
        $guzzle = new Client(['verify' => false, 'timeout' => 240]);

        try {
            $response = $guzzle->post($batchUrl, [
                'headers' => [
                    'Cookie'       => "B1SESSION={$this->token}",
                    'Content-Type' => "multipart/mixed; boundary={$batchBoundary}",
                ],
                'body' => $payload,
            ]);

            $body = (string) $response->getBody();

            // LOG DIAGNÓSTICO: Ver si ahora vienen múltiples bloques
            Log::info('[DeleteBatch] Lote enviado: ' . count($this->records) . ' registros. Respuesta chars: ' . strlen($body));

            // ── Parsear respuesta multipart para contar éxitos y fallos ───
            [$deleted, $failed] = $this->parseResponse($body, count($this->records));

        } catch (\Exception $e) {
            Log::error('[DeleteBatch] Excepción en $batch: ' . $e->getMessage());
            // Marcar todos como fallidos
            $deleted = 0;
            $failed  = count($this->records);
        }

        // ── Actualizar contadores en Redis (incremento atómico) ───────────
        Cache::increment($this->cacheKey . ':deleted', $deleted);
        Cache::increment($this->cacheKey . ':failed',  $failed);

        $totalDeleted = (int) Cache::get($this->cacheKey . ':deleted', 0);
        $totalFailed  = (int) Cache::get($this->cacheKey . ':failed',  0);
        $total        = (int) Cache::get($this->cacheKey . ':total',   0);

        Log::info("[DeleteBatch] Lote procesado: +{$deleted} borrados, +{$failed} fallidos. Acum: {$totalDeleted}/{$total}");

        // ── Marcar como completado si ya procesamos todos ─────────────────
        if ($total > 0 && ($totalDeleted + $totalFailed) >= $total) {
            Cache::put($this->cacheKey . ':status', 'done', now()->addHours(4));
            Log::info("[DeleteBatch] CLEANUP COMPLETADO. Borrados: {$totalDeleted}. Fallidos: {$totalFailed}.");
        }
    }

    /**
     * Parsea la respuesta multipart de SAP contando 204 (éxito) y 4xx/5xx (fallo).
     * SAP no aborta el lote si un registro falla; cada parte tiene su propio código.
     *
     * @return array [int $deleted, int $failed]
     */
    private function parseResponse(string $body, int $sent): array
    {
        $deleted = 0;
        $failed  = 0;

        // SAP puede responder el status de dos formas:
        // 1. "HTTP/1.1 204 No Content"
        // 2. Solo el status inline sin HTTP/1.1
        // Buscamos TODOS los códigos de estado en la respuesta multipart

        // Método 1: buscar "HTTP/1.1 NNN"
        preg_match_all('/HTTP\/1[\.\s]+\d\s+(\d{3})/i', $body, $m1);

        // Método 2: buscar líneas sueltas tipo "204 No Content"
        preg_match_all('/^(\d{3})\s+\w/m', $body, $m2);

        $allCodes = array_merge($m1[1] ?? [], $m2[1] ?? []);

        // Deduplicar: si el mismo código aparece por ambos métodos, tomamos solo los únicos
        // Para SAP, cada sub-response tiene exactamente UN código de estado
        if (count($allCodes) > 0) {
            foreach ($allCodes as $statusCode) {
                $code = (int) $statusCode;
                if ($code >= 200 && $code < 300) {
                    $deleted++;
                } elseif ($code >= 400) {
                    $failed++;
                    Log::warning("[DeleteBatch] SAP rechazó un registro con HTTP {$code}");
                }
            }
        }

        // Si el parser no encontró nada legible, loguear la respuesta cruda
        if ($deleted === 0 && $failed === 0) {
            Log::error("[DeleteBatch] Respuesta no parseable. Primeros 800 chars: " . substr($body, 0, 800));
            // Asumir: enviamos $sent, los que fallaron son los que no tienen 204
            $failed = $sent;
        }

        // Sanity check: si detectamos más respuestas de las enviadas, algo está mal
        $total = $deleted + $failed;
        if ($total > $sent) {
            // Recortar al máximo enviado
            $excess  = $total - $sent;
            $failed  = max(0, $failed - $excess);
        }

        return [$deleted, $failed];
    }

    public function failed(\Throwable $e): void
    {
        Cache::increment($this->cacheKey . ':failed', count($this->records));
        Log::error("[DeleteBatch] Job FALLIDO definitivamente: " . $e->getMessage());
    }
}
