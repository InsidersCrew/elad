/** Minimal Markdown → HTML for the generated reports (headings, lists, tables, quotes, bold/italic/code). */
function esc(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function inline(s) {
  return esc(s)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/_([^_]+)_/g, '<em>$1</em>');
}

export function renderMarkdown(md) {
  const lines = md.split('\n');
  let html = '';
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (/^#{1,3} /.test(line)) {
      const level = line.match(/^#+/)[0].length;
      html += `<h${level}>${inline(line.replace(/^#+ /, ''))}</h${level}>`;
      i++;
    } else if (line.startsWith('|')) {
      const rows = [];
      while (i < lines.length && lines[i].startsWith('|')) rows.push(lines[i++]);
      const cells = (r) => r.replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
      const head = cells(rows[0]);
      const body = rows.slice(2).map(cells);
      html += '<table><thead><tr>' + head.map((c) => `<th>${inline(c)}</th>`).join('') + '</tr></thead><tbody>' + body.map((r) => '<tr>' + r.map((c) => `<td>${inline(c)}</td>`).join('') + '</tr>').join('') + '</tbody></table>';
    } else if (/^\s*- /.test(line)) {
      html += '<ul>';
      while (i < lines.length && /^\s*- /.test(lines[i])) {
        const m = lines[i].match(/^(\s*)- (.*)$/);
        const depth = Math.floor(m[1].length / 2);
        html += `<li style="margin-inline-start:${depth * 14}px">${inline(m[2])}</li>`;
        i++;
      }
      html += '</ul>';
    } else if (line.startsWith('> ')) {
      html += `<blockquote>${inline(line.slice(2))}</blockquote>`;
      i++;
    } else if (line.trim() === '' || line.trim() === '---') {
      i++;
    } else {
      html += `<p>${inline(line)}</p>`;
      i++;
    }
  }
  return html;
}
