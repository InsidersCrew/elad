/**
 * Report generators (Markdown): Daily Mayor Brief, Weekly City Review,
 * Monthly Structural Review, What-if Report.
 */
import { fmtValue, fmtDelta, fmtPctChange, PRIORITY_LABEL } from './format.js';
import { LEVERS } from './levers.js';
import { diffCities, describeChanges } from './scenario.js';

const STATUS_ICON = { green: '🟢', yellow: '🟡', red: '🔴' };
const skillName = (city, id) => city.skills.find((s) => s.id === id)?.name_he ?? id;

function line(s) {
  return `${s}\n`;
}

export function dailyBrief(city) {
  const m = city.mayor;
  const f = city.facts;
  let md = '';
  md += line(`# Daily Mayor Brief — ${city.periodLabel}`);
  md += line(`_INSIDERS CityOS · נוצר ${new Date().toLocaleString('he-IL')}_\n`);
  md += line(`## 1. מצב העיר ${STATUS_ICON[m.state.status]}`);
  md += line(`- **Health Score:** ${Math.round(m.state.health)}/100`);
  md += line(`- **הכנסות:** ${fmtValue(f.total_revenue, 'money')} · **תרומה:** ${fmtValue(f.contribution, 'money')} · **רווח נקי:** ${fmtValue(f.net, 'money')} (${fmtValue(f.net_margin, 'pct')})`);
  md += line(`- **זרימה:** ${fmtValue(f.leads_total, 'int')} לידים → ${fmtValue(f.qualified, 'int')} Qualified → ${fmtValue(f.calls_completed, 'int')} שיחות → ${fmtValue(f.joined, 'int')} תלמידים חדשים → ${fmtValue(f.started, 'int')} התחילו → ${fmtValue(f.opened, 'int')} פתחו חשבון`);
  md += line(`- **ניצולת נציגים:** ${fmtValue(f.rep_utilization, 'pct')} · **CAC:** ${fmtValue(f.cac, 'money')} · **הכנסה לתלמיד:** ${fmtValue(f.revenue_per_student, 'money')}`);
  md += line(`- **דגלים:** ${m.state.counts.weak} חלשים · ${m.state.counts.critical} קריטיים · ${m.state.counts.overloaded} בעומס · ${m.state.counts.leaky} דולפים · ${m.state.counts.fragile} שבירים\n`);
  md += line(`> ${m.narrative}\n`);

  md += line(`## 2. שלושת המבנים החלשים ביותר`);
  for (const s of m.weakest) {
    md += line(`- ${STATUS_ICON[s.scores.status]} **${s.def.name_he}** (${s.def.name}) — Health ${Math.round(s.scores.health)} · Size ${Math.round(s.scores.size)} · Strength ${Math.round(s.scores.strength)} · Risk ${Math.round(s.scores.risk)}`);
    if (s.rootCauses[0]) md += line(`  - למה: ${s.rootCauses[0].title}`);
  }
  md += '\n';

  md += line(`## 3. דירוג צווארי בקבוק`);
  md += line(`| # | מבנה | רובע | Bottleneck | Health | רגישות תרומה (+10%) |`);
  md += line(`|---|---|---|---|---|---|`);
  m.bottlenecks.forEach((s, i) => {
    const sens = s.def.primary_lever ? city.sensitivities[s.def.primary_lever] : null;
    md += line(`| ${i + 1} | ${s.def.name_he} | ${city.byId.districts[s.def.district_id].def.name_he} | ${Math.round(s.scores.bottleneck)} | ${Math.round(s.scores.health)} | ${sens ? fmtDelta(sens.dContribution, 'money') : '—'} |`);
  });
  md += '\n';

  md += line(`## 4. השערת שורש — ${m.primaryBottleneck?.def.name_he ?? '—'}`);
  for (const c of m.primaryBottleneck?.rootCauses ?? []) {
    md += line(`- **${c.title}** (ביטחון ${Math.round(c.confidence * 100)}%) — ${c.description}`);
    md += line(`  - ראיות: ${c.evidence.map((e) => `${e.name} ${fmtValue(e.value, e.fmt)}${e.status ? ' ' + STATUS_ICON[e.status] : ''}`).join(' · ')}`);
    if (c.impact) md += line(`  - משמעות: ${c.impact}`);
  }
  md += '\n';

  md += line(`## 5–8. מהלכים מומלצים, השפעה צפויה, סקיל אחראי, עדיפות`);
  md += line(`| # | מהלך | מבנה | Δ תרומה/חודש | Δ תלמידים | Δ ניצולת | מאמץ | סקיל | עדיפות |`);
  md += line(`|---|---|---|---|---|---|---|---|---|`);
  for (const mv of m.moves) {
    md += line(`| ${mv.rank} | ${mv.action.title} | ${mv.structure.def.name_he} | ${fmtDelta(mv.impact.dContribution, 'money')} | ${fmtDelta(mv.impact.dJoined, 'int')} | ${fmtDelta(mv.impact.dUtilization, 'pct')} | ${mv.effort} | ${skillName(city, mv.ownerSkill)} | ${PRIORITY_LABEL[mv.priority]} |`);
  }
  md += '\n';
  for (const mv of m.moves) {
    if (mv.secondOrder.length) md += line(`- _${mv.action.title}_ — אפקטים מסדר שני: ${mv.secondOrder.map((e) => e.text).join('; ')}`);
  }
  md += '\n';

  md += line(`## 9. מה לנטר עכשיו`);
  for (const it of m.monitor) md += line(`- **${it.metric}** (${it.structure}): ${it.value} → יעד ${it.target} — ${it.why}`);
  return md;
}

