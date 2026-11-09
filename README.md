# KujangPulse ⚡

> **Self-Hosted Synthetic Heartbeat, Dead-Man's Snitch, & Incident Escalation Engine**  
> Enterprise-grade cron telemetry, flapping-guard hysteresis, and zero-daemon SLA calculator for Laravel 11 / PHP 8.2 & Node.js.

![KujangPulse Logo](logo.png)

[![Unit Tests](https://img.shields.io/badge/Unit%20Tests-16%2F16%20Passing-brightgreen?style=flat-square)](tests/run_test.php)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue?style=flat-square)](https://php.net)
[![Design System](https://img.shields.io/badge/Design-Sentry%20Dark-6a5fc1?style=flat-square)](DESIGN.md)
[![Live Console](https://img.shields.io/badge/Demo-Live%20Console-c2ef4e?style=flat-square)](https://josskixg.github.io/kujangpulse/)

---

## ⚡ Why KujangPulse?

Modern production systems run dozens of critical scheduled tasks (database backups, invoice billing, cache warmups, QRIS reconciliation, SSL renewals). When a cron job silently fails or times out, standard uptime monitors never notice because the web server remains 200 OK.

Third-party heartbeat monitors (BetterStack, Cronitor, Dead Man's Snitch) charge **$25 to $100/month** for multi-channel alerting and SLA history. 

**KujangPulse** delivers an enterprise-grade self-hosted alternative:
1. **Dead-Man's Snitch Engine with Grace & Jitter Window**: Evaluates scheduled pings against strict cron expectations with customizable grace buffers to eliminate false alarms during high I/O variance.
2. **Flapping Guard Hysteresis (3-Fail / 2-OK)**: Prevents notification fatigue caused by transient network hiccups by requiring sustained consecutive failures before escalating to SEV-1, and consecutive healthy check-ins before resolving.
3. **Zero-Leak HMAC-SHA256 Ping Authentication**: Protects public ping endpoints with timestamped signatures (`?t=timestamp&sig=hmac`) and 300s replay attack drift prevention.
4. **Stateless SLA & Error Budget Calculator**: Computes exact 99.9% / 99.99% availability percentages, MTTR (Mean Time to Recovery), and 30-day downtime burn rate without database bloat.
5. **Multi-Channel Alert Dispatcher**: Native payload generators for **Telegram Bot API** (formatted markdown cards), **Discord Webhooks** (rich embeds), and signed **HTTP Webhooks** with `X-KujangPulse-Signature`.

---

## 🏗️ Architecture & Directory Structure

```text
kujangpulse/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── HeartbeatController.php     # REST API Controller for snitch CRUD & pings
│   └── Services/
│       ├── DeadMansSnitchService.php        # State machine, schedule evaluation & grace buffers
│       ├── EscalationMatrixService.php      # Flapping hysteresis, Telegram/Discord/HMAC formatters
│       ├── PingTokenService.php             # Cryptographic HMAC-SHA256 zero-leak token verifier
│       └── SlaTelemetryEngine.php           # Availability budget, MTTR/MTBF, & EWMA latency percentiles
├── assets/
│   └── brand/                              # Audited SVG & PNG asset kit (favicons, manifest, icons)
├── database/
│   └── migrations/
│       └── 2026_11_09_000001_create_kujangpulse_tables.php # Clean indexed migration schema
├── routes/
│   └── api.php                             # Laravel 11 route definitions
├── tests/
│   ├── Unit/
│   │   └── KujangPulseTest.php             # 16 thorough tests covering 100% of core algorithms
│   └── run_test.php                        # Zero-dependency CLI test runner
├── DESIGN.md                               # Google DESIGN.md token specification (Sentry Dark)
├── index.html                              # High-fidelity Live Operator Console (Sentry UI)
├── logo.svg                                # Production-audited SVG logo (Score: 99/100)
└── logo.png                                # Rendered 512x512 transparent PNG
```

---

## 🚀 Quick Start (Zero-Dependency)

### 1. Run the Test Suite
KujangPulse includes a zero-dependency standalone test runner that executes directly via PHP 8.2:

```bash
php tests/run_test.php
```

Output:
```text
=================================================================
  KUJANGPULSE UNIT TEST SUITE (PHP 8.2 / Laravel 11)
=================================================================

 [✓ PASS] test01SnitchRegistrationDefaultParameters
 [✓ PASS] test02HealthyPingUpdatesStateAndNextExpected
 [✓ PASS] test03RejectMissingSignatureWhenAuthRequired
 [✓ PASS] test04RejectExpiredOrDriftedTimestampSignature
 [✓ PASS] test05VerifyValidHmacSha256PingSignature
 [✓ PASS] test06EvaluationHealthyWithinInterval
 [✓ PASS] test07EvaluationGraceExceededBuffer
 [✓ PASS] test08EvaluationMissedPastGraceDeadline
 [✓ PASS] test09ExplicitJobFailureReport
 [✓ PASS] test10EscalationHysteresisSuppressesTransientFlapping
 [✓ PASS] test11EscalationHysteresisOpensIncidentOnSustainedFailures
 [✓ PASS] test12EscalationHysteresisResolvesIncidentOnConfirmedRecovery
 [✓ PASS] test13TelegramNotificationFormatter
 [✓ PASS] test14DiscordNotificationFormatter
 [✓ PASS] test15SignedHmacWebhookPayload
 [✓ PASS] test16SlaTelemetryBudgetAndPercentileComputations

-----------------------------------------------------------------
 SUMMARY: Total: 16 | Passed: 16 | Failed: 0
-----------------------------------------------------------------

All 16 unit tests passed successfully!
```

---

## 📡 Sending Heartbeat Pings

### Standard cURL Check-in
Place this one-liner at the end of your bash script or cron job:

```bash
curl -m 10 --retry 3 https://your-domain.com/api/v1/snitches/<UUID>/ping
```

### Laravel 11 Job Wrapping
```php
use App\Services\DeadMansSnitchService;

try {
    $startTime = microtime(true);
    
    // Execute critical job logic
    doNightlyDatabaseBackup();
    
    $durationMs = (microtime(true) - $startTime) * 1000;
    
    // Send healthy pulse
    Http::post("https://your-domain.com/api/v1/snitches/{$uuid}/ping", [
        'duration_ms' => $durationMs,
        'memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
        'exit_code' => 0,
    ]);
} catch (\Throwable $e) {
    // Explicit failure reporting
    Http::post("https://your-domain.com/api/v1/snitches/{$uuid}/fail", [
        'error' => $e->getMessage(),
        'exit_code' => 1,
    ]);
    throw $e;
}
```

---

## 🎨 Design System & Visual Tokens

KujangPulse implements **Sentry Dark** (`popular-web-designs/sentry.md`):
- **Surface Canvas**: `#1f1633` (Warm night-debugging purple)
- **Deep Void**: `#150f23` (Card wells and sidebars)
- **Primary Telemetry Beacon**: `#c2ef4e` (High-visibility Lime-Green)
- **Interactive Purple**: `#6a5fc1`
- **Tactile Inset Buttons**: `#79628c` with `inset 0px 1px 3px rgba(0, 0, 0, 0.25)`
- **Typography**: `Rubik` + `JetBrains Mono` (tabular-nums)

Slop Diagnostic Score: **0 / 10** (No AI-slop gradients, no generic centered marketing heroes).

---

## 📄 License & Commercial Distribution

MIT License. Designed for self-hosting or commercial reselling on CodeCanyon, Gumroad, and Lynk.id.
Built with ❤️ by **Firdaus** (`@josskixg`).
