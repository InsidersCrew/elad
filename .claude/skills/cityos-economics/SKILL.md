---
name: cityos-economics
description: Economics Skill of INSIDERS CityOS — monetization district and City Hall economics. Use when the Mayor delegates a revenue / collection / cash move, or when Elad asks about revenue per student, contribution margin, CAC vs revenue, seat economics per rep, non-open charge collection, waivers, cash delay, cash tied up, revenue leakage, or whether the city is profitable. Triggers on "רובע המימוש", "כלכלת העיר", "CAC", "תרומה", "גבייה", "ויתורים", "תזרים", "cityos-economics".
---

# Economics Skill — כלכלת העיר

**Owns:** Account Revenue Tower, Non-Open Charge Processing Center, Collections Unit, Waiver Control Office, Strategy War Room, Risk Observatory.
**Levers:** `revenue_per_open`, `non_open_charge`, `waiver_rate`, `collection_rate`, `cash_delay_days`, `broker_payout_delay_days`, `rep_cost_month`, `ai_agent_cost_month`, `fixed_costs_month`, `coaching_delivery_cost_share`, `rep_bonus_per_deal`.

## The unit economics (src/engine/model.js)
```
core revenue        = opened × revenue_per_open + non-open collected × non_open_charge
non-open collected  = not_opened × (1 − waiver_rate) × collection_rate
revenue leakage     = waived value + uncollected value
CAC                 = (ad spend + rep cost + AI cost) / joined
contribution        = total revenue − ad spend − delivery cost − bonuses − rep cost − AI cost
net                 = contribution − fixed costs
cash tied up        = open revenue × payout delay/30 + non-open revenue × collection delay/30
```

## Inputs
`npm run snapshot` → `facts.*revenue*`, `contribution*`, `net*`, `cac`, `revenue_per_student`, `contribution_per_student`, `seat_contribution`, `revenue_leakage`, `cash_tied_up`, `weighted_cash_delay_days`, and structures of `district_id in ("monetization","cityhall")`.

## Method
1. **Where is the margin lost?** CAC vs revenue per student; then revenue leakage (waivers + uncollected) — the cheapest money to recover, the customer already exists.
2. **Charge path that actually works**: payment method captured at joining, automatic charge on day 30, 3 reminders, instalments; waiver policy = manager approval + 3 criteria. Targets: `collection_rate ≥ 0.85`, `waiver_rate ≤ 0.12`, `cash_delay_days ≤ 20`.
3. **Terms**: broker revenue per open and payout delay negotiated together (cityos-broker-handoff holds the relationship).
4. **Seat economics**: `seat_contribution` per rep must cover `rep_cost_month` with margin before any hire is approved (cityos-rep-productivity).
5. **Cash**: growth increases cash tied up — quantify the working capital needed for a scenario before recommending it.
6. Simulate: `npm run mayor -- --set collection_rate=0.85 --set waiver_rate=0.12 --set cash_delay_days=20`.

## Output (Hebrew)
- **Unit economics** table (per lead, per qualified, per joined, per opened; CAC; margin)
- **Leakage plan** (waiver policy, collection automation, expected ₪ recovered)
- **Cash plan** (delays, tied-up cash now vs. after, working capital for growth scenarios)
- **Pricing / terms** asks (broker, charge amount, instalments)
- **Expected impact** — Δcontribution, Δnet, Δcash tied up
- **Red team** — what would make this wrong (e.g. collection pressure raising churn or complaints).
