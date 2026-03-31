<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ModuleWriterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessModuleAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300; // Un temps généreux pour l'écriture et les migrations

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    public string $action;
    public array $mcpResult;

    /**
     * Create a new job instance.
     *
     * @param string $action ('generate', 'edit', 'delete')
     * @param array $mcpResult
     */
    public function __construct(string $action, array $mcpResult)
    {
        $this->action = $action;
        $this->mcpResult = $mcpResult;
    }

    /**
     * Execute the job.
     */
    public function handle(ModuleWriterService $writer): void
    {
        Log::info("🚀 Démarrage du Job ProcessModuleAction pour l'action : {$this->action}");

        try {
            if ($this->action === 'delete') {
                $writeResult = $writer->processDeleteResult($this->mcpResult);
            } else {
                // Pour 'generate' et 'edit', le traitement est le même dans ModuleWriterService
                $writeResult = $writer->processModuleResult($this->mcpResult);
            }

            if (!$writeResult['success']) {
                Log::error("❌ Échec lors du traitement de l'action {$this->action}", [
                    'errors' => $writeResult['errors']
                ]);
                throw new Exception("L'action module '{$this->action}' a échouée ou a été réalisée partiellement.");
            }

            Log::info("✅ Job ProcessModuleAction terminé avec succès", [
                'action' => $this->action,
                'files_affected' => $writeResult['files_written'] ?? ($writeResult['files_deleted'] ?? [])
            ]);

        } catch (Exception $e) {
            Log::error("💥 Erreur fatale dans ProcessModuleAction", [
                'action' => $this->action,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
