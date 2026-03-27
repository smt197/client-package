<?php

namespace App\Http\Controllers;

use App\Ai\Tools\McpTool;
use App\Services\ModuleWriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;

class OrchestratorController extends Controller
{
    private string $mcpServerUrl;
    private ?string $mcpSessionId = null;

    public function __construct()
    {
        $this->mcpServerUrl = env('MCP_SERVER_URL', 'http://boost.192.168.1.10.sslip.io:8002/api/mcp');
    }

    public function generateFromAngular(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:5000',
        ]);

        $prompt = $request->input('prompt');

        // 1. Initialiser la session MCP (obligatoire avant tout appel)
        $this->initializeMcpSession();

        // 2. Récupérer les outils depuis le serveur Docker MCP
        $mcpToolsList = $this->getMcpTools();

        if (empty($mcpToolsList)) {
            return response()->json(['success' => false, 'error' => 'Aucun outil MCP disponible.'], 503);
        }

        // 3. Transformer les outils MCP en implémentations du contrat Tool du SDK
        $aiTools = [];
        $registeredToolNames = [];
        $moduleWriterService = new ModuleWriterService();

        foreach ($mcpToolsList as $mcpTool) {
            $toolName = $mcpTool['name'];

            // Éviter les doublons
            if (in_array($toolName, $registeredToolNames)) {
                continue;
            }

            $aiTools[] = new McpTool(
                toolName: $toolName,
                toolDescription: $mcpTool['description'] ?? 'MCP Tool',
                inputSchema: $mcpTool['inputSchema'] ?? [
                    'type' => 'object',
                    'properties' => []
                ],
                handler: fn(array $arguments) => $this->callMcpToolAndProcess(
                    $toolName,
                    $arguments,
                    $moduleWriterService
                )
            );

            $registeredToolNames[] = $toolName;
        }

        Log::info("🛠️ Outils MCP chargés pour l'Agent : ", $registeredToolNames);

        // 4. Créer un agent anonyme avec les outils MCP et lui soumettre le prompt
        $systemPrompt = 'Tu es un architecte logiciel Laravel expert. '
            . 'Tu as accès à des outils externes (MCP) pour interagir avec le projet cible. '
            . 'Tu DOIS utiliser les outils pour obtenir les informations réelles avant de répondre. '
            . 'Réponds toujours en français et de manière précise.';

        $agent = new AnonymousAgent(
            instructions: $systemPrompt,
            messages: [],
            tools: $aiTools
        );

        $response = $agent->prompt(
            prompt: $prompt,
            provider: 'openai',
            model: env('OLLAMA_MODEL', 'qwen2.5:7b'),
            timeout: 600
        );

        $textResponse = (string) $response;

        if (empty(trim($textResponse))) {
            return response()->json([
                'success' => false,
                'error' => 'La réponse textuelle est vide.',
                'raw_response' => json_decode(json_encode($response), true),
            ]);
        }

        return response()->json([
            'success'     => true,
            'ai_response' => $textResponse,
        ]);
    }

    /**
     * Appelle un outil MCP et traite le résultat (écriture locale si generate-module).
     */
    private function callMcpToolAndProcess(string $name, array $arguments, ModuleWriterService $writer): mixed
    {
        $result = $this->callMcpTool($name, $arguments);

        // Si c'est le tool generate-module, intercepter et écrire les fichiers localement
        if ($name === 'generate-module' && is_array($result)) {
            $textContent = $result['content'][0]['text'] ?? null;

            if ($textContent) {
                $moduleData = json_decode($textContent, true);

                if (is_array($moduleData) && isset($moduleData['files'])) {
                    Log::info('🏗️ Interception generate-module: écriture des fichiers dans client-package');

                    $writeResult = $writer->processModuleResult($moduleData);

                    Log::info('📦 Résultat écriture module:', $writeResult);

                    // Retourner un résumé lisible pour l'IA
                    $summary = $writeResult['success']
                        ? "✅ Module écrit avec succès dans client-package!\n"
                        : "⚠️ Module partiellement écrit.\n";

                    $summary .= "Fichiers créés:\n";
                    foreach ($writeResult['files_written'] as $file) {
                        $summary .= "  - {$file}\n";
                    }

                    if (! empty($writeResult['errors'])) {
                        $summary .= "\nErreurs:\n";
                        foreach ($writeResult['errors'] as $error) {
                            $summary .= "  - {$error}\n";
                        }
                    }

                    return $summary;
                }
            }
        }

        // Si c'est le tool delete-module, intercepter et supprimer les fichiers localement
        if ($name === 'delete-module' && is_array($result)) {
            $textContent = $result['content'][0]['text'] ?? null;

            if ($textContent) {
                $moduleData = json_decode($textContent, true);

                if (is_array($moduleData) && isset($moduleData['files_to_delete'])) {
                    Log::info('🗑️ Interception delete-module: suppression des fichiers dans client-package');

                    $deleteResult = $writer->processDeleteResult($moduleData);

                    Log::info('📦 Résultat suppression module:', $deleteResult);

                    // Retourner un résumé lisible pour l'IA
                    $summary = $deleteResult['success']
                        ? "✅ Module supprimé avec succès de client-package!\n"
                        : "⚠️ Module partiellement supprimé.\n";

                    $summary .= "Fichiers supprimés:\n";
                    foreach ($deleteResult['files_deleted'] as $file) {
                        $summary .= "  - {$file}\n";
                    }

                    if (! empty($deleteResult['errors'])) {
                        $summary .= "\nErreurs:\n";
                        foreach ($deleteResult['errors'] as $error) {
                            $summary .= "  - {$error}\n";
                        }
                    }

                    return $summary;
                }
            }
        }

        return $result;
    }

    /**
     * Initialise la session MCP et récupère le session ID via le header.
     */
    private function initializeMcpSession(): void
    {
        $response = Http::post($this->mcpServerUrl, [
            'jsonrpc' => '2.0',
            'id'      => 0,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => [],
                'clientInfo'      => [
                    'name'    => 'client-package-orchestrator',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        $this->mcpSessionId = $response->header('MCP-Session-Id');
    }

    /**
     * Interroge le serveur MCP pour lister les outils disponibles.
     */
    private function getMcpTools(): array
    {
        return $this->mcpRequest('tools/list', [], 1)->json('result.tools') ?? [];
    }

    /**
     * Appelle un outil MCP précis avec les arguments fournis par l'IA.
     */
    private function callMcpTool(string $name, array $arguments): mixed
    {
        return $this->mcpRequest('tools/call', [
            'name'      => $name,
            'arguments' => $arguments,
        ], 2)->json('result');
    }

    /**
     * Effectue une requête JSON-RPC vers le serveur MCP, avec le session ID si présent.
     */
    private function mcpRequest(string $method, array $params = [], int $id = 1): \Illuminate\Http\Client\Response
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        if ($this->mcpSessionId) {
            $headers['MCP-Session-Id'] = $this->mcpSessionId;
        }

        $body = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if (! empty($params)) {
            $body['params'] = $params;
        }

        return Http::withHeaders($headers)
            ->timeout(600)
            ->post($this->mcpServerUrl, $body);
    }
}

