/**
 * Lenses (view modes): each maps a structure's state to a colour + glow.
 * Colour rules follow the doc: green healthy · yellow attention · red bottleneck/risk ·
 * blue info/neutral · purple strategic (City Hall).
 */
import * as THREE from 'three';

export const COLORS = {
  green: '#00c28a',
  yellow: '#f5a623',
  red: '#e53935',
  blue: '#0097fe',
  sky: '#83cdff',
  purple: '#460fff',
  purpleSoft: '#7c5cff',
  dim: '#1b3a6b',
  navy: '#0b2350',
};

export const STATUS_COLOR = { green: COLORS.green, yellow: COLORS.yellow, red: COLORS.red };

export const LENSES = [
  {
    id: 'health', label: 'בריאות', desc: 'צבע = Health Score · גובה = תפוקה · רוחב = קיבולת',
    legend: [
      { c: COLORS.green, t: 'בריא ≥ 75' }, { c: COLORS.yellow, t: 'דורש תשומת לב' }, { c: COLORS.red, t: 'חלש / סיכון' }, { c: COLORS.purple, t: 'בית העירייה' },
      { icon: '⚠', t: 'משואה = צוואר בקבוק קריטי' }, { icon: '≈', t: 'אדים = דליפת הכנסה' },
    ],
  },
  {
    id: 'weak', label: 'מבנים חלשים', desc: 'רק המבנים החלשים מוארים — השאר מעומעמים',
    legend: [{ c: COLORS.red, t: 'Health < 60' }, { c: COLORS.yellow, t: '60–75' }, { c: COLORS.dim, t: 'תקין (מעומעם)' }],
  },
  {
    id: 'bottleneck', label: 'צווארי בקבוק', desc: 'כמה המבנה מגביל את כל העיר (רגישות × פער × עומס)',
    legend: [{ c: COLORS.red, t: 'צוואר בקבוק ראשי' }, { c: COLORS.yellow, t: 'מגביל' }, { c: COLORS.dim, t: 'לא מגביל' }],
  },
  {
    id: 'profit', label: 'רווחיות', desc: 'ירוק = מייצר תרומה · אדום = מרכז עלות · כחול = ניטרלי',
    legend: [{ c: COLORS.green, t: 'הכנסה / תרומה' }, { c: COLORS.red, t: 'עלות' }, { c: COLORS.blue, t: 'ניטרלי' }],
  },
  {
    id: 'cash', label: 'תזרים', desc: 'כמה מהר ההכנסה הופכת למזומן',
    legend: [{ c: COLORS.sky, t: 'מזומן מהיר' }, { c: COLORS.yellow, t: 'מתעכב' }, { c: COLORS.red, t: 'כלוא / לא נגבה' }, { c: COLORS.dim, t: 'לא רלוונטי' }],
  },
  {
    id: 'quality', label: 'איכות שירות', desc: 'Strength Score — איכות ויציבות ללא קשר לגודל',
    legend: [{ c: COLORS.sky, t: 'איכות גבוהה' }, { c: COLORS.dim, t: 'בינונית' }, { c: COLORS.red, t: 'סדקים (< 50)' }],
  },
];

export const CASH_METRICS = new Set(['cash_delay_days', 'weighted_cash_delay_days', 'cash_tied_up', 'collection_rate', 'uncollected_value', 'time_to_open_days', 'non_open_charge_rate', 'waiver_rate', 'broker_sla_actual_days']);

const _a = new THREE.Color();
const _b = new THREE.Color();
function mix(hexA, hexB, k) {
  return '#' + _a.set(hexA).lerp(_b.set(hexB), Math.min(1, Math.max(0, k))).getHexString();
}
/** Three-stop sequential ramp. */
function ramp(stops, k) {
  k = Math.min(1, Math.max(0, k));
  const n = stops.length - 1;
  const i = Math.min(n - 1, Math.floor(k * n));
  return mix(stops[i], stops[i + 1], k * n - i);
}

