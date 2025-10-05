<?php
/**
 * Script pentru generarea unui token JWT valid pentru Mercure
 * Usage: php generate-mercure-token.php [MERCURE_JWT_SECRET] [retrospective_id]
 */

if ($argc < 2) {
    echo "Usage: php generate-mercure-token.php <MERCURE_JWT_SECRET> [retrospective_id]\n";
    echo "Example: php generate-mercure-token.php 'your_secret_key' 1\n";
    exit(1);
}

$secret = $argv[1];
$retrospectiveId = $argv[2] ?? 1;

function generateMercureToken(string $secret, int $retrospectiveId): string
{
    $payload = [
        'mercure' => [
            'subscribe' => [
                "retrospective/{$retrospectiveId}",
                "retrospective/{$retrospectiveId}/timer",
                "retrospective/{$retrospectiveId}/review",
                "retrospective/{$retrospectiveId}/connected-users"
            ]
        ]
    ];

    // Simple JWT encoding using Mercure secret
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payloadJson = json_encode($payload);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadJson));
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $secret, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

$token = generateMercureToken($secret, $retrospectiveId);

echo "Generated JWT Token for retrospective ID $retrospectiveId:\n";
echo "$token\n\n";

echo "Test command:\n";
echo "curl -H \"Authorization: Bearer $token\" \"http://retro.estimatorapp.site/.well-known/mercure?topic=retrospective/{$retrospectiveId}\"\n";
