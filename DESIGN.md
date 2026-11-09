---
version: alpha
name: KujangPulse Sentry Dark
description: Dark-mode-native telemetry & synthetic heartbeat operator console inspired by Sentry (#1f1633 canvas, #150f23 void, #362d59 border, #c2ef4e lime-green telemetry accent, and tactile inset buttons).
colors:
  primary: "#c2ef4e"
  brand-purple: "#6a5fc1"
  surface-canvas: "#1f1633"
  surface-deep: "#150f23"
  surface-elevated: "#291f42"
  surface-button: "#79628c"
  border-subtle: "#362d59"
  border-strong: "#584674"
  text-primary: "#ffffff"
  text-secondary: "#e5e7eb"
  text-muted: "#a5a7b7"
  status-healthy: "#c2ef4e"
  status-warning: "#ffb287"
  status-danger: "#fa7faa"
  code-accent: "#dcdcaa"
typography:
  display-hero:
    fontFamily: Rubik
    fontSize: 2.25rem
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.02em"
  h2:
    fontFamily: Rubik
    fontSize: 1.25rem
    fontWeight: 500
    lineHeight: 1.25
  label-caps:
    fontFamily: Rubik
    fontSize: 0.8125rem
    fontWeight: 600
    letterSpacing: "0.2px"
    textTransform: uppercase
  body:
    fontFamily: Rubik
    fontSize: 0.9375rem
    fontWeight: 400
    lineHeight: 1.5
  telemetry-mono:
    fontFamily: JetBrains Mono
    fontSize: 0.8125rem
    fontWeight: 500
    letterSpacing: "-0.01em"
rounded:
  sm: 6px
  md: 8px
  lg: 12px
  button: 13px
  pill: 9999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
components:
  card:
    backgroundColor: "{colors.surface-deep}"
    borderColor: "{colors.border-subtle}"
    rounded: "{rounded.lg}"
    padding: 20px
  button-primary:
    backgroundColor: "{colors.surface-button}"
    textColor: "{colors.text-primary}"
    borderColor: "{colors.border-strong}"
    rounded: "{rounded.button}"
    padding: "10px 18px"
  button-primary-hover:
    backgroundColor: "{colors.brand-purple}"
  badge-healthy:
    backgroundColor: "rgba(194, 239, 78, 0.12)"
    textColor: "{colors.status-healthy}"
    borderColor: "rgba(194, 239, 78, 0.35)"
---

# KujangPulse Design Specification

## Overview

KujangPulse is an enterprise-grade self-hosted heartbeat, dead-man's snitch, and synthetic SLA monitoring engine for modern microservices and cron jobs. The design identity is rooted in Sentry's dark telemetry environment: an immersive deep purple-black canvas (`#1f1633`, `#150f23`), fine purple-tinted boundary borders (`#362d59`), tactile inset buttons (`#79628c`), and high-visibility lime-green (`#c2ef4e`) telemetry beacons that pierce through dark mode during mission-critical observability.

## Colors

- **Surface Canvas (`#1f1633`)**: The primary dashboard foundation that replaces sterile grays with warm night-debugging tones.
- **Surface Deep (`#150f23`)**: Darker inner card wells and sidebar rails providing optical contrast.
- **Surface Elevated (`#291f42`)**: Hover and active card state.
- **Lime-Green Accent (`#c2ef4e`)**: The primary telemetry signal, used for healthy status pings, 99.99% uptime badges, and key metric readouts.
- **Brand Purple (`#6a5fc1`)**: Interactive links, focus borders, and active tabs.
- **Tactile Button (`#79628c`)**: Physical, clickable control surface with subtle inset illumination.
- **Coral Warning (`#ffb287`)**: Grace-exceeded, high latency jitter, and SLA budget burn notifications.
- **Pink Alert (`#fa7faa`)**: Hard downtime, missed cron triggers, and SEV-1 incidents.

## Typography

- **Primary UI (`Rubik`)**: Clean, rounded geometric grotesque providing high legibility across dense dashboard elements.
- **Uppercase Labels**: Category identifiers and section headers set in `Rubik` 600 weight with `0.2px` tracking.
- **Monospace Telemetry (`JetBrains Mono`)**: Strict tabular numbers (`font-variant-numeric: tabular-nums`) for timestamps, microsecond latency timers, HMAC signatures, and SLA percentiles.

## Layout & Spacing

KujangPulse commits to a **Monitor & Operate** composition:
1. **Top Telemetry Ribbon**: Real-time fleet health, aggregate SLA, rolling incident count, and MTTR.
2. **Split Operational Workspace**: 
   - Left Column: Interactive Dead-Man's Snitch test harness, cron expression simulator, and HMAC ping injector.
   - Right Column: Incident Escalation Matrix (Telegram/Discord/HMAC Webhook dispatch preview) and 30-day error budget calculator.
3. **Bottom Ledger**: Live snitch health grid with visual heartbeat sparklines and execution duration telemetry.

## Elevation & Depth

- **Tactile Inset (Buttons)**: `box-shadow: inset 0px 1px 3px 0px rgba(0, 0, 0, 0.25)`.
- **Surfaces & Cards**: Defined by 1px subtle borders (`#362d59`) without muddy drop shadows.
- **Hover Elevation**: Glow transition using purple ambient radiance `rgba(106, 95, 193, 0.18) 0px 8px 24px`.

## Components

- **Telemetry Capsule**: Pill container displaying real-time ping latency, exit codes, and jitter tolerance.
- **Tactile Inset Button**: Distinctive Sentry button with 13px border radius, 0.2px letter spacing, and uppercase text.
- **Hysteresis Incident Tracker**: State machine demonstrating 3-failure flapping dampening before escalation.

## Do's and Don'ts

### Do
- Always use deep purple `#1f1633` rather than generic `#000000` or `#111827`.
- Use the `#c2ef4e` lime green selectively for operational heartbeat indicators.
- Maintain tabular-nums for all latency, timestamps, and SLA computations.
- Utilize uppercase 0.2px tracked micro-labels for telemetry categories.

### Don't
- Do not introduce AI-slop gradients or generic centered marketing heroes.
- Do not use neutral cool gray borders (`#6b7280`); use purple-tinted `#362d59`.
- Do not mix lime-green with coral accents within the same component tile.
