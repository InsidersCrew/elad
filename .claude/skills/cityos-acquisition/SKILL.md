---
name: cityos-acquisition
description: Acquisition Skill of INSIDERS CityOS — the city gate. Use when the Mayor delegates an acquisition move, or when Elad asks about leads, CPL, lead quality, channel mix (paid / organic / referral), landing-page conversion or WhatsApp entry in the context of the CityOS model. Triggers on "שער הכניסה", "Acquisition District", "CPL בעיר", "תמהיל ערוצים", "cityos-acquisition".
---

# Acquisition Skill — שער הכניסה לעיר

**Owns:** Paid Ads Tower, Organic Content Hub, Landing Page Center, Referral Gate (with cityos-referral), WhatsApp Entry Point.
**Levers:** `leads_paid`, `leads_organic`, `cost_per_lead`, `landing_conv`, `lead_quality`, `whatsapp_share`, `wa_first_response_min`.

## Inputs
Run `npm run snapshot` and read `structures[]` where `district_id == "acquisition"`, plus `facts.leads_total`, `facts.cost_per_qualified`, `facts.cac`, `facts.rep_utilization` (the gate must never be opened wider than the Fit Call district can absorb).

## Method
1. **Volume vs quality**: leads up but `lead_quality` / `qual_rate` down = the gate is letting in the wrong people. Check `cost_per_qualified`, not only CPL.
2. **Channel dependence**: `organic_share < 30%` or `referral_share < 5%` = structural risk; every CPL increase hits contribution directly.
3. **Capacity check before scaling**: simulate `leads_paid × 1.2` with `npm run mayor -- --set leads_paid=<n>`; if `rep_utilization > 0.9` the correct move is not more leads.
4. **Speed**: `wa_first_response_min > 3` predicts qualification drop-off; fix response before spend.

## Output (Hebrew)
- **Diagnosis** — what is wrong at the gate and why (evidence values).
- **Channel-mix recommendation** — target shares for paid / organic / referral for the next quarter.
- **CPL plan** — creative, audiences, landing CRO; lever targets (CPL from → to).
- **Quality actions** — pre-qualification, form questions, messaging alignment with the AI agent.
- **Expected impact** — Δleads, Δqualified, Δcontribution (simulated), second-order effect on rep load.
- **Handoffs** — insiders-meta-ads for paid execution, insiders-youtube for organic, cityos-referral for the referral gate.
