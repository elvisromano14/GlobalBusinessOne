<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\SAPService;
use Illuminate\Support\Facades\Log;

class DeleteSapRecordJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $sequence;
    protected $accountCode;
    protected $token;

    /**
     * Create a new job instance.
     */
    public function __construct($accountCode, $sequence, $token)
    {
        $this->accountCode = $accountCode;
        $this->sequence = $sequence;
        $this->token = $token;
    }

    public function handle(SAPService $sapService)
    {
        $success = $sapService->deleteBankPage($this->accountCode, $this->sequence, $this->token);
        if (!$success) {
            Log::error("Fallo definitivo al borrar OBNK AccountCode {$this->accountCode} Sequence {$this->sequence}.");
            throw new \Exception("SAP rechazó el borrado de la secuencia {$this->sequence}");
        } else {
            Log::info("Borrado exitoso de OBNK AccountCode {$this->accountCode} Sequence {$this->sequence}");
        }
    }
}
