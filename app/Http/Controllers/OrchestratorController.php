<?php

namespace App\Http\Controllers;

use App\Ai\Tools\McpTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        foreach ($mcpToolsList as $mcpTool) {
            $aiTools[] = new McpTool(
                toolName: $mcpTool['name'],
                toolDescription: $mcpTool['description'] ?? '',
                inputSchema: $mcpTool['inputSchema'] ?? ['type' => 'object', 'properties' => []],
                handler: fn (array $arguments) => $this->callMcpTool($mcpTool['name'], $arguments)
            );
        }

        // 4. Créer un agent anonyme avec les outils MCP et lui soumettre le prompt
       $systemPrompt = 'Tu es un architecte logiciel Laravel expert. '
            . 'Tu as accès à des outils externes (MCP) pour interagir avec le projet cible. '
            . 'RÈGLE 1 : Tu dois utiliser les outils pour obtenir les vraies informations. '
            . 'RÈGLE 2 : UNE FOIS que tu as reçu le retour de l\'outil, tu DOIS IMPÉRATIVEMENT analyser ce résultat et rédiger une réponse claire et détaillée pour l\'utilisateur. Ne renvoie jamais une réponse vide. '
            . 'Réponds toujours en français.';

        $agent = new AnonymousAgent(
            instructions: $systemPrompt,
            messages: [],
            tools: $aiTools
        );

        $response = $agent->prompt(
            prompt: $prompt,
            provider: 'ollama',
            model: env('OLLAMA_MODEL', 'qwen2.5:7b'),
            timeout: 600
        );

        return response()->json([
            'success'     => true,
            'ai_response' => (string) $response,
        ]);
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
