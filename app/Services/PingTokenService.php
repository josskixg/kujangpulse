<?php

namespace App\Services;

class PingTokenService
{
    /**
     * Generate an HMAC-SHA256 token for zero-leak public ping endpoints.
     */
    public function generatePingToken(string $snitchId, string $secret, int $timestamp): string
    {
        $payload = "{$snitchId}.{$timestamp}";
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Validate an incoming ping signature with timestamp drift guard.
     */
    public function verifyPingToken(
        string $snitchId,
        string $providedToken,
        string $secret,
        int $clientTimestamp,
        int $serverTimestamp,
        int $maxDriftSeconds = 300
    ): array {
        $drift = abs($serverTimestamp - $clientTimestamp);

        if ($drift > $maxDriftSeconds) {
            return [
                'valid' => false,
                'reason' => "Timestamp drift ({$drift}s) exceeds maximum allowed ({$maxDriftSeconds}s)",
                'drift' => $drift,
            ];
        }

        $expected = $this->generatePingToken($snitchId, $secret, $clientTimestamp);

        if (!hash_equals($expected, $providedToken)) {
            return [
                'valid' => false,
                'reason' => 'Invalid HMAC-SHA256 signature',
                'drift' => $drift,
            ];
        }

        return [
            'valid' => true,
            'reason' => 'Signature verified successfully',
            'drift' => $drift,
        ];
    }
}
