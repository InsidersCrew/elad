---
name: cityos-advanced-growth
description: Advanced Growth Skill of INSIDERS CityOS — the Growth & Upgrade district. Use when the Mayor delegates an upsell / coaching move, or when Elad asks about professional-trader appointments, personal coaching deals, deal conversion, revenue per deal, rep bonuses for advanced deals, the advanced route pipeline, or the economics of advanced services. Triggers on "רובע ההמשך", "אימון אישי", "סוחר מקצועי", "upsell", "מסלול מתקדם", "cityos-advanced-growth".
---

# Advanced Growth Skill — רובע ההמשך והשדרוג

**Owns:** Professional Trader Matching Office, Personal Coaching Deals Desk, Advanced Opportunity Tracker.
**Levers:** `pro_appointment_rate`, `coaching_conv`, `revenue_per_deal`, `rep_bonus_per_deal`, `coaching_delivery_cost_share`, `advanced_candidate_rate`.

## Why it matters
Coaching is the highest-margin revenue in the city (`coaching_contribution = revenue − delivery cost − bonuses`). Its pipeline is `engaged_students × pro_appointment_rate × coaching_conv`, so it depends on Student Success upstream — do not promise growth here if engagement is falling.

## Inputs
`npm run snapshot` → structures of `district_id == "growth"`, `facts.engaged_students`, `pro_appointments`, `coaching_deals`, `coaching_revenue`, `coaching_contribution`, `advanced_candidates`, `bonus_per_rep`.

## Method
1. **Pipeline math**: appointments → deals → ₪. Which step is thin?
2. **Trigger moments**: offer after a milestone (first profitable month, account funded, clinic streak) — not by calendar. Target `pro_appointment_rate ≥ 0.10`.
3. **Meeting structure**: diagnostic → plan → written offer → 48h follow-up. Target `coaching_conv ≥ 0.42`.
4. **Offer design**: packages, `revenue_per_deal` from → to, delivery cost share, rep bonus that aligns with contribution (not just revenue).
5. **Candidate scoring** in the rep dashboard (insiders-wp-dev) so advanced candidates are visible: `advanced_candidate_rate ≥ 0.2`.
6. Simulate: `npm run mayor -- --set pro_appointment_rate=0.095 --set coaching_conv=0.42 --set revenue_per_deal=7500`.

## Output (Hebrew)
- **Pipeline diagnosis** (numbers per step, contribution per deal)
- **Offer design** (packages, price points, delivery model, margin)
- **Meeting structure** (script outline — copy via insiders-copy)
- **Rep incentives** (bonus per deal vs. contribution, dashboard scoring)
- **Expected impact** — Δdeals, Δcoaching contribution, Δbonus per rep
- **Handoffs** — cityos-student-success (engagement upstream), cityos-economics (delivery cost, bonus policy).
