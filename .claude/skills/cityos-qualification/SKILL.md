---
name: cityos-qualification
description: Qualification Skill of INSIDERS CityOS — the AI/WhatsApp filtering district. Use when the Mayor delegates a qualification move, or when Elad asks about the AI agent, qualification rate, drop-off in the funnel's first stage, objection handling scripts, or the quality of the handoff to a rep. Triggers on "רובע הסינון", "AI Agent", "Qualified rate", "handoff", "cityos-qualification".
---

# Qualification Skill — רובע הסינון

**Owns:** AI Agent Core, Objection Handling Hub, Qualification Logic Engine, Handoff Router.
**Levers:** `qual_rate`, `qual_dropoff`, `ai_resolution_rate`, `qual_time_hours`, `objection_resolve`, `handoff_quality`, `handoff_conv_min`.

## Why this district matters to the whole city
Every lead the AI does not resolve costs `handoff_conv_min` minutes of a rep — time taken directly from fit calls. `facts.human_qual_conversations × handoff_conv_min` is rep capacity burned before a single fit call. And a poor handoff makes fit calls longer and convert worse (two bottlenecks at once).

## Inputs
`npm run snapshot` → `structures[]` with `district_id == "qualification"`, `facts.qualified`, `facts.qual_dropoff_count`, `facts.human_calls_saved`, `facts.rep_utilization`, `facts.call_duration_min`, `facts.fit_conv_effective`.

## Method
1. **Drop-off first** — `qual_dropoff > 30%` is lost money already paid for. Diagnose: response time, message clarity, process length.
2. **AI resolution** — target `ai_resolution_rate ≥ 0.75`. List the top objections / FAQs that currently escalate and script them.
3. **Qualification criteria** — is the filter too strict (low `qual_rate`, high `lead_quality`) or too loose (high `qual_rate`, low `fit_conv`)? Calibrate against fit-call outcomes, never in isolation.
4. **Handoff card** — specify the summary a rep must receive (goal, readiness, objections raised, program terms explained). Target `handoff_quality ≥ 0.75`.
5. **Simulate**: `npm run mayor -- --set ai_resolution_rate=0.75 --set qual_dropoff=0.26 --set handoff_quality=0.78`.

## Output (Hebrew)
- **Diagnosis** with evidence
- **Script changes** (concrete message blocks for the AI agent — hand copy to insiders-copy for polish)
- **Qualification criteria** (a checklist the AI can evaluate)
- **Handoff card spec** (fields + example)
- **Expected impact** — Δqualified, Δrep minutes freed, Δjoined, Δcontribution
- **Handoffs** — cityos-rep-productivity when the constraint moves to fit calls; insiders-customer-journey for the full journey design.
