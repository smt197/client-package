<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ModuleWriterService
{
    /**
     * Process the MCP generate-module result and write files locally.
     *
     * @param  array  $mcpResult  The structured result from the MCP generate-module tool
     * @return array{success: bool, message: string, files_written: array<string>, errors: array<string>}
     */
    public function processModuleResult(array $mcpResult): array
    {
        $filesWritten = [];
        $errors = [];

        try {
            // 1. Write all generated files
            if (! empty($mcpResult['files'])) {
                foreach ($mcpResult['files'] as $file) {
                    try {
                        $this->writeFile($file['relative_path'], $file['content']);
                        $filesWritten[] = $file['relative_path'];
                        Log::info("📝 Fichier écrit: {$file['relative_path']}");
                    } catch (Exception $e) {
                        $errors[] = "Failed to write {$file['relative_path']}: {$e->getMessage()}";
                        Log::error("❌ Erreur écriture: {$file['relative_path']}", ['error' => $e->getMessage()]);
                    }
                }
            }

            // 2. Append route to routes/api.php
            if (! empty($mcpResult['route_append']) && is_array($mcpResult['route_append'])) {
                try {
                    $this->appendRoute($mcpResult['route_append']);
                    $filesWritten[] = 'routes/api.php (updated)';
                    Log::info('📝 Route ajoutée dans routes/api.php');
                } catch (Exception $e) {
                    $errors[] = "Failed to append route: {$e->getMessage()}";
                    Log::error('❌ Erreur route', ['error' => $e->getMessage()]);
                }
            }

            // 3. Execute post-actions (migrate, seed)
            if (! empty($mcpResult['post_actions'])) {
                foreach ($mcpResult['post_actions'] as $action) {
                    try {
                        $this->executePostAction($action);
                        Log::info("⚡ Post-action exécutée: {$action}");
                    } catch (Exception $e) {
                        $errors[] = "Post-action '{$action}' failed: {$e->getMessage()}";
                        Log::warning("⚠️ Post-action échouée: {$action}", ['error' => $e->getMessage()]);
                    }
                }
            }

            return [
                'success' => empty($errors),
                'message' => empty($errors)
                    ? 'Module written successfully to client-package'
                    : 'Module partially written with errors',
                'files_written' => $filesWritten,
                'errors' => $errors,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Module writing failed: '.$e->getMessage(),
                'files_written' => $filesWritten,
                'errors' => array_merge($errors, [$e->getMessage()]),
            ];
        }
    }

    /**
     * Write a file to the project's filesystem.
     */
    protected function writeFile(string $relativePath, string $content): void
    {
        $absolutePath = base_path($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $content);
    }

    /**
     * Append route and imports to routes/api.php.
     */
    protected function appendRoute(array $routeData): void
    {
        $routesPath = base_path($routeData['file'] ?? 'routes/api.php');

        if (! File::exists($routesPath)) {
            throw new Exception("Route file not found: {$routesPath}");
        }

        $content = File::get($routesPath);

        // Add import lines if not already present
        foreach ($routeData['import_lines'] ?? [] as $importLine) {
            if (! str_contains($content, $importLine)) {
                // Try to add after existing use statements
                if (preg_match('/(use\s+[^;]+;\s*\n)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $lastUsePos = strrpos($content, $matches[1][0]) + strlen($matches[1][0]);
                    $content = substr($content, 0, $lastUsePos).$importLine."\n".substr($content, $lastUsePos);
                } else {
                    // Add after <?php tag
                    $content = preg_replace(
                        '/^<\?php\s*/',
                        "<?php\n\n{$importLine}\n",
                        $content
                    );
                }
            }
        }

        // Add route line if not already present
        $routeLine = $routeData['route_line'] ?? '';
        if (! empty($routeLine) && ! str_contains($content, $routeLine)) {
            $content = rtrim($content)."\n\n{$routeLine}\n";
        }

        File::put($routesPath, $content);
    }

    /**
     * Execute a post-action (migrate, seed, etc.).
     */
    protected function executePostAction(string $action): void
    {
        if ($action === 'migrate') {
            Artisan::call('migrate', ['--force' => true]);
            Log::info('Migration output: '.Artisan::output());
        } elseif (str_starts_with($action, 'seed:')) {
            $seederClass = 'Database\\Seeders\\'.substr($action, 5);
            Artisan::call('db:seed', [
                '--class' => $seederClass,
                '--force' => true,
            ]);
            Log::info('Seeder output: '.Artisan::output());
        }
    }
}
