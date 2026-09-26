/**
 * The operating model of INSIDERS as a flow: leads → qualified → fit calls →
 * joined → started → opened / non-open charge → success → advanced → referrals
 * (which loop back to the city gate).
 *
 * `simulate(inputs)` is pure: same inputs → same derived facts. Everything the
 * city renders (throughput, load, capacity, economics) is derived here, which is
 * what lets scenario mode re-shape the whole city from a single lever change.
 */

const WEEKS_PER_MONTH = 4.33;

function clamp(v, lo, hi) {
  return Math.min(hi, Math.max(lo, v));
}

/**
 * @param {Record<string, number>} inp lever values
 * @returns {Record<string, number>} derived facts (all inputs are echoed back too)
 */
export function simulate(inp) {
  const d = { ...inp };

  // ── Referral loop → city gate ─────────────────────────────────
  d.leads_referral = inp.active_students * inp.referral_request_rate * inp.referral_rate * inp.referrals_per_advocate;
  d.leads_total = inp.leads_paid + inp.leads_organic + d.leads_referral;
  d.visits = inp.landing_conv > 0 ? inp.leads_paid / inp.landing_conv : 0;
  d.ad_spend = inp.leads_paid * inp.cost_per_lead;
  d.paid_share = d.leads_total > 0 ? inp.leads_paid / d.leads_total : 0;
  d.organic_share = d.leads_total > 0 ? inp.leads_organic / d.leads_total : 0;
  d.referral_share = d.leads_total > 0 ? d.leads_referral / d.leads_total : 0;
  d.whatsapp_leads = d.leads_total * inp.whatsapp_share;

  // ── Qualification ────────────────────────────────────────────
  const referralQualRate = clamp(inp.qual_rate * inp.referral_quality_mult, 0, 0.95);
  d.qualified = (inp.leads_paid + inp.leads_organic) * inp.qual_rate + d.leads_referral * referralQualRate;
  d.qual_rate_effective = d.leads_total > 0 ? d.qualified / d.leads_total : 0;
  d.qual_dropoff_count = d.leads_total * inp.qual_dropoff;
  d.human_qual_conversations = d.leads_total * (1 - inp.ai_resolution_rate);
  d.human_calls_saved = d.leads_total * inp.ai_resolution_rate;
  d.ai_handoff_rate = 1 - inp.ai_resolution_rate;

  // ── Rep capacity (minutes / month) ───────────────────────────
  d.rep_minutes_total = inp.reps * inp.rep_hours_week * WEEKS_PER_MONTH * 60;
  d.rep_minutes_productive = d.rep_minutes_total * (1 - inp.admin_share);
  const minutesPerCall = inp.call_duration_min + inp.call_wrapup_min;

  // Fit call demand before capacity effects
  d.calls_demand = d.qualified * inp.show_rate;

  // Fixed-point iteration: load depends on joined, joined depends on load.
  let joined = d.calls_demand * inp.fit_conv;
  let utilization = 0;
  let callsCompleted = d.calls_demand;
  let fitConvEff = inp.fit_conv;
  let showEff = inp.show_rate;
  let lagEff = inp.scheduling_lag_days;
  let capacityCalls = 0;
  let otherMinutes = 0;
  for (let i = 0; i < 4; i++) {
    const started = joined * inp.started_rate;
    const handoffMin = d.human_qual_conversations * inp.handoff_conv_min;
    const onboardingMin = joined * (1 - inp.group_share) * inp.one_on_one_min;
    const followupMin = joined * inp.followup_min;
    const exceptionMin = started * inp.exception_rate * inp.exception_handling_min;
    otherMinutes = handoffMin + onboardingMin + followupMin + exceptionMin;

    capacityCalls = Math.max(0, (d.rep_minutes_productive - otherMinutes) / minutesPerCall);

    // Scheduling lag grows once reps are busy; show rate erodes with lag.
    const preUtil = d.rep_minutes_productive > 0
      ? (d.calls_demand * minutesPerCall + otherMinutes) / d.rep_minutes_productive
      : 2;
    lagEff = inp.scheduling_lag_days * (1 + 2 * Math.max(0, preUtil - 0.7));
    showEff = inp.show_rate * (1 - 0.04 * Math.max(0, lagEff - 2));
    const demandEff = d.qualified * showEff;
    callsCompleted = Math.min(demandEff, capacityCalls);

    utilization = d.rep_minutes_productive > 0
      ? (callsCompleted * minutesPerCall + otherMinutes) / d.rep_minutes_productive
      : 2;

    // Overloaded reps convert worse (less prep, less follow-up).
    fitConvEff = inp.fit_conv * (1 - 0.4 * Math.max(0, utilization - 0.85));
    joined = callsCompleted * fitConvEff;
  }

  d.minutes_per_call = minutesPerCall;
  d.calls_capacity = capacityCalls;
  d.calls_completed = callsCompleted;
  d.calls_overflow = Math.max(0, d.qualified * showEff - callsCompleted);
  d.show_rate_effective = showEff;
  d.scheduling_lag_effective = lagEff;
  d.fit_conv_effective = fitConvEff;
  d.rep_utilization = utilization;
  d.rep_other_minutes = otherMinutes;
  d.rep_fit_minutes = callsCompleted * minutesPerCall;
  d.calls_per_rep = inp.reps > 0 ? callsCompleted / inp.reps : 0;
  d.fit_call_load_ratio = capacityCalls > 0 ? (d.qualified * showEff) / capacityCalls : 2;
  d.joined = joined;
  d.joined_per_rep = inp.reps > 0 ? joined / inp.reps : 0;
  d.rep_headroom_calls = Math.max(0, capacityCalls - callsCompleted);
  d.fitcall_leak = Math.max(0, d.qualified - joined);

  // ── Onboarding ───────────────────────────────────────────────
  d.started = d.joined * inp.started_rate;
  d.onboarding_attended = d.joined * inp.onboarding_attendance;
  d.inactive_7d = 1 - inp.started_rate;
  d.dormant_students = d.joined - d.started;
  d.one_on_one_sessions = d.joined * (1 - inp.group_share);
  d.logged_in = d.joined * inp.login_rate;
  d.community_joined = d.joined * inp.community_join;

  // ── Broker handoff ───────────────────────────────────────────
  d.opened = d.started * inp.open_rate;
  d.not_opened = d.started - d.opened;
  d.exceptions = d.started * inp.exception_rate;
  d.broker_sla_gap_days = inp.broker_sla_actual_days - inp.broker_sla_target_days;
  d.broker_sla_ratio = inp.broker_sla_target_days > 0 ? inp.broker_sla_actual_days / inp.broker_sla_target_days : 1;

  // ── Monetization ─────────────────────────────────────────────
  d.open_revenue = d.opened * inp.revenue_per_open;
  d.non_open_chargeable = d.not_opened * (1 - inp.waiver_rate);
  d.non_open_collected_count = d.non_open_chargeable * inp.collection_rate;
  d.non_open_revenue = d.non_open_collected_count * inp.non_open_charge;
  d.waived_value = d.not_opened * inp.waiver_rate * inp.non_open_charge;
  d.uncollected_value = d.non_open_chargeable * (1 - inp.collection_rate) * inp.non_open_charge;
  d.non_open_charge_rate = d.not_opened > 0 ? d.non_open_collected_count / d.not_opened : 0;
  d.revenue_leakage_count = Math.max(0, d.not_opened - d.non_open_collected_count);
  d.core_revenue = d.open_revenue + d.non_open_revenue;
  d.revenue_per_student = d.joined > 0 ? d.core_revenue / d.joined : 0;
  d.revenue_per_started = d.started > 0 ? d.core_revenue / d.started : 0;

  // ── Student success ──────────────────────────────────────────
  d.engaged_students = inp.active_students * inp.engagement_rate;
  d.at_risk_students = inp.active_students * inp.at_risk_rate;
  d.reactivated_students = d.at_risk_students * inp.reactivation_rate;
  d.checkins_done = inp.active_students * inp.checkin_coverage;
  d.clinic_attendees = inp.active_students * inp.clinic_attendance;
  d.net_churn_risk = d.at_risk_students - d.reactivated_students;

  // ── Growth & upgrade ─────────────────────────────────────────
  d.pro_appointments = d.engaged_students * inp.pro_appointment_rate;
  d.coaching_deals = d.pro_appointments * inp.coaching_conv;
  d.coaching_revenue = d.coaching_deals * inp.revenue_per_deal;
  d.coaching_delivery_cost = d.coaching_revenue * inp.coaching_delivery_cost_share;
  d.rep_bonus_total = d.coaching_deals * inp.rep_bonus_per_deal;
  d.coaching_contribution = d.coaching_revenue - d.coaching_delivery_cost - d.rep_bonus_total;
  d.advanced_candidates = d.engaged_students * inp.advanced_candidate_rate;
  d.bonus_per_rep = inp.reps > 0 ? d.rep_bonus_total / inp.reps : 0;

  // ── Referral (as seen from the referral district) ────────────
  d.referral_requests = inp.active_students * inp.referral_request_rate;
  d.advocates = d.referral_requests * inp.referral_rate;
  d.referral_joined = d.leads_referral * referralQualRate * showEff * fitConvEff;
  d.referral_to_join = d.leads_referral > 0 ? d.referral_joined / d.leads_referral : 0;
  d.referral_tracked = d.leads_referral * inp.referral_tracking_coverage;

  // ── Economics ────────────────────────────────────────────────
  d.rep_cost_total = inp.reps * inp.rep_cost_month;
  d.total_revenue = d.core_revenue + d.coaching_revenue;
  d.variable_costs = d.ad_spend + d.coaching_delivery_cost + d.rep_bonus_total;
  d.people_costs = d.rep_cost_total + inp.ai_agent_cost_month;
  d.total_costs = d.variable_costs + d.people_costs + inp.fixed_costs_month;
  d.contribution = d.total_revenue - d.variable_costs - d.people_costs;
  d.net = d.total_revenue - d.total_costs;
  d.contribution_margin = d.total_revenue > 0 ? d.contribution / d.total_revenue : -1;
  d.net_margin = d.total_revenue > 0 ? d.net / d.total_revenue : -1;
  d.cac = d.joined > 0 ? (d.ad_spend + d.people_costs) / d.joined : Infinity;
  d.cac_paid_only = d.joined > 0 ? d.ad_spend / d.joined : Infinity;
  d.cost_per_qualified = d.qualified > 0 ? d.ad_spend / d.qualified : Infinity;
  d.cost_per_opened = d.opened > 0 ? (d.ad_spend + d.people_costs) / d.opened : Infinity;
  d.contribution_per_student = d.joined > 0 ? (d.core_revenue - d.ad_spend - d.people_costs) / d.joined : 0;
  d.effective_cac = d.joined > 0 ? (d.ad_spend + d.people_costs) / (d.joined + d.referral_joined) : Infinity;
  d.cost_per_seat = inp.reps > 0 ? (inp.rep_cost_month) : 0;
  d.seat_contribution = inp.reps > 0 ? (d.core_revenue - d.ad_spend) / inp.reps - inp.rep_cost_month : 0;

  // ── Cash timing ──────────────────────────────────────────────
  d.cash_tied_up = d.open_revenue * (inp.broker_payout_delay_days / 30) + d.non_open_revenue * (inp.cash_delay_days / 30);
  d.weighted_cash_delay_days = d.core_revenue > 0
    ? (d.open_revenue * inp.broker_payout_delay_days + d.non_open_revenue * inp.cash_delay_days) / d.core_revenue
    : 0;
  d.cash_conversion = d.core_revenue > 0 ? 1 - Math.min(1, d.weighted_cash_delay_days / 90) : 0;
  d.revenue_leakage = d.waived_value + d.uncollected_value;

  // ── City-level ───────────────────────────────────────────────
  d.lead_to_joined = d.leads_total > 0 ? d.joined / d.leads_total : 0;
  d.lead_to_opened = d.leads_total > 0 ? d.opened / d.leads_total : 0;
  d.joined_to_opened = d.joined > 0 ? d.opened / d.joined : 0;
  d.throughput_index = d.joined;

  return d;
}

/**
 * Time series: run the model per period and return arrays of derived facts.
 * @param {{inputs:Record<string,number>, history?:Record<string,number[]>, period:{labels:string[]}}} model
 */
export function simulateHistory(model) {
  const n = model.period.labels.length;
  const series = [];
  for (let i = 0; i < n; i++) {
    const inputs = inputsAtPeriod(model, i);
    series.push(simulate(inputs));
  }
  return series;
}

export function inputsAtPeriod(model, i) {
  const inputs = { ...model.inputs };
  const hist = model.history ?? {};
  for (const [k, arr] of Object.entries(hist)) {
    if (Array.isArray(arr) && arr.length > i && typeof arr[i] === 'number') inputs[k] = arr[i];
  }
  return inputs;
}
