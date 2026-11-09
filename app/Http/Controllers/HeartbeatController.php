<?php

namespace App\Http\Controllers;

use App\Services\DeadMansSnitchService;
use App\Services\EscalationMatrixService;
use App\Services\PingTokenService;
use App\Services\SlaTelemetryEngine;

class HeartbeatController
{
    protected DeadMansSnitchService $snitchService;
    protected EscalationMatrixService $escalationService;
    protected PingTokenService $tokenService;
    protected SlaTelemetryEngine $slaEngine;

    public function __construct(
        ?DeadMansSnitchService $snitchService = null,
        ?EscalationMatrixService $escalationService = null,
        ?PingTokenService $tokenService = null,
        ?SlaTelemetryEngine $slaEngine = null
    ) {
        $this->snitchService = $snitchService ?? new DeadMansSnitchService();
        $this->escalationService = $escalationService ?? new EscalationMatrixService();
        $this->tokenService = $tokenService ?? new PingTokenService();
        $this->slaEngine = $slaEngine ?? new SlaTelemetryEngine();
    }

    /**
     * POST /api/v1/snitches
     */
    public function store(array $requestData): array
    {
        if (empty($requestData['name'])) {
            return ['status' => 422, 'error' => 'Job name is required'];
        }

        $snitch = $this->snitchService->registerSnitch($requestData);

        return [
            'status' => 201,
            'message' => 'Heartbeat monitor initialized',
            'data' => $snitch,
        ];
    }

    /**
     * POST /api/v1/snitches/{id}/ping
     */
    public function ping(array &$snitch, array $requestData, ?string $signature, ?int $timestamp): array
    {
        $result = $this->snitchService->recordPing($snitch, $requestData, $signature, $timestamp);

        if (!$result['success']) {
            return ['status' => 403, 'error' => $result['error'], 'message' => $result['message']];
        }

        return [
            'status' => 200,
            'message' => 'Pulse registered successfully',
            'snitch_status' => $snitch['status'],
            'next_expected_at' => $snitch['next_expected_at'],
        ];
    }

    /**
     * POST /api/v1/snitches/{id}/fail
     */
    public function fail(array &$snitch, array $requestData): array
    {
        $payload = array_merge($requestData, ['status' => 'fail']);
        $result = $this->snitchService->recordPing($snitch, $payload);

        return [
            'status' => 200,
            'message' => 'Job failure acknowledged and logged',
            'snitch_status' => $snitch['status'],
            'consecutive_failures' => $snitch['consecutive_failures'],
        ];
    }

    /**
     * GET /api/v1/snitches/{id}/status
     */
    public function status(array &$snitch, int $currentTime): array
    {
        $evaluation = $this->snitchService->evaluateSnitchState($snitch, $currentTime);

        return [
            'status' => 200,
            'data' => array_merge($snitch, [
                'evaluation' => $evaluation,
            ]),
        ];
    }
}
