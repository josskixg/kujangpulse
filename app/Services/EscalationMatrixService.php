<?php

namespace App\Services;

class EscalationMatrixService
{
    public const SEV_1 = 'SEV-1 (CRITICAL)';
    public const SEV_2 = 'SEV-2 (HIGH)';
    public const SEV_3 = 'SEV-3 (WARNING)';
    public const SEV_RESOLVED = 'RESOLVED';

    protected int $failureHysteresisThreshold;
    protected int $successHysteresisThreshold;

    public function __construct(int $failureHysteresisThreshold = 3, int $successHysteresisThreshold = 2)
    {
        $this->failureHysteresisThreshold = $failureHysteresisThreshold;
        $this->successHysteresisThreshold = $successHysteresisThreshold;
    }

    /**
     * Determine if an incident transition should trigger an alert, suppressing flapping.
     */
    public function evaluateEscalation(array $snitch, string $evaluatedStatus, bool $incidentCurrentlyOpen): array
    {
        $failures = (int)($snitch['consecutive_failures'] ?? 0);
        $successes = (int)($snitch['consecutive_successes'] ?? 0);

        // Flapping guard: if currently no incident open, require consecutive failures >= threshold
        if (!$incidentCurrentlyOpen) {
            if (in_array($evaluatedStatus, [DeadMansSnitchService::STATUS_MISSED, DeadMansSnitchService::STATUS_CRITICAL_DOWN])) {
                if ($failures >= $this->failureHysteresisThreshold) {
                    return [
                        'action' => 'OPEN_INCIDENT',
                        'severity' => ($evaluatedStatus === DeadMansSnitchService::STATUS_CRITICAL_DOWN) ? self::SEV_1 : self::SEV_2,
                        'reason' => "Sustained failure: {$failures} consecutive missed check-ins exceeded threshold ({$this->failureHysteresisThreshold})",
                        'suppressed' => false,
                    ];
                }

                return [
                    'action' => 'SUPPRESS',
                    'severity' => self::SEV_3,
                    'reason' => "Transient anomaly suppressed by flapping guard ({$failures}/{$this->failureHysteresisThreshold} failures)",
                    'suppressed' => true,
                ];
            }

            return [
                'action' => 'NONE',
                'severity' => null,
                'reason' => 'Service operates within normal parameters',
                'suppressed' => false,
            ];
        }

        // An incident IS currently open: check if recovery threshold met
        if ($evaluatedStatus === DeadMansSnitchService::STATUS_HEALTHY) {
            if ($successes >= $this->successHysteresisThreshold) {
                return [
                    'action' => 'RESOLVE_INCIDENT',
                    'severity' => self::SEV_RESOLVED,
                    'reason' => "Recovery confirmed: {$successes} consecutive healthy pings received",
                    'suppressed' => false,
                ];
            }

            return [
                'action' => 'AWAIT_RECOVERY_CONFIRMATION',
                'severity' => null,
                'reason' => "Recovery in progress ({$successes}/{$this->successHysteresisThreshold} confirmations)",
                'suppressed' => true,
            ];
        }

        return [
            'action' => 'MAINTAIN_INCIDENT',
            'severity' => self::SEV_1,
            'reason' => 'Incident remains ongoing',
            'suppressed' => false,
        ];
    }

    /**
     * Format rich message for Telegram Bot API.
     */
    public function formatTelegramAlert(array $snitch, string $action, string $severity, string $reason): array
    {
        $isResolved = ($action === 'RESOLVE_INCIDENT');
        $icon = $isResolved ? '✅' : '🚨';
        $title = $isResolved ? 'INCIDENT RESOLVED' : 'HEARTBEAT INCIDENT DETECTED';

        $text = "{$icon} *{$title}*\n\n"
              . "• *Target*: `{$snitch['name']}` (`{$snitch['id']}`)\n"
              . "• *Severity*: *{$severity}*\n"
              . "• *Status*: `{$snitch['status']}`\n"
              . "• *Interval*: {$snitch['interval_seconds']}s (Grace: {$snitch['grace_seconds']}s)\n"
              . "• *Diagnosis*: {$reason}\n"
              . "• *Timestamp*: `" . date('Y-m-d H:i:s \U\T\C', time()) . "`";

        return [
            'channel' => 'telegram',
            'parse_mode' => 'Markdown',
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
    }

    /**
     * Format payload for Discord Webhook embed.
     */
    public function formatDiscordAlert(array $snitch, string $action, string $severity, string $reason): array
    {
        $isResolved = ($action === 'RESOLVE_INCIDENT');
        // Discord color decimal: 0xc2ef4e (12775246) for green, 0xfa7faa (16416682) for red/pink
        $color = $isResolved ? 12775246 : 16416682;

        return [
            'channel' => 'discord',
            'username' => 'KujangPulse Monitor',
            'embeds' => [
                [
                    'title' => ($isResolved ? '✅ ' : '🚨 ') . "[{$severity}] {$snitch['name']}",
                    'description' => $reason,
                    'color' => $color,
                    'fields' => [
                        ['name' => 'Snitch ID', 'value' => "`{$snitch['id']}`", 'inline' => true],
                        ['name' => 'Current Status', 'value' => "`{$snitch['status']}`", 'inline' => true],
                        ['name' => 'Failures / Successes', 'value' => "{$snitch['consecutive_failures']} / {$snitch['consecutive_successes']}", 'inline' => true],
                    ],
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ];
    }

    /**
     * Format signed HTTP Webhook dispatch payload with HMAC-SHA256 signature.
     */
    public function formatWebhookPayload(array $snitch, string $action, string $severity, string $reason, string $webhookSecret): array
    {
        $timestamp = time();
        $payloadData = [
            'event' => 'heartbeat.incident',
            'action' => $action,
            'severity' => $severity,
            'reason' => $reason,
            'timestamp' => $timestamp,
            'snitch' => [
                'id' => $snitch['id'],
                'name' => $snitch['name'],
                'status' => $snitch['status'],
                'interval_seconds' => $snitch['interval_seconds'],
                'last_ping_at' => $snitch['last_ping_at'],
                'last_duration_ms' => $snitch['last_duration_ms'],
                'last_exit_code' => $snitch['last_exit_code'],
            ],
        ];

        $rawJson = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawJson}", $webhookSecret);

        return [
            'channel' => 'webhook',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-KujangPulse-Timestamp' => (string)$timestamp,
                'X-KujangPulse-Signature' => "sha256={$signature}",
                'User-Agent' => 'KujangPulse-Webhook-Dispatcher/1.0',
            ],
            'body' => $payloadData,
            'raw_json' => $rawJson,
        ];
    }
}