export function weeklyReview(city) {
  const h = city.factsHistory;
  const cur = h[h.length - 1];
  const prev = h[h.length - 2] ?? cur;
  const labels = city.period.labels;
  let md = '';
  md += line(`# Weekly City Review — ${labels[labels.length - 1]} מול ${labels[labels.length - 2] ?? '—'}`);
  md += line(`_INSIDERS CityOS_\n`);
  const rows = [
    ['לידים', 'leads_total', 'int'], ['Qualified', 'qualified', 'int'], ['שיחות התאמה', 'calls_completed', 'int'], ['תלמידים חדשים', 'joined', 'int'],
    ['התחילו', 'started', 'int'], ['פתחו חשבון', 'opened', 'int'], ['הכנסות', 'total_revenue', 'money'], ['תרומה', 'contribution', 'money'], ['רווח נקי', 'net', 'money'],
    ['ניצולת נציגים', 'rep_utilization', 'pct'], ['CAC', 'cac', 'money'], ['ליד → תלמיד', 'lead_to_joined', 'pct'],
  ];
  md += line(`## מה השתנה`);
  md += line(`| מדד | קודם | עכשיו | שינוי |`);
  md += line(`|---|---|---|---|`);
  for (const [label, key, fmt] of rows) {
    md += line(`| ${label} | ${fmtValue(prev[key], fmt)} | ${fmtValue(cur[key], fmt)} | ${fmtDelta(cur[key] - prev[key], fmt)} (${fmtPctChange(prev[key] ? (cur[key] - prev[key]) / Math.abs(prev[key]) : 0)}) |`);
  }
  md += '\n';

  // Which structures strengthened / weakened: compare metric trends
  const strengthened = [];
  const weakened = [];
  for (const s of city.structures) {
    const better = s.metrics.filter((m) => m.weight >= 2 && m.trend.improving === true).length;
    const worse = s.metrics.filter((m) => m.weight >= 2 && m.trend.improving === false).length;
    if (worse > better) weakened.push({ s, worse, better });
    else if (better > worse) strengthened.push({ s, worse, better });
  }
  md += line(`## מה התחזק`);
  md += strengthened.length ? strengthened.map(({ s }) => line(`- 🟢 ${s.def.name_he} — ${s.metrics.filter((m) => m.trend.improving).map((m) => `${m.name} ${fmtDelta(m.trend.delta, m.fmt)}`).join(', ')}`)).join('') : line('- אין שינוי מהותי');
  md += line(`\n## מה נחלש`);
  md += weakened.length ? weakened.map(({ s }) => line(`- 🔴 ${s.def.name_he} — ${s.metrics.filter((m) => m.trend.improving === false).map((m) => `${m.name} ${fmtDelta(m.trend.delta, m.fmt)}`).join(', ')}`)).join('') : line('- אין שינוי מהותי');
  md += line(`\n## צוואר הבקבוק הראשי`);
  const b = city.mayor.primaryBottleneck;
  md += line(`- **${b.def.name_he}** (${city.byId.districts[b.def.district_id].def.name_he}) — Bottleneck ${Math.round(b.scores.bottleneck)}, Health ${Math.round(b.scores.health)}`);
  md += line(`- למה: ${b.rootCauses[0]?.title ?? '—'}`);
  md += line(`\n## איזה סקיל צריך להיכנס`);
  const skills = [...new Set(city.mayor.moves.map((m) => m.ownerSkill))];
  for (const id of skills) {
    const moves = city.mayor.moves.filter((m) => m.ownerSkill === id);
    md += line(`- **${skillName(city, id)}** — ${moves.map((m) => m.action.title).join('; ')}`);
  }
  return md;
}