/** Economic role of each district for the profit lens. */
export function districtEconomics(facts) {
  const e = {
    acquisition: -facts.ad_spend,
    qualification: -facts.ai_agent_cost_month,
    fitcall: -facts.rep_cost_total,
    onboarding: 0,
    broker: 0,
    monetization: facts.core_revenue,
    success: 0,
    growth: facts.coaching_contribution,
    referral: facts.leads_referral * facts.cost_per_lead,
    cityhall: facts.net,
  };
  const max = Math.max(...Object.values(e).map((v) => Math.abs(v)), 1);
  return Object.fromEntries(Object.entries(e).map(([k, v]) => [k, { value: v, norm: Math.abs(v) / max, kind: v > 0 ? 'revenue' : v < 0 ? 'cost' : 'neutral' }]));
}

/**
 * @returns {{color:string, intensity:number, dim:number}}
 */
export function lensStyle(lensId, s, city) {
  const sc = s.scores;
  const isHall = s.def.district_id === 'cityhall';
  switch (lensId) {
    case 'weak': {
      if (sc.flags.weak) return { color: COLORS.red, intensity: 1.1, dim: 0 };
      if (sc.status === 'yellow') return { color: COLORS.yellow, intensity: 0.55, dim: 0.25 };
      return { color: COLORS.dim, intensity: 0.15, dim: 0.65 };
    }
    case 'bottleneck': {
      const top = city.mayor.primaryBottleneck?.def.id === s.def.id;
      const k = sc.bottleneck / 100;
      if (top) return { color: COLORS.red, intensity: 1.2, dim: 0 };
      if (k < 0.25) return { color: COLORS.dim, intensity: 0.15, dim: 0.6 };
      return { color: ramp([COLORS.dim, COLORS.yellow, COLORS.red], (k - 0.25) / 0.75), intensity: 0.3 + k * 0.8, dim: 0 };
    }
    case 'profit': {
      const eco = districtEconomics(city.facts)[s.def.district_id];
      if (!eco || eco.kind === 'neutral') return { color: COLORS.blue, intensity: 0.35, dim: 0.3 };
      const base = eco.kind === 'revenue' ? COLORS.green : COLORS.red;
      const share = (sc.size / 100) * 0.5 + 0.5;
      return { color: base, intensity: 0.3 + eco.norm * share * 0.9, dim: 0 };
    }
    case 'cash': {
      const cashMetrics = s.metrics.filter((m) => CASH_METRICS.has(m.id) || CASH_METRICS.has(m.source));
      if (!cashMetrics.length) {
        if (s.def.district_id === 'growth' && s.def.type === 'revenue') return { color: COLORS.sky, intensity: 0.9, dim: 0 };
        return { color: COLORS.dim, intensity: 0.15, dim: 0.6 };
      }
      const score = cashMetrics.reduce((a, m) => a + m.score, 0) / cashMetrics.length;
      return { color: ramp([COLORS.red, COLORS.yellow, COLORS.sky], score / 100), intensity: 0.4 + (score / 100) * 0.7, dim: 0 };
    }
    case 'quality': {
      const k = sc.strength / 100;
      if (k < 0.5) return { color: mix(COLORS.red, COLORS.dim, k / 0.5), intensity: 0.5 + (0.5 - k), dim: 0 };
      return { color: mix(COLORS.dim, COLORS.sky, (k - 0.5) / 0.5), intensity: 0.3 + k * 0.8, dim: 0 };
    }
    case 'health':
    default: {
      if (isHall) return { color: COLORS.purple, intensity: 0.7 + (sc.health / 100) * 0.5, dim: 0 };
      const color = STATUS_COLOR[sc.status] ?? COLORS.blue;
      return { color, intensity: 0.35 + (sc.health / 100) * 0.9, dim: sc.visual?.dim ?? 0 };
    }
  }
}

export function districtStyle(lensId, d, city) {
  if (d.def.id === 'cityhall') return { color: COLORS.purple };
  if (lensId === 'profit') {
    const eco = districtEconomics(city.facts)[d.def.id];
    return { color: eco.kind === 'revenue' ? COLORS.green : eco.kind === 'cost' ? COLORS.red : COLORS.blue };
  }
  if (lensId === 'bottleneck') {
    const k = d.scores.bottleneck / 100;
    return { color: k < 0.3 ? COLORS.dim : ramp([COLORS.dim, COLORS.yellow, COLORS.red], (k - 0.3) / 0.7) };
  }
  return { color: STATUS_COLOR[d.scores.status] ?? COLORS.blue };
}

export function lensById(id) {
  return LENSES.find((l) => l.id === id) ?? LENSES[0];
}
