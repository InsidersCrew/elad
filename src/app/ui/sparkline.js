/** 64×22 SVG sparkline: 1.5px line, faint area, end marker. Ink = text tokens, not status colour. */
export function sparkline(values, { w = 64, h = 22, better = 'up' } = {}) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'sparkline');
  svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
  svg.setAttribute('aria-hidden', 'true');
  const v = (values || []).filter((x) => Number.isFinite(x));
  if (v.length < 2) return svg;
  const min = Math.min(...v);
  const max = Math.max(...v);
  const span = max - min || Math.abs(max) * 0.1 || 1;
  const pad = 3;
  const pts = v.map((x, i) => [pad + (i / (v.length - 1)) * (w - pad * 2), h - pad - ((x - min) / span) * (h - pad * 2)]);
  const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
  const area = document.createElementNS(ns, 'path');
  area.setAttribute('class', 'area');
  area.setAttribute('d', `${d} L${pts[pts.length - 1][0].toFixed(1)} ${h} L${pts[0][0].toFixed(1)} ${h} Z`);
  svg.appendChild(area);
  const path = document.createElementNS(ns, 'path');
  path.setAttribute('d', d);
  svg.appendChild(path);
  const c = document.createElementNS(ns, 'circle');
  c.setAttribute('cx', pts[pts.length - 1][0].toFixed(1));
  c.setAttribute('cy', pts[pts.length - 1][1].toFixed(1));
  c.setAttribute('r', '2');
  svg.appendChild(c);
  return svg;
}
