---
name: cityos-referral
description: Referral Skill of INSIDERS CityOS — the district that closes the loop back to the city gate. Use when the Mayor delegates a referral move, or when Elad asks about referral rate, referral requests, advocacy triggers, referral tracking / attribution, referral-to-join, or effective CAC including referrals. Triggers on "רובע ההפניות", "הפניות", "referral", "ממליצים", "CAC אפקטיבי", "cityos-referral".
---

# Referral Skill — רובע ההפניות

**Owns:** Referral Engine, Advocate Trigger Hub, Referral Tracking Center, and the Referral Gate in Acquisition.
**Levers:** `referral_request_rate`, `referral_rate`, `referrals_per_advocate`, `referral_quality_mult`, `referral_tracking_coverage`.

## Why it matters
`leads_referral = active_students × referral_request_rate × referral_rate × referrals_per_advocate` and referral leads qualify at `qual_rate × referral_quality_mult` — the cheapest and best-converting lead in the city. A working loop lowers `effective_cac` for everyone and reduces dependence on paid ads. Untracked referrals get counted as organic, so the engine looks smaller than it is and advocates cannot be rewarded.

## Inputs
`npm run snapshot` → structures of `district_id == "referral"` + `referral_gate`, `facts.referral_requests`, `advocates`, `leads_referral`, `referral_to_join`, `referral_tracked`, `effective_cac`, `satisfaction`, `opened`.

## Method
1. **Ask more**: the request rate is the free lever — target `referral_request_rate ≥ 0.5` with 3 automated trigger moments (account opened, first profit / milestone, great clinic feedback).
2. **Make it easy + worth it**: personal link, two-sided benefit, presented in clinics and in the community. Target `referral_rate ≥ 0.18`.
3. **Track**: personal links, "who referred you" field, CRM sync (Pipedrive), `referral_tracking_coverage ≥ 0.9`.
4. **Quality**: keep referral leads on a fast lane in qualification (they are warmer).
5. Simulate: `npm run mayor -- --set referral_request_rate=0.5 --set referral_rate=0.18 --set referral_tracking_coverage=0.9`.

## Output (Hebrew)
- **Loop diagnosis** (requests → advocates → referrals → joined, with tracking gap)
- **Trigger moments** (event, message, channel, owner)
- **Incentive design** (benefit, budget vs. CAC saved)
- **Tracking spec** (fields, links, CRM mapping — insiders-wp-core / Pipedrive notes)
- **Expected impact** — Δreferral leads, Δjoined, Δeffective CAC
- **Handoffs** — insiders-copy / insiders-creative for the referral messages, cityos-acquisition for the gate.
