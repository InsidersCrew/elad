---
name: cityos-rep-productivity
description: Rep Productivity Skill of INSIDERS CityOS — the Fit Call district and rep capacity. Use when the Mayor delegates a fit-call / capacity move, or when Elad asks about fit-call conversion, call duration, show rate, scheduling lag, rep utilization, onboarding load on reps, or whether to hire another rep. Triggers on "רובע ההתאמה", "Fit Call", "קיבולת נציגים", "ניצולת", "לגייס נציג", "cityos-rep-productivity".
---

# Rep Productivity Skill — רובע ההתאמה

**Owns:** Rep Capacity Tower, Fit Call Conversion Building, Scheduling Center, Call Time Efficiency Unit (and the rep-time side of Weekly Group Onboarding Hall).
**Levers:** `reps`, `rep_hours_week`, `admin_share`, `call_duration_min`, `call_wrapup_min`, `show_rate`, `fit_conv`, `scheduling_lag_days`, `group_share`, `one_on_one_min`, `followup_min`.

## The capacity equation (from src/engine/model.js)
```
productive minutes = reps × hours/week × 4.33 × 60 × (1 − admin_share)
other load         = handoff conversations × handoff_conv_min
                   + joined × (1 − group_share) × one_on_one_min
                   + joined × followup_min
                   + started × exception_rate × exception_handling_min
fit-call capacity  = (productive − other load) / (call_duration + wrap-up)
utilization        = (calls × minutes_per_call + other load) / productive
```
Above 85% utilization the model applies a conversion penalty (less prep, less follow-up) and scheduling lag grows, which erodes show rate. That is why an overloaded district looks *busy but weak*.

## Inputs
`npm run snapshot` → `facts.rep_utilization`, `calls_capacity`, `calls_demand`, `calls_overflow`, `rep_other_minutes`, `fit_conv_effective`, `show_rate_effective`, `scheduling_lag_effective`, `joined_per_rep`, `seat_contribution`, and the structures of `district_id == "fitcall"`.

## Method — capacity release before headcount
1. Quantify where rep minutes go (fit calls vs. handoff vs. 1:1 onboarding vs. follow-up vs. exceptions vs. admin).
2. Release capacity in cost order: group onboarding (`group_share → 0.8`), call structure (`call_duration_min → 13`, wrap-up automation), AI resolution (cityos-qualification), admin automation (`admin_share`).
3. Only then evaluate `reps + 1` — compare Δcontribution of hiring vs. the released-capacity package: `npm run mayor -- --set reps=5` vs `--set group_share=0.8 --set call_duration_min=13`.
4. Conversion: script, handoff card, digital signature in-call, follow-up within 24h. Target `fit_conv` 34–36%.
5. Show rate: reminders 24h/2h, self-scheduling from the AI, lag ≤ 2 days.

## Output (Hebrew)
- **Capacity diagnosis** — minutes table, utilization, overflow
- **Call structure** — the 13-minute fit call outline (blocks + timings)
- **Scheduling fixes**
- **Capacity release plan** — levers from → to, minutes freed
- **Hire or not** — with the simulated comparison
- **Expected impact** — Δjoined, Δcontribution, utilization after; second-order: onboarding / broker load downstream
- **Handoffs** — cityos-student-success for the group onboarding format; insiders-wp-dev for the rep dashboard changes.