export function monthlyStructural(city) {
  const f = city.facts;
  let md = '';
  md += line(`# Monthly Structural Review — ${city.periodLabel}`);
  md += line(`_INSIDERS CityOS_\n`);
  md += line(`## איפה העיר לא בנויה נכון`);
  const structural = [];
  if (f.rep_utilization > 0.85) structural.push(`**קיבולת:** הנציגים ב-${fmtValue(f.rep_utilization, 'pct')} ניצולת. ${fmtValue(f.rep_other_minutes / 60, 'num')} שעות בחודש הולכות ל-handoff, אונבורדינג 1:1, follow-up וחריגים — לא לשיחות התאמה.`);
  if (f.group_share < 0.7) structural.push(`**מודל אונבורדינג:** ${fmtValue(1 - f.group_share, 'pct')} מהאונבורדינג נעשה 1:1. מודל קבוצתי כברירת מחדל משחרר ${fmtValue((f.one_on_one_sessions * f.one_on_one_min) / 60, 'num')} שעות נציג בחודש.`);
  if (f.ai_resolution_rate < 0.7) structural.push(`**AI Agent:** ${fmtValue(f.ai_handoff_rate, 'pct')} מהלידים דורשים נציג לפני שיחת התאמה (${fmtValue(f.human_qual_conversations, 'int')} שיחות). היעד המבני: מתחת ל-30%.`);
  if (f.open_rate < 0.6) structural.push(`**Handoff לברוקר:** open-rate ${fmtValue(f.open_rate, 'pct')} — כמעט חצי מהתלמידים שהתחילו לא הופכים להכנסה מלאה. זו הקפיצה הגדולה ביותר בעיר וצריך לה תהליך ייעודי.`);
  if (f.non_open_charge_rate < 0.6) structural.push(`**מסלול החיוב:** רק ${fmtValue(f.non_open_charge_rate, 'pct')} מחיובי אי-הפתיחה הופכים להכנסה — המסלול קיים על הנייר ולא בתהליך.`);
  if (f.referral_share < 0.05) structural.push(`**מעגל ההפניות:** ${fmtValue(f.referral_share, 'pct')} מהלידים מהפניות. המעגל לא סגור — העיר תלויה בפרסום.`);
  if (f.weighted_cash_delay_days > 30) structural.push(`**תזרים:** ${fmtValue(f.weighted_cash_delay_days, 'days')} עיכוב מזומן משוקלל, ${fmtValue(f.cash_tied_up, 'money')} כלואים.`);
  md += structural.length ? structural.map((s) => line(`- ${s}`)).join('') : line('- המבנה תקין.');

  md += line(`\n## האם צריך לשנות מודל`);
  const sens = city.ranking.slice(0, 5);
  md += line(`הידיות עם המינוף הגבוה ביותר (שיפור של 10% בכל אחת):`);
  md += line(`| ידית | מ- | ל- | Δ תרומה/חודש | Δ תלמידים |`);
  md += line(`|---|---|---|---|---|`);
  for (const s of sens) md += line(`| ${LEVERS[s.lever]?.label ?? s.lever} | ${fmtValue(s.from, LEVERS[s.lever]?.fmt)} | ${fmtValue(s.to, LEVERS[s.lever]?.fmt)} | ${fmtDelta(s.dContribution, 'money')} | ${fmtDelta(s.dJoined, 'int')} |`);

  md += line(`\n## האם צריך לשנות Headcount`);
  const hire = city.sensitivities.reps;
  if (hire) {
    md += line(`- נציג נוסף: ${fmtDelta(hire.dContribution, 'money')} תרומה בחודש (אחרי עלות של ${fmtValue(city.inputs.rep_cost_month, 'money')}), ${fmtDelta(hire.dJoined, 'int')} תלמידים, ניצולת ${fmtDelta(hire.dUtilization, 'pct')}.`);
    md += line(hire.dContribution > 0 ? `- **כדאי** — אבל רק אחרי שחרור קיבולת זול (קבוצה, AI, שיחה קצרה) שמניב יותר בלי עלות קבועה.` : `- **לא כדאי כרגע** — הקיבולת הנוספת לא מכסה את עלותה. קודם לשפר המרה ולשחרר זמן.`);
  }
  md += line(`\n## האם צריך לבנות skill חדש`);
  const uncovered = city.structures.filter((s) => s.scores.flags.weak && s.rootCauses.every((c) => c.generic));
  md += uncovered.length ? uncovered.map((s) => line(`- ${s.def.name_he}: מבנה חלש בלי חוק סיבה ייעודי — מועמד לסקיל/חוק חדש.`)).join('') : line('- כל המבנים החלשים מכוסים על ידי סקיל קיים.');
  return md;
}

