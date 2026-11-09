<?php

namespace App\Services;

class DeadMansSnitchService
{
    public const STATUS_HEALTHY = 'HEALTHY';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_GRACE_EXCEEDED = 'GRACE_EXCEEDED';
    public const STATUS_MISSED = 'MISSED';
    public const STATUS_CRITICAL_DOWN = 'CRITICAL_DOWN';

    protected PingTokenService $tokenService;

    public function __construct(?PingTokenService $tokenService = null)
    {
        $this->tokenService = $tokenService ?? new PingTokenService();
    }

    /**
     * Register a new monitored job / snitch.
     */
    public function registerSnitch(array $params): array
    {
        $uuid = $params['id'] ?? sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $interval = (int)($params['interval_seconds'] ?? 300); // 5 minutes default
        $grace = (int)($params['grace_seconds'] ?? max(30, (int)($interval * 0.20))); // 20% grace or min 30s
        $now = $params['created_at'] ?? time();

        return [
            'id' => $uuid,
            'name' => $params['name'] ?? 'Unnamed Cron Job',
            'tags' => $params['tags'] ?? ['production'],
            'schedule_type' => $params['schedule_type'] ?? 'interval',
            'interval_seconds' => $interval,
            'grace_seconds' => $grace,
            'auth_secret' => $params['auth_secret'] ?? bin2hex(random_bytes(16)),
            'require_auth' => (bool)($params['require_auth'] ?? false),
            'status' => self::STATUS_PENDING,
            'last_ping_at' => null,
            'next_expected_at' => $now + $interval,
            'grace_deadline_at' => $now + $interval + $grace,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'last_duration_ms' => null,
            'last_memory_mb' => null,
            'last_exit_code' => 0,
            'metadata' => [],
        ];
    }

    /**
     * Process an incoming ping pulse from a microservice or cron job.
     */
    public function recordPing(
        array &$snitch,
        array $payload = [],
        ?string $signature = null,
        ?int $clientTimestamp = null,
        ?int $serverTimestamp = null
    ): array {
        $serverTime = $serverTimestamp ?? time();

        // Check authentication if required
        if ($snitch['require_auth']) {
            if (!$signature || !$clientTimestamp) {
                return [
                    'success' => false,
                    'error' => 'AUTHENTICATION_REQUIRED',
                    'message' => 'Missing ping signature or client timestamp',
                ];
            }

            $authResult = $this->tokenService->verifyPingToken(
                $snitch['id'],
                $signature,
                $snitch['auth_secret'],
                $clientTimestamp,
                $serverTime
            );

            if (!$authResult['valid']) {
                return [
                    'success' => false,
                    'error' => 'SIGNATURE_INVALID',
                    'message' => $authResult['reason'],
                ];
            }
        }

        $isExplicitFailure = isset($payload['status']) && $payload['status'] === 'fail';
        $exitCode = (int)($payload['exit_code'] ?? ($isExplicitFailure ? 1 : 0));

        if ($isExplicitFailure || $exitCode !== 0) {
            $snitch['status'] = self::STATUS_CRITICAL_DOWN;
            $snitch['consecutive_failures']++;
            $snitch['consecutive_successes'] = 0;
            $snitch['last_exit_code'] = $exitCode;
            $snitch['metadata'] = [
                'error_message' => $payload['error'] ?? 'Job reported failure with non-zero exit code',
                'stdout_tail' => $payload['stdout_tail'] ?? null,
            ];

            return [
                'success' => true,
                'status' => self::STATUS_CRITICAL_DOWN,
                'message' => 'Failure recorded',
                'consecutive_failures' => $snitch['consecutive_failures'],
            ];
        }

        // Healthy Ping recorded
        $snitch['status'] = self::STATUS_HEALTHY;
        $snitch['last_ping_at'] = $serverTime;
        $snitch['next_expected_at'] = $serverTime + $snitch['interval_seconds'];
        $snitch['grace_deadline_at'] = $snitch['next_expected_at'] + $snitch['grace_seconds'];
        $snitch['consecutive_successes']++;
        $snitch['consecutive_failures'] = 0;
        $snitch['last_exit_code'] = 0;
        $snitch['last_duration_ms'] = isset($payload['duration_ms']) ? (float)$payload['duration_ms'] : null;
        $snitch['last_memory_mb'] = isset($payload['memory_mb']) ? (float)$payload['memory_mb'] : null;
        $snitch['metadata'] = $payload['metadata'] ?? [];

        return [
            'success' => true,
            'status' => self::STATUS_HEALTHY,
            'last_ping_at' => $snitch['last_ping_at'],
            'next_expected_at' => $snitch['next_expected_at'],
            'grace_deadline_at' => $snitch['grace_deadline_at'],
            'consecutive_successes' => $snitch['consecutive_successes'],
        ];
    }

    /**
     * Evaluate state of a snitch against the current wall-clock time.
     */
    public function evaluateSnitchState(array &$snitch, int $currentTime): array
    {
        // If it was an explicit failure already flagged
        if ($snitch['status'] === self::STATUS_CRITICAL_DOWN && $snitch['consecutive_failures'] > 0) {
            return [
                'status' => self::STATUS_CRITICAL_DOWN,
                'state_reason' => 'Job reported explicit failure or non-zero exit code',
                'seconds_overdue' => max(0, $currentTime - ($snitch['grace_deadline_at'] ?? $currentTime)),
            ];
        }

        // If never pinged and past initial grace deadline
        if ($snitch['last_ping_at'] === null) {
            if ($currentTime > $snitch['grace_deadline_at']) {
                $snitch['status'] = self::STATUS_MISSED;
                $snitch['consecutive_failures']++;
                $snitch['consecutive_successes'] = 0;
                return [
                    'status' => self::STATUS_MISSED,
                    'state_reason' => 'First ping was never received before initial grace deadline',
                    'seconds_overdue' => $currentTime - $snitch['grace_deadline_at'],
                ];
            }
            return [
                'status' => self::STATUS_PENDING,
                'state_reason' => 'Awaiting first ping',
                'seconds_overdue' => 0,
            ];
        }

        // Before expected time -> still healthy
        if ($currentTime <= $snitch['next_expected_at']) {
            $snitch['status'] = self::STATUS_HEALTHY;
            return [
                'status' => self::STATUS_HEALTHY,
                'state_reason' => 'Operating normally within schedule interval',
                'seconds_until_next' => $snitch['next_expected_at'] - $currentTime,
            ];
        }

        // Past next expected, but within grace period
        if ($currentTime <= $snitch['grace_deadline_at']) {
            $snitch['status'] = self::STATUS_GRACE_EXCEEDED;
            return [
                'status' => self::STATUS_GRACE_EXCEEDED,
                'state_reason' => 'Ping interval exceeded; currently within grace window buffer',
                'seconds_in_grace' => $currentTime - $snitch['next_expected_at'],
                'grace_remaining' => $snitch['grace_deadline_at'] - $currentTime,
            ];
        }

        // Past grace deadline -> Missed / Down
        $secondsOverdue = $currentTime - $snitch['grace_deadline_at'];
        $snitch['status'] = self::STATUS_MISSED;
        $snitch['consecutive_failures']++;
        $snitch['consecutive_successes'] = 0;

        return [
            'status' => self::STATUS_MISSED,
            'state_reason' => "Missed expected heartbeat window by {$secondsOverdue}s",
            'seconds_overdue' => $secondsOverdue,
        ];
    }
}
