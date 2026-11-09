<?php

namespace Tests\Unit;

require_once __DIR__ . '/../../app/Services/PingTokenService.php';
require_once __DIR__ . '/../../app/Services/DeadMansSnitchService.php';
require_once __DIR__ . '/../../app/Services/EscalationMatrixService.php';
require_once __DIR__ . '/../../app/Services/SlaTelemetryEngine.php';
require_once __DIR__ . '/../../app/Http/Controllers/HeartbeatController.php';

use App\Services\DeadMansSnitchService;
use App\Services\EscalationMatrixService;
use App\Services\PingTokenService;
use App\Services\SlaTelemetryEngine;

class KujangPulseTest
{
    protected DeadMansSnitchService $snitchService;
    protected EscalationMatrixService $escalationService;
    protected PingTokenService $tokenService;
    protected SlaTelemetryEngine $slaEngine;

    public function __construct()
    {
        $this->tokenService = new PingTokenService();
        $this->snitchService = new DeadMansSnitchService($this->tokenService);
        $this->escalationService = new EscalationMatrixService(3, 2);
        $this->slaEngine = new SlaTelemetryEngine();
    }

    public function runAll(): array
    {
        $results = [];
        $methods = get_class_methods($this);

        foreach ($methods as $method) {
            if (str_starts_with($method, 'test')) {
                try {
                    $this->$method();
                    $results[$method] = ['status' => 'PASS'];
                } catch (\Throwable $e) {
                    $results[$method] = [
                        'status' => 'FAIL',
                        'error' => $e->getMessage(),
                        'line' => $e->getLine(),
                    ];
                }
            }
        }

        return $results;
    }

    protected function assertTrue($condition, string $msg = 'Expected condition to be true'): void
    {
        if (!$condition) throw new \RuntimeException($msg);
    }

    protected function assertFalse($condition, string $msg = 'Expected condition to be false'): void
    {
        if ($condition) throw new \RuntimeException($msg);
    }

