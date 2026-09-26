/** Number formatting shared by the UI, the reports and the CLI. Hebrew locale, ₪. */

const nfInt = new Intl.NumberFormat('he-IL', { maximumFractionDigits: 0 });
const nf1 = new Intl.NumberFormat('he-IL', { maximumFractionDigits: 1 });
const nf2 = new Intl.NumberFormat('he-IL', { maximumFractionDigits: 2 });

export function fmtValue(v, fmt) {
  if (v === null || v === undefined || Number.isNaN(v)) return '—';
  if (!Number.isFinite(v)) return '∞';
  switch (fmt) {
    case 'pct': {
      const p = v * 100;
      return (Math.abs(p) < 10 && Math.abs(p) > 0 ? nf1.format(p) : nfInt.format(p)) + '%';
    }
    case 'int':
      return nfInt.format(Math.round(v));
    case 'money':
      return (v < 0 ? '−' : '') + '₪' + nfInt.format(Math.abs(Math.round(v)));
    case 'num':
      return Math.abs(v) >= 100 ? nfInt.format(v) : nf1.format(v);
    case 'min':
      return nf1.format(v) + ' דק׳';
    case 'days':
      return nf1.format(v) + ' ימים';
    case 'hours':
      return nf1.format(v) + ' שע׳';
    case 'score5':
      return nf1.format(v) + '/5';
    default:
      return nf2.format(v);
  }
}

/** Signed delta in the metric's own units, e.g. "+4.2 נק׳" for pct, "+₪12,300" for money. */
export function fmtDelta(delta, fmt) {
  if (delta === null || delta === undefined || !Number.isFinite(delta)) return '—';
  const sign = delta > 0 ? '+' : delta < 0 ? '−' : '';
  const a = Math.abs(delta);
  switch (fmt) {
    case 'pct':
      return `${sign}${nf1.format(a * 100)} נק׳`;
    case 'money':
      return `${sign}₪${nfInt.format(Math.round(a))}`;
    case 'int':
      return `${sign}${nfInt.format(Math.round(a))}`;
    case 'min':
      return `${sign}${nf1.format(a)} דק׳`;
    case 'days':
      return `${sign}${nf1.format(a)} ימים`;
    case 'hours':
      return `${sign}${nf1.format(a)} שע׳`;
    default:
      return `${sign}${nf1.format(a)}`;
  }
}

export function fmtPctChange(ratio) {
  if (!Number.isFinite(ratio)) return '—';
  const p = ratio * 100;
  const sign = p > 0 ? '+' : p < 0 ? '−' : '';
  return `${sign}${nf1.format(Math.abs(p))}%`;
}

export function fmtScore(v) {
  return nfInt.format(Math.round(v));
}

export const STATUS_LABEL = { green: 'בריא', yellow: 'דורש תשומת לב', red: 'צוואר בקבוק / סיכון' };
export const PRIORITY_LABEL = { high: 'גבוהה', medium: 'בינונית', low: 'נמוכה' };
