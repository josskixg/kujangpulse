<?php

namespace App\Services;

class SlaTelemetryEngine
{
    /**
     * Calculate availability percentage and error budget consumption against target SLA.
     */
    public function calculateSla(int $totalWindowSeconds, int $downtimeSeconds, float $targetSlaPercent = 99.9): array
    {
        $uptimeSeconds = max(0, $totalWindowSeconds - $downtimeSeconds);
        $uptimePercentage = ($totalWindowSeconds > 0)
            ? round(($uptimeSeconds / $totalWindowSeconds) * 100, 4)
            : 100.0;

        // Allowable downtime for target SLA
        $allowableDowntimeSeconds = (int)round($totalWindowSeconds * (1 - ($targetSlaPercent / 100.0)));
        $errorBudgetRemainingSeconds = $allowableDowntimeSeconds - $downtimeSeconds;
        $errorBudgetBurnRate = ($allowableDowntimeSeconds > 0)
            ? round(($downtimeSeconds / $allowableDowntimeSeconds) * 100, 2)
            : 0.0;

        return [
            'uptime_percentage' => $uptimePercentage,
            'target_sla' => $targetSlaPercent,
            'is_breached' => ($uptimePercentage < $targetSlaPercent),
            'total_window_seconds' => $totalWindowSeconds,
            'uptime_seconds' => $uptimeSeconds,
            'downtime_seconds' => $downtimeSeconds,
            'allowable_downtime_seconds' => $allowableDowntimeSeconds,
            'error_budget_remaining_seconds' => $errorBudgetRemainingSeconds,
            'error_budget_burn_percent' => $errorBudgetBurnRate,
        ];
    }

    /**
     * Compute MTTR (Mean Time to Recovery) and MTBF (Mean Time Between Failures).
     *
     * @param array<array{started_at: int, resolved_at: int}> $incidents
     */
    public function calculateMttrMtbf(array $incidents, int $monitoringWindowSeconds): array
    {
        $incidentCount = count($incidents);
        if ($incidentCount === 0) {
            return [
                'incident_count' => 0,
                'total_downtime_seconds' => 0,
                'mttr_seconds' => 0.0,
                'mtbf_seconds' => (float)$monitoringWindowSeconds,
                'availability' => 100.0,
            ];
        }

        $totalDowntime = 0;
        foreach ($incidents as $inc) {
            $duration = max(0, $inc['resolved_at'] - $inc['started_at']);
            $totalDowntime += $duration;
        }

        $mttr = round($totalDowntime / $incidentCount, 2);
        $totalUptime = max(0, $monitoringWindowSeconds - $totalDowntime);
        $mtbf = round($totalUptime / $incidentCount, 2);
        $availability = ($monitoringWindowSeconds > 0)
            ? round(($totalUptime / $monitoringWindowSeconds) * 100, 4)
            : 100.0;

        return [
            'incident_count' => $incidentCount,
            'total_downtime_seconds' => $totalDowntime,
            'mttr_seconds' => $mttr,
            'mtbf_seconds' => $mtbf,
            'availability' => $availability,
        ];
    }

    /**
     * Calculate response latency percentiles (P50, P90, P95, P99) and Exponential Weighted Moving Average (EWMA).
     *
     * @param array<float|int> $latencies
     */
    public function calculateLatencyPercentiles(array $latencies, float $alpha = 0.3): array
    {
        if (empty($latencies)) {
            return [
                'count' => 0,
                'min_ms' => 0.0,
                'max_ms' => 0.0,
                'p50_ms' => 0.0,
                'p90_ms' => 0.0,
                'p95_ms' => 0.0,
                'p99_ms' => 0.0,
                'ewma_ms' => 0.0,
            ];
        }

        sort($latencies);
        $count = count($latencies);

        $getPercentile = function (float $p) use ($latencies, $count): float {
            if ($count === 1) return (float)$latencies[0];
            $rank = ($p / 100) * ($count - 1);
            $lower = (int)floor($rank);
            $upper = (int)ceil($rank);
            $weight = $rank - $lower;
            return round((float)($latencies[$lower] * (1 - $weight) + $latencies[$upper] * $weight), 2);
        };

        // EWMA computation
        $ewma = (float)$latencies[0];
        for ($i = 1; $i < $count; $i++) {
            $ewma = ($alpha * (float)$latencies[$i]) + ((1 - $alpha) * $ewma);
        }

        return [
            'count' => $count,
            'min_ms' => (float)$latencies[0],
            'max_ms' => (float)$latencies[$count - 1],
            'p50_ms' => $getPercentile(50.0),
            'p90_ms' => $getPercentile(90.0),
            'p95_ms' => $getPercentile(95.0),
            'p99_ms' => $getPercentile(99.0),
            'ewma_ms' => round($ewma, 2),
        ];
    }
}