    protected function assertEquals($expected, $actual, string $msg = 'Values not equal'): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("{$msg} — Expected: " . var_export($expected, true) . ", got: " . var_export($actual, true));
        }
    }

    // 1. Test Snitch Registration Defaults
    public function test01SnitchRegistrationDefaultParameters(): void
    {
        $snitch = $this->snitchService->registerSnitch([
            'name' => 'Database Nightly Backup',
            'interval_seconds' => 86400,
            'grace_seconds' => 3600,
            'created_at' => 1700000000,
        ]);

        $this->assertEquals('Database Nightly Backup', $snitch['name']);
        $this->assertEquals(DeadMansSnitchService::STATUS_PENDING, $snitch['status']);
        $this->assertEquals(1700086400, $snitch['next_expected_at']);
        $this->assertEquals(1700090000, $snitch['grace_deadline_at']);
        $this->assertEquals(0, $snitch['consecutive_failures']);
    }

    // 2. Test Healthy Ping Updates State
    public function test02HealthyPingUpdatesStateAndNextExpected(): void
    {
        $snitch = $this->snitchService->registerSnitch([
            'name' => 'Invoice Worker',
            'interval_seconds' => 300,
            'grace_seconds' => 60,
        ]);

        $pingTime = 1700001000;
        $res = $this->snitchService->recordPing($snitch, [
            'duration_ms' => 45.2,
            'memory_mb' => 12.8,
            'exit_code' => 0,
        ], null, null, $pingTime);

        $this->assertTrue($res['success']);
        $this->assertEquals(DeadMansSnitchService::STATUS_HEALTHY, $snitch['status']);
        $this->assertEquals($pingTime, $snitch['last_ping_at']);
        $this->assertEquals($pingTime + 300, $snitch['next_expected_at']);
        $this->assertEquals($pingTime + 360, $snitch['grace_deadline_at']);
        $this->assertEquals(1, $snitch['consecutive_successes']);
        $this->assertEquals(45.2, $snitch['last_duration_ms']);
    }

    // 3. Test Reject Missing Signature When Auth Required
    public function test03RejectMissingSignatureWhenAuthRequired(): void
    {
        $snitch = $this->snitchService->registerSnitch([
            'name' => 'Secure Vault Sync',
            'require_auth' => true,
        ]);

        $res = $this->snitchService->recordPing($snitch, ['duration_ms' => 10]);
        $this->assertFalse($res['success']);
        $this->assertEquals('AUTHENTICATION_REQUIRED', $res['error']);
    }

    // 4. Test Reject Expired / Drifted Timestamp Signature
    public function test04RejectExpiredOrDriftedTimestampSignature(): void
    {
        $secret = 'super-secret-key';
        $snitch = $this->snitchService->registerSnitch([
            'name' => 'Secure Vault Sync',
            'require_auth' => true,
            'auth_secret' => $secret,
        ]);

        $clientTimestamp = 1700000000;
        $serverTimestamp = 1700000400; // 400s drift (exceeds max 300s)
        $sig = $this->tokenService->generatePingToken($snitch['id'], $secret, $clientTimestamp);

        $res = $this->snitchService->recordPing($snitch, [], $sig, $clientTimestamp, $serverTimestamp);
        $this->assertFalse($res['success']);
        $this->assertEquals('SIGNATURE_INVALID', $res['error']);
    }

    // 5. Test Verify Valid HMAC-SHA256 Ping Signature
    public function test05VerifyValidHmacSha256PingSignature(): void
    {
        $secret = 'super-secret-key';
        $snitch = $this->snitchService->registerSnitch([
            'name' => 'Secure Vault Sync',
            'require_auth' => true,
            'auth_secret' => $secret,
        ]);

        $clientTimestamp = 1700000100;
        $serverTimestamp = 1700000105; // 5s drift (well within 300s)
        $sig = $this->tokenService->generatePingToken($snitch['id'], $secret, $clientTimestamp);

        $res = $this->snitchService->recordPing($snitch, ['duration_ms' => 22.1], $sig, $clientTimestamp, $serverTimestamp);
        $this->assertTrue($res['success']);
        $this->assertEquals(DeadMansSnitchService::STATUS_HEALTHY, $snitch['status']);
    }

    // 6. Test Evaluation Healthy Within Interval
    public function test06EvaluationHealthyWithinInterval(): void
    {
        $snitch = $this->snitchService->registerSnitch(['interval_seconds' => 300, 'grace_seconds' => 60]);
        $this->snitchService->recordPing($snitch, [], null, null, 1700000000);

        $eval = $this->snitchService->evaluateSnitchState($snitch, 1700000200); // 200s after ping (<300)
        $this->assertEquals(DeadMansSnitchService::STATUS_HEALTHY, $eval['status']);
        $this->assertEquals(100, $eval['seconds_until_next']);
    }

    // 7. Test Evaluation Grace Exceeded Buffer
    public function test07EvaluationGraceExceededBuffer(): void
    {
        $snitch = $this->snitchService->registerSnitch(['interval_seconds' => 300, 'grace_seconds' => 60]);
        $this->snitchService->recordPing($snitch, [], null, null, 1700000000);

        // 320s after ping: passed interval (300) but within grace (360)
        $eval = $this->snitchService->evaluateSnitchState($snitch, 1700000320);
        $this->assertEquals(DeadMansSnitchService::STATUS_GRACE_EXCEEDED, $eval['status']);
        $this->assertEquals(20, $eval['seconds_in_grace']);
        $this->assertEquals(40, $eval['grace_remaining']);
    }

    // 8. Test Evaluation Missed Past Grace Deadline
    public function test08EvaluationMissedPastGraceDeadline(): void
    {
        $snitch = $this->snitchService->registerSnitch(['interval_seconds' => 300, 'grace_seconds' => 60]);
        $this->snitchService->recordPing($snitch, [], null, null, 1700000000);

        // 400s after ping: passed grace deadline (360s)
        $eval = $this->snitchService->evaluateSnitchState($snitch, 1700000400);
        $this->assertEquals(DeadMansSnitchService::STATUS_MISSED, $eval['status']);
        $this->assertEquals(40, $eval['seconds_overdue']);
        $this->assertEquals(1, $snitch['consecutive_failures']);
    }

    // 9. Test Explicit Job Failure Report
    public function test09ExplicitJobFailureReport(): void
    {
        $snitch = $this->snitchService->registerSnitch([]);
        $res = $this->snitchService->recordPing($snitch, [
            'status' => 'fail',
            'exit_code' => 2,
            'error' => 'Database connection timeout on replica-02',
        ]);

        $this->assertTrue($res['success']);
        $this->assertEquals(DeadMansSnitchService::STATUS_CRITICAL_DOWN, $snitch['status']);
        $this->assertEquals(2, $snitch['last_exit_code']);
        $this->assertEquals(1, $snitch['consecutive_failures']);
    }

    // 10. Test Escalation Hysteresis Suppresses Transient Flapping
    public function test10EscalationHysteresisSuppressesTransientFlapping(): void
    {
        $snitch = $this->snitchService->registerSnitch([]);
        $snitch['consecutive_failures'] = 1; // only 1 failure (< threshold 3)

        $escalation = $this->escalationService->evaluateEscalation(
            $snitch,
            DeadMansSnitchService::STATUS_MISSED,
            false // no incident currently open
        );

        $this->assertEquals('SUPPRESS', $escalation['action']);
        $this->assertTrue($escalation['suppressed']);
    }

    // 11. Test Escalation Hysteresis Opens Incident On Sustained Failures
    public function test11EscalationHysteresisOpensIncidentOnSustainedFailures(): void
    {
        $snitch = $this->snitchService->registerSnitch([]);
        $snitch['consecutive_failures'] = 3; // exactly matches threshold 3

        $escalation = $this->escalationService->evaluateEscalation(
            $snitch,
            DeadMansSnitchService::STATUS_MISSED,
            false
        );

        $this->assertEquals('OPEN_INCIDENT', $escalation['action']);
        $this->assertEquals(EscalationMatrixService::SEV_2, $escalation['severity']);
        $this->assertFalse($escalation['suppressed']);
    }

    // 12. Test Escalation Hysteresis Resolves Incident On Confirmed Recovery
    public function test12EscalationHysteresisResolvesIncidentOnConfirmedRecovery(): void
    {
        $snitch = $this->snitchService->registerSnitch([]);
        $snitch['status'] = DeadMansSnitchService::STATUS_HEALTHY;
        $snitch['consecutive_successes'] = 2; // matches threshold 2

        $escalation = $this->escalationService->evaluateEscalation(
            $snitch,
            DeadMansSnitchService::STATUS_HEALTHY,
            true // incident was open
        );

        $this->assertEquals('RESOLVE_INCIDENT', $escalation['action']);
        $this->assertEquals(EscalationMatrixService::SEV_RESOLVED, $escalation['severity']);
        $this->assertFalse($escalation['suppressed']);
    }

    // 13. Test Telegram Notification Formatter
    public function test13TelegramNotificationFormatter(): void
    {
        $snitch = $this->snitchService->registerSnitch(['name' => 'Payment Reconciliation']);
        $snitch['status'] = DeadMansSnitchService::STATUS_MISSED;

        $msg = $this->escalationService->formatTelegramAlert(
            $snitch,
            'OPEN_INCIDENT',
            EscalationMatrixService::SEV_1,
            'Missed heartbeat by 120s'
        );

        $this->assertEquals('telegram', $msg['channel']);
        $this->assertEquals('Markdown', $msg['parse_mode']);
        $this->assertTrue(str_contains($msg['text'], 'HEARTBEAT INCIDENT DETECTED'));
        $this->assertTrue(str_contains($msg['text'], 'Payment Reconciliation'));
    }

    // 14. Test Discord Notification Formatter
    public function test14DiscordNotificationFormatter(): void
    {
        $snitch = $this->snitchService->registerSnitch(['name' => 'Elasticsearch Indexer']);
        $discord = $this->escalationService->formatDiscordAlert(
            $snitch,
            'OPEN_INCIDENT',
            EscalationMatrixService::SEV_2,
            'Service missed 3 check-ins'
        );

        $this->assertEquals('discord', $discord['channel']);
        $this->assertEquals(1, count($discord['embeds']));
        $this->assertTrue(str_contains($discord['embeds'][0]['title'], 'Elasticsearch Indexer'));
        $this->assertEquals(16416682, $discord['embeds'][0]['color']); // Red/Pink
    }

    // 15. Test Signed HMAC Webhook Payload
    public function test15SignedHmacWebhookPayload(): void
    {
        $snitch = $this->snitchService->registerSnitch(['name' => 'Telemetry Ingest']);
        $secret = 'webhook-hmac-secret-99';

        $webhook = $this->escalationService->formatWebhookPayload(
            $snitch,
            'OPEN_INCIDENT',
            EscalationMatrixService::SEV_1,
            'Critical cron failure',
            $secret
        );

        $this->assertEquals('webhook', $webhook['channel']);
        $this->assertTrue(isset($webhook['headers']['X-KujangPulse-Signature']));
        $this->assertTrue(str_starts_with($webhook['headers']['X-KujangPulse-Signature'], 'sha256='));

        // Verify that the signature matches our HMAC calculation
        $expectedSig = hash_hmac(
            'sha256',
            "{$webhook['headers']['X-KujangPulse-Timestamp']}.{$webhook['raw_json']}",
            $secret
        );
        $this->assertEquals("sha256={$expectedSig}", $webhook['headers']['X-KujangPulse-Signature']);
    }

    // 16. Test SLA Telemetry Budget and Latency Percentiles
    public function test16SlaTelemetryBudgetAndPercentileComputations(): void
    {
        // 30 days = 2,592,000 seconds; 1,200 seconds of downtime (~20 min)
        $sla = $this->slaEngine->calculateSla(2592000, 1200, 99.9);
        $this->assertEquals(99.9537, $sla['uptime_percentage']);
        $this->assertFalse($sla['is_breached']);
        $this->assertEquals(2592, $sla['allowable_downtime_seconds']);
        $this->assertEquals(1392, $sla['error_budget_remaining_seconds']);

        // Latency percentiles
        $latencies = [12.0, 15.5, 18.2, 22.0, 25.1, 30.0, 45.0, 80.0, 120.0, 250.0];
        $percentiles = $this->slaEngine->calculateLatencyPercentiles($latencies);
        $this->assertEquals(10, $percentiles['count']);
        $this->assertEquals(12.0, $percentiles['min_ms']);
        $this->assertEquals(250.0, $percentiles['max_ms']);
        $this->assertTrue($percentiles['p50_ms'] >= 23.0 && $percentiles['p50_ms'] <= 28.0);
        $this->assertTrue($percentiles['p99_ms'] >= 230.0);
        $this->assertTrue($percentiles['ewma_ms'] > 0);
    }
}

$runner = new KujangPulseTest();
$results = $runner->runAll();
$passed = 0; $failed = 0;
foreach ($results as $method => $res) {
    if ($res['status'] === 'PASS') {
        $passed++;
    } else {
        $failed++;
        echo "[FAIL] $method: {$res['error']} on line {$res['line']}\n";
    }
}
if ($failed > 0) {
    echo "FAILED: $failed tests failed out of " . ($passed + $failed) . "\n";
    exit(1);
} else {
    echo "PASS: All $passed KujangPulse tests passed successfully.\n";
}
