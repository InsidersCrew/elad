---
name: cityos-student-success
description: Student Success Skill of INSIDERS CityOS — onboarding and student success districts. Use when the Mayor delegates an activation / engagement / retention move, or when Elad asks about onboarding attendance, dormant students (joined but not started), dashboard login, community entry, check-ins, at-risk students, re-activation, clinics, engagement or satisfaction. Triggers on "אונבורדינג", "רדומים", "Started rate", "at-risk", "מעורבות", "רובע ההצלחה", "cityos-student-success".
---

# Student Success Skill — אונבורדינג והצלחת תלמידים

**Owns:** Weekly Group Onboarding Hall, Welcome Automation Center, Course Access Terminal, Community Entry Building, Check-in Office, At-Risk Rescue Center, Clinic Room, Engagement Monitor.
**Levers:** `onboarding_attendance`, `started_rate`, `login_rate`, `group_share`, `community_join`, `time_to_start_days`, `engagement_rate`, `at_risk_rate`, `reactivation_rate`, `checkin_coverage`, `clinic_attendance`, `satisfaction`.

## Why this matters economically
A student who joined and never started (`dormant_students = joined − started`) is CAC paid with zero revenue: no account opened, and the non-open charge is the hardest to collect from someone who never engaged. `started_rate` is one of the highest-elasticity levers in the city. Engagement feeds the two high-margin engines downstream: coaching deals and referrals.

## Inputs
`npm run snapshot` → structures of `district_id in ("onboarding","success")`, `facts.dormant_students`, `facts.inactive_7d`, `facts.one_on_one_sessions`, `facts.engaged_students`, `facts.net_churn_risk`, `facts.rep_utilization`.

## Method
1. **Activation funnel**: joined → attended onboarding → logged in → started (≤7 days). Find the biggest step loss.
2. **Group by default**: onboarding in weekly groups (2 slots), registration inside the fit call, reminders; 1:1 only for exceptions. This also releases rep capacity (see cityos-rep-productivity).
3. **Welcome sequence**: immediate access link, a 10-minute first task, day-2 and day-5 nudges, dashboard login as the activation event.
4. **Dormant rescue**: day-3 five-minute call + "restart group".
5. **Success loop**: automated check-ins (day 7/21/45) → at-risk flag → rescue playbook (restart clinic + 14-day track) → re-activation.
6. Simulate: `npm run mayor -- --set onboarding_attendance=0.72 --set started_rate=0.8 --set group_share=0.8`.

## Output (Hebrew)
- **Activation diagnosis** (step-loss table)
- **Onboarding plan** (format, cadence, message sequence — copy via insiders-copy / insiders-email-marketing)
- **At-risk playbook** (triggers, owner, SLA)
- **Engagement program** (30-day track, clinics, community rituals)
- **Expected impact** — Δstarted, Δopened, Δcontribution, rep minutes freed
- **Handoffs** — insiders-customer-journey for journey design, insiders-wp-dev (student-progress-dashboard) for tracking events.
