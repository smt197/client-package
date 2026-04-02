<?php

namespace Database\Seeders;

use App\Models\AiAgent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AiAgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $agents = [
            [
                'name' => 'Architecte Laravel (Local Qwen)',
                'slug' => Str::slug('Architecte Laravel Local Qwen'),
                'provider' => 'openai', // ou ollama si le provider SDK l'utilise avec une syntaxe différente
                'model_name' => 'qwen2.5:7b',
                'instructions' => 'Tu es un architecte logiciel Laravel expert. Tu as accès à des outils externes (MCP) pour interagir avec le projet. SURTOUT pour "edit-module", tu DOIS impérativement utiliser un OBJET JSON structuré pour le paramètre "changes" avec les clés "added", "renamed" ou "modified" comme spécifié dans le schéma. Ne mets JAMAIS de texte simple dans "changes". Réponds toujours en français et de manière précise.',
                'is_active' => true,
            ],
            [
                'name' => 'Architecte Laravel (OpenAI GPT-4o)',
                'slug' => Str::slug('Architecte Laravel OpenAI GPT-4o'),
                'provider' => 'openai',
                'model_name' => 'gpt-4o',
                'instructions' => 'Tu es un architecte logiciel Laravel expert. Tu as accès à des outils externes (MCP) pour interagir avec le projet. SURTOUT pour "edit-module", tu DOIS impérativement utiliser un OBJET JSON structuré pour le paramètre "changes" avec les clés "added", "renamed" ou "modified" comme spécifié dans le schéma. Ne mets JAMAIS de texte simple dans "changes". Réponds toujours en français et de manière précise.',
                'is_active' => true,
            ],
            [
                'name' => 'Architecte Laravel (Anthropic Claude 3.5 Sonnet)',
                'slug' => Str::slug('Architecte Laravel Anthropic Claude 3.5 Sonnet'),
                'provider' => 'anthropic',
                'model_name' => 'claude-3-5-sonnet-latest',
                'instructions' => 'Tu es un architecte logiciel Laravel expert. Tu as accès à des outils externes (MCP) pour interagir avec le projet. SURTOUT pour "edit-module", tu DOIS impérativement utiliser un OBJET JSON structuré pour le paramètre "changes" avec les clés "added", "renamed" ou "modified" comme spécifié dans le schéma. Ne mets JAMAIS de texte simple dans "changes". Réponds toujours en français et de manière précise.',
                'is_active' => true,
            ],
            [
                'name' => 'Architecte Laravel (Local Llama 3.1)',
                'slug' => Str::slug('Architecte Laravel Local Llama 3.1'),
                'provider' => 'openai', 
                'model_name' => 'llama3.1:8b',
                'instructions' => 'Tu es un architecte logiciel Laravel expert. Tu as accès à des outils externes (MCP) pour interagir avec le projet. SURTOUT pour "edit-module", tu DOIS impérativement utiliser un OBJET JSON structuré pour le paramètre "changes" avec les clés "added", "renamed" ou "modified" comme spécifié dans le schéma. Ne mets JAMAIS de texte simple dans "changes". Réponds toujours en français et de manière précise.',
                'is_active' => true,
            ]
        ];

        foreach ($agents as $agent) {
            AiAgent::updateOrCreate(['slug' => $agent['slug']], $agent);
        }
    }
}
