---
name: cityos-broker-handoff
description: Broker Handoff Skill of INSIDERS CityOS — the account-opening district. Use when the Mayor delegates an open-rate / time-to-open move, or when Elad asks about account opening rate, time to open, broker SLA, exceptions that return to reps, documents / first deposit blockers, or the handoff from INSIDERS to the broker. Triggers on "פתיחת חשבון", "Open rate", "ברוקר", "SLA", "חריגים", "cityos-broker-handoff".
---

# Broker Handoff Skill — רובע פתיחת החשבון

**Owns:** Broker Routing Hub, Open Account Assistance Desk, Exceptions Queue, SLA Monitor.
**Levers:** `open_rate`, `time_to_open_days`, `exception_rate`, `exception_handling_min`, `broker_sla_target_days`, `broker_sla_actual_days`.

## Why this is the biggest jump in the city
`open_rate` decides between `revenue_per_open` (≈₪2,800) and a ₪980 non-open charge that is only partly collected. Every day added to `time_to_open_days` cools the student and raises exceptions; every exception costs rep minutes (`exception_handling_min`) taken from fit calls. The broker SLA is an *external* dependency — the city cannot fix it directly, only manage it.

## Inputs
`npm run snapshot` → structures of `district_id == "broker"`, `facts.opened`, `not_opened`, `exceptions`, `broker_sla_gap_days`, `time_to_open_days`, `revenue_per_open`, `non_open_charge_rate`.

## Method
1. **Where do they stall?** Split not-opened into: never started the broker flow / stuck on documents / stuck on first deposit / broker delay. Use exception categories from the CRM if available; otherwise state it as "לא נמדד".
2. **Open Day**: guided opening inside a session (screen-share or group), documents checklist sent *before*, technical-help call, 48-hour follow-up. Target `time_to_open_days ≤ 7`, `open_rate ≥ 0.6`.
3. **Exceptions ≤ 12%**: video guides for the recurring blockers, pre-validation of documents, a single owner for the exceptions queue.
4. **Broker SLA**: dedicated contact, weekly SLA report, escalation path; negotiate the SLA and the payout delay (`broker_payout_delay_days`) together with cityos-economics.
5. Simulate: `npm run mayor -- --set open_rate=0.6 --set time_to_open_days=7 --set exception_rate=0.12`.

## Output (Hebrew)
- **Handoff diagnosis** (stall map with numbers)
- **Open-assist flow** (steps, owner, materials, SLA)
- **Exception reduction** plan
- **Broker SLA plan** (contact, report, escalation, negotiation asks)
- **Expected impact** — Δopened, Δrevenue, Δrep minutes, Δcash timing
- **Handoffs** — cityos-economics for payout terms and the non-open charge path; cityos-student-success when the stall is motivational rather than technical.
