<?php

namespace App\Http\Controllers;

use App\Ai\Tools\McpTool;
use App\Jobs\ProcessModuleAction;
use App\Models\AiAgent;
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
            'agent_name' => 'nullable|string',
        ]);

        $prompt = $request->input('prompt');
        $agentName = $request->input('agent_name');
        
        $aiAgentModel = $agentName 
            ? AiAgent::where('name', $agentName)->orWhere('slug', $agentName)->first() 
            : null;

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
        $defaultPrompt = 'Tu es un architecte logiciel Laravel expert. '
            . 'Tu as accès à des outils externes (MCP) pour interagir avec le projet. '
            . 'SURTOUT pour "edit-module", tu DOIS impérativement utiliser un OBJET JSON structuré pour le paramètre "changes" '
            . 'avec les clés "added", "renamed" ou "modified" comme spécifié dans le schéma. Ne mets JAMAIS de texte simple dans "changes". '
            . 'Réponds toujours en français et de manière précise.';

        $systemPrompt = $aiAgentModel && !empty($aiAgentModel->instructions) 
            ? $aiAgentModel->instructions 
            : $defaultPrompt;

        $agent = new AnonymousAgent(
            instructions: $systemPrompt,
            messages: [],
            tools: $aiTools
        );

        $provider = $aiAgentModel ? $aiAgentModel->provider : 'openai';
        $model = $aiAgentModel ? $aiAgentModel->model_name : env('OLLAMA_MODEL', 'qwen2.5:7b');

        $response = $agent->prompt(
            prompt: $prompt,
            provider: $provider,
            model: $model,
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

                    ProcessModuleAction::dispatch('generate', $moduleData);
                    
                    Log::info('🚀 Tâche generate-module mise en file d\'attente Redis.');

                    return "✅ Tâche de génération de module mise en file d'attente. L'écriture se fera en arrière-plan.";
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

                    ProcessModuleAction::dispatch('delete', $moduleData);
                    
                    Log::info('🚀 Tâche delete-module mise en file d\'attente Redis.');

                    return "✅ Tâche de suppression de module mise en file d'attente. La suppression se fera en arrière-plan.";
                }
            }
        }

        // Si c'est le tool edit-module, intercepter et mettre à jour les fichiers localement
        if ($name === 'edit-module' && is_array($result)) {
            $textContent = $result['content'][0]['text'] ?? null;

            if ($textContent) {
                $moduleData = json_decode($textContent, true);

                if (is_array($moduleData) && isset($moduleData['files'])) {
                    Log::info('🔄 Interception edit-module: mise à jour des fichiers dans client-package');

                    ProcessModuleAction::dispatch('edit', $moduleData);
                    
                    Log::info('🚀 Tâche edit-module mise en file d\'attente Redis.');

                    return "✅ Tâche de modification de module mise en file d'attente. Les mises à jour se feront en arrière-plan.";
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

