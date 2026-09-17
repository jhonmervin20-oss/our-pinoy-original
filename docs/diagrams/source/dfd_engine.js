/* Gane & Sarson DFD renderer: entity | process column | data store column.
   Emits SVG and self-checks for crossing lines and colliding labels. */
const NS = 'http://www.w3.org/2000/svg';
const FONT = 'Arial, Helvetica, sans-serif';
const INK = '#111111', LINE = '#333333';
let SEGS = [];   // {x1,y1,x2,y2,owner}
let RECTS = [];  // {x,y,w,h,kind,owner}
window.__results = {};

function el(tag, attrs, parent) {
  const e = document.createElementNS(NS, tag);
  for (const k in attrs) e.setAttribute(k, attrs[k]);
  if (parent) parent.appendChild(e);
  return e;
}
function newSvg(id, title, w, h) {
  SEGS = []; RECTS = [];
  const svg = el('svg', { xmlns: NS, id, width: w, height: h, viewBox: `0 0 ${w} ${h}`, 'font-family': FONT });
  el('title', {}, svg).textContent = title;
  const defs = el('defs', {}, svg);
  const m = el('marker', { id: id + '-ah', viewBox: '0 0 10 10', refX: '2', refY: '5',
    markerWidth: '10', markerHeight: '10', markerUnits: 'userSpaceOnUse', orient: 'auto' }, defs);
  el('path', { d: 'M0,1 L10,5 L0,9 Z', fill: LINE }, m);
  el('rect', { x: 0, y: 0, width: w, height: h, fill: '#ffffff' }, svg);
  const d = { svg, id, w, h };
  d.gLines = el('g', {}, svg);
  d.gBoxes = el('g', {}, svg);
  d.gLabels = el('g', {}, svg);
  document.getElementById('host').appendChild(svg);
  return d;
}
function textBlock(g, cx, cy, lines, size, weight, anchor) {
  const lh = Math.round(size * 1.2);
  const t = el('text', { 'text-anchor': anchor || 'middle', 'font-size': size, 'font-weight': weight, fill: INK }, g);
  lines.forEach((ln, i) => {
    const y = cy - ((lines.length - 1) * lh) / 2 + i * lh + size * 0.35;
    el('tspan', { x: cx, y: y.toFixed(1) }, t).textContent = ln;
  });
  return t;
}
function box(d, x, y, w, h, lines, size, rx) {
  el('rect', { x, y, width: w, height: h, rx: rx || 2, ry: rx || 2, fill: '#fff', stroke: INK, 'stroke-width': 1.5 }, d.gBoxes);
  textBlock(d.gBoxes, x + w / 2, y + h / 2, lines, size, 400);
  RECTS.push({ x, y, w, h, kind: 'node' });
}
// Gane & Sarson process: rounded box, number in the header band, name below.
function processBox(d, x, y, w, h, num, nameLines) {
  el('rect', { x, y, width: w, height: h, rx: 10, ry: 10, fill: '#fff', stroke: INK, 'stroke-width': 1.5 }, d.gBoxes);
  el('line', { x1: x, y1: y + 28, x2: x + w, y2: y + 28, stroke: INK, 'stroke-width': 1.3 }, d.gBoxes);
  textBlock(d.gBoxes, x + w / 2, y + 14, [num], 15, 400);
  textBlock(d.gBoxes, x + w / 2, y + 28 + (h - 28) / 2, nameLines, 15, 400);
  RECTS.push({ x, y, w, h, kind: 'node' });
}
// Gane & Sarson data store: open-ended rectangle, ID compartment on the left.
function storeBox(d, x, y, w, h, id, name) {
  el('path', { d: `M${x + w},${y} L${x},${y} L${x},${y + h} L${x + w},${y + h}`, fill: '#fff', stroke: INK, 'stroke-width': 1.5 }, d.gBoxes);
  el('line', { x1: x + 42, y1: y, x2: x + 42, y2: y + h, stroke: INK, 'stroke-width': 1.3 }, d.gBoxes);
  textBlock(d.gBoxes, x + 21, y + h / 2, [id], 14, 400);
  textBlock(d.gBoxes, x + 42 + (w - 42) / 2, y + h / 2, [name], 14, 400);
  RECTS.push({ x, y, w, h, kind: 'node' });
}
function polyline(d, pts, owner) {
  const p = pts.map(q => q.slice());
  const a = p[p.length - 2], b = p[p.length - 1];
  b[0] -= Math.sign(b[0] - a[0]) * 8;
  b[1] -= Math.sign(b[1] - a[1]) * 8;
  el('polyline', { points: p.map(q => q.join(',')).join(' '), fill: 'none', stroke: LINE,
    'stroke-width': 1.3, 'marker-end': `url(#${d.id}-ah)` }, d.gLines);
  for (let i = 0; i < p.length - 1; i++) {
    SEGS.push({ x1: p[i][0], y1: p[i][1], x2: p[i + 1][0], y2: p[i + 1][1], owner });
  }
}
// label anchored to a point; place: 'above' | 'below', align: 'middle' | 'end' | 'start'
function label(d, lines, x, y, place, align) {
  const size = 13, lh = Math.round(size * 1.2);
  const h = lines.length * lh;
  const cy = place === 'mid' ? y : (place === 'above' ? y - 7 - h / 2 : y + 7 + h / 2);
  const t = textBlock(d.gLabels, x, cy, lines, size, 700, align || 'middle');
  const bb = t.getBBox();
  const r = el('rect', { x: (bb.x - 4).toFixed(1), y: (bb.y - 2).toFixed(1),
    width: (bb.width + 8).toFixed(1), height: (bb.height + 4).toFixed(1), fill: '#fff' });
  d.gLabels.insertBefore(r, t);
  RECTS.push({ x: bb.x - 4, y: bb.y - 2, w: bb.width + 8, h: bb.height + 4, kind: 'label', text: lines.join(' ') });
}