export function whatIfReport(base, alt, changes) {
  const d = diffCities(base, alt);
  const desc = describeChanges(base.baseInputs, changes);
  let md = '';
  md += line(`# What-if Report`);
  md += line(`_INSIDERS CityOS · ${new Date().toLocaleString('he-IL')}_\n`);
  md += line(`## השינוי`);
  md += desc.length ? desc.map((c) => line(`- ${c.text}`)).join('') : line('- אין שינוי');
  md += line(`\n## תוצאות`);
  md += line(`| מדד | לפני | אחרי | שינוי |`);
  md += line(`|---|---|---|---|`);
  for (const o of d.outcomes) md += line(`| ${o.label} | ${fmtValue(o.from, o.fmt)} | ${fmtValue(o.to, o.fmt)} | ${o.text} (${fmtPctChange(o.pct)}) |`);
  md += line(`\n- **בריאות העיר:** ${Math.round(d.cityHealth.from)} → ${Math.round(d.cityHealth.to)}`);
  md += line(`- **צוואר בקבוק:** ${d.bottleneck.from?.name_he ?? '—'} → ${d.bottleneck.to?.name_he ?? '—'}${d.bottleneck.moved ? ' (עבר!)' : ' (נשאר)'}`);
  md += line(`\n## Impact map — מבנים שהשתנו`);
  md += d.structures.length ? d.structures.map((s) => line(`- ${STATUS_ICON[s.fromStatus]}→${STATUS_ICON[s.toStatus]} ${s.name}: Health ${fmtDelta(s.dHealth, 'num')}, Size ${fmtDelta(s.dSize, 'num')}`)).join('') : line('- אין שינוי מהותי במבנים');
  md += line(`\n## אפקטים מסדר שני`);
  md += d.secondOrder.length ? d.secondOrder.map((e) => line(`- ${e.kind === 'warn' ? '⚠️' : e.kind === 'good' ? '✅' : 'ℹ️'} ${e.text}`)).join('') : line('- לא זוהו');
  return md;
}
