<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$url = env('MCP_SERVER_URL', 'http://boost.192.168.1.10.sslip.io:8002/api/mcp');
$debugUrl = str_replace('/mcp', '/debug-boost', $url);
echo "Testing debug URL: $debugUrl\n";

try {
    $response = \Illuminate\Support\Facades\Http::timeout(5)->get($debugUrl);

    echo "Status Code: " . $response->status() . "\n";
    if ($response->failed()) {
        echo "Error: " . $response->body() . "\n";
    } else {
        echo "Debug Response: " . json_encode($response->json(), JSON_PRETTY_PRINT) . "\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
