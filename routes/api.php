<?php

use App\Http\Controllers\HeartbeatController;

/**
 * KujangPulse API Routes
 * Laravel 11 / PHP 8.2 Route Definition
 */

return [
    'POST /api/v1/snitches' => [HeartbeatController::class, 'store'],
    'POST /api/v1/snitches/{id}/ping' => [HeartbeatController::class, 'ping'],
    'POST /api/v1/snitches/{id}/fail' => [HeartbeatController::class, 'fail'],
    'GET /api/v1/snitches/{id}/status' => [HeartbeatController::class, 'status'],
];