// Try candidate positions in order; keep the first that collides with nothing.
function placeLabel(d, lines, candidates) {
  for (let i = 0; i < candidates.length; i++) {
    const c = candidates[i];
    const size = 13, lh = Math.round(size * 1.2), h = lines.length * lh;
    const cy = c.place === 'mid' ? c.y : (c.place === 'above' ? c.y - 5 - h / 2 : c.y + 5 + h / 2);
    const t = textBlock(d.gLabels, c.x, cy, lines, size, 700, c.align || 'middle');
    const bb = t.getBBox();
    const box = { x: bb.x - 4, y: bb.y - 2, w: bb.width + 8, h: bb.height + 4 };
    const clash = RECTS.some(R => overlap(box, R, 1)) || SEGS.some(s => overlap(box, segRects(s), 0));
    if (!clash || i === candidates.length - 1) {
      const r = el('rect', { x: box.x.toFixed(1), y: box.y.toFixed(1),
        width: box.w.toFixed(1), height: box.h.toFixed(1), fill: '#fff' });
      d.gLabels.insertBefore(r, t);
      RECTS.push({ ...box, kind: 'label', text: lines.join(' ') });
      return;
    }
    t.remove();
  }
}

/* ---- checker: orthogonal segment crossings + label/box collisions ---- */
function segRects(s) { // bounding box of a segment, 1px thick
  return { x: Math.min(s.x1, s.x2) - 0.5, y: Math.min(s.y1, s.y2) - 0.5,
           w: Math.abs(s.x2 - s.x1) + 1, h: Math.abs(s.y2 - s.y1) + 1 };
}
function overlap(a, b, pad = 0) {
  return a.x < b.x + b.w + pad && b.x < a.x + a.w + pad && a.y < b.y + b.h + pad && b.y < a.y + a.h + pad;
}
function check() {
  const issues = [];
  for (let i = 0; i < SEGS.length; i++) {
    for (let j = i + 1; j < SEGS.length; j++) {
      const a = SEGS[i], b = SEGS[j];
      if (a.owner === b.owner) continue;
      const ah = a.y1 === a.y2, bh = b.y1 === b.y2;
      if (ah !== bh) { // one horizontal, one vertical -> true crossing
        const H = ah ? a : b, V = ah ? b : a;
        const hx1 = Math.min(H.x1, H.x2), hx2 = Math.max(H.x1, H.x2);
        const vy1 = Math.min(V.y1, V.y2), vy2 = Math.max(V.y1, V.y2);
        if (V.x1 > hx1 + 0.5 && V.x1 < hx2 - 0.5 && H.y1 > vy1 + 0.5 && H.y1 < vy2 - 0.5) {
          issues.push(`CROSS ${a.owner} x ${b.owner} at ${V.x1},${H.y1}`);
        }
      } else if (overlap(segRects(a), segRects(b))) { // parallel overlap
        issues.push(`OVERLAP ${a.owner}(${a.x1},${a.y1}-${a.x2},${a.y2}) x ${b.owner}(${b.x1},${b.y1}-${b.x2},${b.y2})`);
      }
    }
  }
  const labels = RECTS.filter(r => r.kind === 'label');
  labels.forEach((L, i) => {
    RECTS.forEach((R, j) => { if (R !== L && (R.kind === 'node' || j > RECTS.indexOf(L)) && overlap(L, R, 1))
      issues.push(`LABEL "${L.text}"@${Math.round(L.x)},${Math.round(L.y)} hits ${R.kind}${R.text ? ' "'+R.text+'"' : ''}@${Math.round(R.x)},${Math.round(R.y)} ${Math.round(R.w)}x${Math.round(R.h)}`); });
    SEGS.forEach(s => { if (overlap(L, segRects(s), 0)) issues.push(`LABEL "${L.text}" on line ${s.owner}`); });
  });
  return issues;
}
window.__check = check;
