/**
 * Lever definitions — every input of the operating model.
 * Each lever is a variable the user can move in scenario mode.
 * `fmt` controls display: pct | int | money | num | min | days | hours | score5
 */
export const LEVER_GROUPS = [
  { id: 'acquisition', label: 'שער הכניסה (Acquisition)', district: 'acquisition' },
  { id: 'qualification', label: 'סינון (Qualification)', district: 'qualification' },
  { id: 'fitcall', label: 'שיחות התאמה (Fit Call)', district: 'fitcall' },
  { id: 'onboarding', label: 'אונבורדינג (Onboarding)', district: 'onboarding' },
  { id: 'broker', label: 'פתיחת חשבון (Broker Handoff)', district: 'broker' },
  { id: 'monetization', label: 'מימוש כלכלי (Monetization)', district: 'monetization' },
  { id: 'success', label: 'הצלחת תלמידים (Student Success)', district: 'success' },
  { id: 'growth', label: 'המשך ושדרוג (Growth & Upgrade)', district: 'growth' },
  { id: 'referral', label: 'הפניות (Referral)', district: 'referral' },
  { id: 'economics', label: 'כלכלה ומשאבים (City Hall)', district: 'cityhall' },
];

/** @type {Record<string, {label:string, group:string, fmt:string, min:number, max:number, step:number, better?:'up'|'down', scenario?:boolean, desc?:string}>} */
export const LEVERS = {
  // ── Acquisition ──────────────────────────────────────────────
  leads_paid: { label: 'לידים ממומנים / חודש', group: 'acquisition', fmt: 'int', min: 0, max: 5000, step: 10, better: 'up', scenario: true },
  leads_organic: { label: 'לידים אורגניים / חודש', group: 'acquisition', fmt: 'int', min: 0, max: 3000, step: 10, better: 'up', scenario: true },
  cost_per_lead: { label: 'עלות לליד (CPL)', group: 'acquisition', fmt: 'money', min: 5, max: 300, step: 1, better: 'down', scenario: true },
  landing_conv: { label: 'המרת דף נחיתה', group: 'acquisition', fmt: 'pct', min: 0.005, max: 0.3, step: 0.001, better: 'up' },
  lead_quality: { label: 'מדד איכות ליד', group: 'acquisition', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  whatsapp_share: { label: 'נתח כניסה דרך WhatsApp', group: 'acquisition', fmt: 'pct', min: 0, max: 1, step: 0.01 },
  wa_first_response_min: { label: 'זמן תגובה ראשון ב-WhatsApp (דק׳)', group: 'acquisition', fmt: 'min', min: 0, max: 120, step: 0.5, better: 'down' },

  // ── Qualification ────────────────────────────────────────────
  qual_rate: { label: 'שיעור Qualified מתוך לידים', group: 'qualification', fmt: 'pct', min: 0.05, max: 0.9, step: 0.01, better: 'up', scenario: true },
  qual_dropoff: { label: 'Drop-off בסינון', group: 'qualification', fmt: 'pct', min: 0, max: 0.8, step: 0.01, better: 'down', scenario: true },
  ai_resolution_rate: { label: 'שיחות שה-AI סוגר לבד', group: 'qualification', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  qual_time_hours: { label: 'זמן ממוצע עד Qualification (שעות)', group: 'qualification', fmt: 'hours', min: 0.1, max: 72, step: 0.1, better: 'down' },
  objection_resolve: { label: 'פתרון התנגדויות בסיסיות', group: 'qualification', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  handoff_quality: { label: 'איכות Handoff לנציג', group: 'qualification', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  handoff_conv_min: { label: 'דק׳ נציג לשיחת handoff', group: 'qualification', fmt: 'min', min: 0, max: 60, step: 1, better: 'down' },

  // ── Fit Call ─────────────────────────────────────────────────
  reps: { label: 'מספר נציגים', group: 'fitcall', fmt: 'int', min: 1, max: 20, step: 1, scenario: true },
  rep_hours_week: { label: 'שעות עבודה לנציג / שבוע', group: 'fitcall', fmt: 'num', min: 5, max: 50, step: 1, scenario: true },
  admin_share: { label: 'נתח זמן אדמין / לא-יצרני', group: 'fitcall', fmt: 'pct', min: 0, max: 0.7, step: 0.01, better: 'down' },
  call_duration_min: { label: 'משך שיחת התאמה (דק׳)', group: 'fitcall', fmt: 'min', min: 5, max: 60, step: 0.5, better: 'down', scenario: true },
  call_wrapup_min: { label: 'זמן סיכום אחרי שיחה (דק׳)', group: 'fitcall', fmt: 'min', min: 0, max: 30, step: 1, better: 'down' },
  show_rate: { label: 'שיעור הגעה לשיחת התאמה', group: 'fitcall', fmt: 'pct', min: 0.1, max: 1, step: 0.01, better: 'up', scenario: true },
  fit_conv: { label: 'המרה Fit Call → Joined', group: 'fitcall', fmt: 'pct', min: 0.02, max: 0.8, step: 0.01, better: 'up', scenario: true },
  scheduling_lag_days: { label: 'ימים עד שיחה מתוזמנת', group: 'fitcall', fmt: 'days', min: 0, max: 14, step: 0.1, better: 'down' },

  // ── Onboarding ───────────────────────────────────────────────
  onboarding_attendance: { label: 'נוכחות באונבורדינג קבוצתי', group: 'onboarding', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  started_rate: { label: 'Joined → Started', group: 'onboarding', fmt: 'pct', min: 0.05, max: 1, step: 0.01, better: 'up', scenario: true },
  login_rate: { label: 'כניסה לדשבורד', group: 'onboarding', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  group_share: { label: 'נתח אונבורדינג בקבוצה (לא 1:1)', group: 'onboarding', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  one_on_one_min: { label: 'דק׳ נציג לאונבורדינג 1:1', group: 'onboarding', fmt: 'min', min: 0, max: 120, step: 5, better: 'down' },
  followup_min: { label: 'דק׳ follow-up לתלמיד חדש', group: 'onboarding', fmt: 'min', min: 0, max: 90, step: 5, better: 'down' },
  community_join: { label: 'הצטרפות לקהילה', group: 'onboarding', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  time_to_start_days: { label: 'ימים עד התחלה בפועל', group: 'onboarding', fmt: 'days', min: 0, max: 30, step: 0.1, better: 'down' },

  // ── Broker handoff ───────────────────────────────────────────
  open_rate: { label: 'Started → חשבון נפתח', group: 'broker', fmt: 'pct', min: 0.05, max: 1, step: 0.01, better: 'up', scenario: true },
  time_to_open_days: { label: 'ימים עד פתיחת חשבון', group: 'broker', fmt: 'days', min: 1, max: 60, step: 0.5, better: 'down', scenario: true },
  exception_rate: { label: 'חריגים שחוזרים לנציג', group: 'broker', fmt: 'pct', min: 0, max: 0.8, step: 0.01, better: 'down', scenario: true },
  exception_handling_min: { label: 'דק׳ נציג לטיפול בחריג', group: 'broker', fmt: 'min', min: 0, max: 120, step: 5, better: 'down' },
  broker_sla_target_days: { label: 'SLA ברוקר — יעד (ימים)', group: 'broker', fmt: 'days', min: 1, max: 30, step: 0.5 },
  broker_sla_actual_days: { label: 'SLA ברוקר — בפועל (ימים)', group: 'broker', fmt: 'days', min: 1, max: 60, step: 0.5, better: 'down' },

  // ── Monetization ─────────────────────────────────────────────
  revenue_per_open: { label: 'הכנסה לחשבון שנפתח', group: 'monetization', fmt: 'money', min: 0, max: 20000, step: 50, better: 'up', scenario: true },
  non_open_charge: { label: 'חיוב אי-פתיחה', group: 'monetization', fmt: 'money', min: 0, max: 5000, step: 10, better: 'up' },
  waiver_rate: { label: 'שיעור ויתור (Waiver)', group: 'monetization', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'down', scenario: true },
  collection_rate: { label: 'שיעור גבייה (Collection)', group: 'monetization', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  cash_delay_days: { label: 'עיכוב מזומן בגבייה (ימים)', group: 'monetization', fmt: 'days', min: 0, max: 120, step: 1, better: 'down', scenario: true },
  broker_payout_delay_days: { label: 'עיכוב תשלום מהברוקר (ימים)', group: 'monetization', fmt: 'days', min: 0, max: 180, step: 1, better: 'down' },

  // ── Student success ──────────────────────────────────────────
  active_students: { label: 'תלמידים פעילים (מלאי)', group: 'success', fmt: 'int', min: 0, max: 20000, step: 10, better: 'up' },
  engagement_rate: { label: 'שיעור מעורבות', group: 'success', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  at_risk_rate: { label: 'שיעור תלמידים בסיכון', group: 'success', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'down', scenario: true },
  reactivation_rate: { label: 'שיעור החזרה לפעילות', group: 'success', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  checkin_coverage: { label: 'כיסוי Check-ins', group: 'success', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  clinic_attendance: { label: 'נוכחות בקליניקות', group: 'success', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },
  satisfaction: { label: 'שביעות רצון (1–5)', group: 'success', fmt: 'score5', min: 1, max: 5, step: 0.1, better: 'up' },

  // ── Growth & upgrade ─────────────────────────────────────────
  pro_appointment_rate: { label: 'פגישות סוחר מקצועי (מתוך מעורבים)', group: 'growth', fmt: 'pct', min: 0, max: 0.5, step: 0.005, better: 'up', scenario: true },
  coaching_conv: { label: 'המרה לפגישה → עסקת אימון', group: 'growth', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  revenue_per_deal: { label: 'הכנסה לעסקת אימון', group: 'growth', fmt: 'money', min: 0, max: 50000, step: 100, better: 'up', scenario: true },
  rep_bonus_per_deal: { label: 'בונוס נציג לעסקה', group: 'growth', fmt: 'money', min: 0, max: 5000, step: 50 },
  coaching_delivery_cost_share: { label: 'עלות אספקת אימון (נתח מהכנסה)', group: 'growth', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'down' },
  advanced_candidate_rate: { label: 'מועמדים למסלול מתקדם', group: 'growth', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },

  // ── Referral ─────────────────────────────────────────────────
  referral_request_rate: { label: 'תלמידים שמתבקשים להפנות', group: 'referral', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  referral_rate: { label: 'שיעור מפנים בפועל', group: 'referral', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up', scenario: true },
  referrals_per_advocate: { label: 'הפניות לממליץ', group: 'referral', fmt: 'num', min: 0, max: 10, step: 0.1, better: 'up' },
  referral_quality_mult: { label: 'מכפיל איכות ליד מהפניה', group: 'referral', fmt: 'num', min: 0.5, max: 4, step: 0.1, better: 'up' },
  referral_tracking_coverage: { label: 'כיסוי מעקב הפניות', group: 'referral', fmt: 'pct', min: 0, max: 1, step: 0.01, better: 'up' },

  // ── Economics ────────────────────────────────────────────────
  rep_cost_month: { label: 'עלות נציג / חודש', group: 'economics', fmt: 'money', min: 0, max: 60000, step: 500, better: 'down' },
  ai_agent_cost_month: { label: 'עלות AI Agent / חודש', group: 'economics', fmt: 'money', min: 0, max: 50000, step: 100, better: 'down' },
  fixed_costs_month: { label: 'עלויות קבועות / חודש', group: 'economics', fmt: 'money', min: 0, max: 500000, step: 1000, better: 'down' },
  utilization_target: { label: 'יעד ניצולת נציגים', group: 'economics', fmt: 'pct', min: 0.3, max: 1, step: 0.01 },
};

export const SCENARIO_LEVERS = Object.entries(LEVERS)
  .filter(([, l]) => l.scenario)
  .map(([id]) => id);

export function leverLabel(id) {
  return LEVERS[id]?.label ?? id;
}
