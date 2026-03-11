<?php

namespace App\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class McpTool implements Tool
{
    public function __construct(
        protected string $toolName,
        protected string $toolDescription,
        protected array $inputSchema,
        protected Closure $handler
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function schema(JsonSchema $schema): array
    {
        // Le schéma MCP est déjà au format JSON Schema, on le mappe en types SDK
        $properties = $this->inputSchema['properties'] ?? [];
        $required = $this->inputSchema['required'] ?? [];

        $result = [];
        foreach ($properties as $name => $property) {
            $type = $property['type'] ?? 'string';
            $description = $property['description'] ?? '';

            $schemaType = match ($type) {
                'integer', 'number' => $schema->integer()->description($description),
                'boolean'           => $schema->boolean()->description($description),
                'array'             => $schema->array()->description($description),
                default             => $schema->string()->description($description),
            };

            if (in_array($name, $required, true)) {
                $schemaType = $schemaType->required();
            }

            $result[$name] = $schemaType;
        }

        return $result;
    }

    public function handle(Request $request): string
    {
        Log::info("🤖 L'IA utilise l'outil: {$this->toolName}", $request->all());
        
        $result = ($this->handler)($request->all());

        if (is_array($result)) {
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return (string) $result;
    }
}
