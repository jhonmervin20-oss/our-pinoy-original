/* UML use case diagram: stick-figure actors, elliptical use cases, a system
   boundary, plain association lines, dashed <<include>>/<<extend>> arrows and
   hollow-triangle generalisations. Self-checks for crossings and collisions. */
const UNS = 'http://www.w3.org/2000/svg';
const UFONT = 'Arial, Helvetica, sans-serif';
const UINK = '#111111';
let LINES = [];   // {x1,y1,x2,y2,owner}
let OVALS = [];   // {cx,cy,rx,ry,name}
let BOXES = [];   // {x,y,w,h,kind,text}

function ue(tag, attrs, parent) {
  const e = document.createElementNS(UNS, tag);
  for (const k in attrs) e.setAttribute(k, attrs[k]);
  if (parent) parent.appendChild(e);
  return e;
}
function ucSvg(id, title, w, h) {
  LINES = []; OVALS = []; BOXES = [];
  const svg = ue('svg', { xmlns: UNS, id, width: w, height: h, viewBox: `0 0 ${w} ${h}`, 'font-family': UFONT });
  ue('title', {}, svg).textContent = title;
  const defs = ue('defs', {}, svg);
  const open = ue('marker', { id: id + '-open', viewBox: '0 0 10 10', refX: '9', refY: '5',
    markerWidth: '12', markerHeight: '12', markerUnits: 'userSpaceOnUse', orient: 'auto' }, defs);
  ue('path', { d: 'M0,0.5 L9.5,5 L0,9.5', fill: 'none', stroke: UINK, 'stroke-width': 1.4 }, open);
  const tri = ue('marker', { id: id + '-tri', viewBox: '0 0 12 12', refX: '11', refY: '6',
    markerWidth: '15', markerHeight: '15', markerUnits: 'userSpaceOnUse', orient: 'auto' }, defs);
  ue('path', { d: 'M0.5,0.5 L11,6 L0.5,11.5 Z', fill: '#fff', stroke: UINK, 'stroke-width': 1.3 }, tri);
  ue('rect', { x: 0, y: 0, width: w, height: h, fill: '#ffffff' }, svg);
  const d = { svg, id };
  d.g = ue('g', {}, svg);
  d.gTop = ue('g', {}, svg);
  document.getElementById('host').appendChild(svg);
  return d;
}
function uText(g, cx, cy, lines, size, weight, style) {
  const lh = Math.round(size * 1.18);
  const t = ue('text', { 'text-anchor': 'middle', 'font-size': size, 'font-weight': weight || 400,
    'font-style': style || 'normal', fill: UINK }, g);
  lines.forEach((ln, i) => {
    const y = cy - ((lines.length - 1) * lh) / 2 + i * lh + size * 0.35;
    ue('tspan', { x: cx, y: y.toFixed(1) }, t).textContent = ln;
  });
  return t;
}
function boundary(d, x, y, w, h, titleLines) {
  ue('rect', { x, y, width: w, height: h, fill: 'none', stroke: UINK, 'stroke-width': 1.6 }, d.g);
  uText(d.g, x + w / 2, y + 40, titleLines, 19, 600);
}
// stick figure; returns the attachment point used by association lines
function actor(d, x, y, name) {
  const r = 15;
  ue('circle', { cx: x, cy: y - 40, r, fill: '#fff', stroke: UINK, 'stroke-width': 1.6 }, d.gTop);
  ue('path', { d: `M${x},${y - 25} L${x},${y + 12} M${x - 22},${y - 12} L${x + 22},${y - 12} M${x},${y + 12} L${x - 18},${y + 44} M${x},${y + 12} L${x + 18},${y + 44}`,
    fill: 'none', stroke: UINK, 'stroke-width': 1.6 }, d.gTop);
  const t = uText(d.gTop, x, y + 64, [name], 17, 600);
  const bb = t.getBBox();
  BOXES.push({ x: x - 24, y: y - 56, w: 48, h: 112, kind: 'actor', text: name });
  BOXES.push({ x: bb.x - 3, y: bb.y - 2, w: bb.width + 6, h: bb.height + 4, kind: 'label', text: name });
  return { x, y };
}
function usecase(d, cx, cy, lines, rx, ry) {
  rx = rx || 100; ry = ry || 30;
  ue('ellipse', { cx, cy, rx, ry, fill: '#fff', stroke: UINK, 'stroke-width': 1.5 }, d.gTop);
  uText(d.gTop, cx, cy, lines, 14, 400);
  const o = { cx, cy, rx, ry, name: lines.join(' ') };
  OVALS.push(o);
  return o;
}
// clip a line from an actor to the ellipse edge
function edgePoint(o, fromX, fromY) {
  const dx = fromX - o.cx, dy = fromY - o.cy;
  const k = 1 / Math.sqrt((dx * dx) / (o.rx * o.rx) + (dy * dy) / (o.ry * o.ry));
  return { x: o.cx + dx * k, y: o.cy + dy * k };
}
function assoc(d, a, o, startOff) {
  const p = edgePoint(o, a.x, a.y);
  const dx = p.x - a.x, dy = p.y - a.y, len = Math.hypot(dx, dy);
  const off = startOff || 26;
  const sx = a.x + (dx / len) * off, sy = a.y + (dy / len) * off;
  ue('line', { x1: sx, y1: sy, x2: p.x, y2: p.y, stroke: UINK, 'stroke-width': 1.4 }, d.g);
  LINES.push({ x1: sx, y1: sy, x2: p.x, y2: p.y, owner: 'assoc:' + o.name });
}
// dashed <<include>> / <<extend>>: arrow points at `to`
function rel(d, from, to, kind, labelSide) {
  const p1 = edgePoint(from, to.cx, to.cy), p2 = edgePoint(to, from.cx, from.cy);
  ue('line', { x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y, stroke: UINK, 'stroke-width': 1.3,
    'stroke-dasharray': '7 5', 'marker-end': `url(#${d.id}-open)` }, d.g);
  LINES.push({ x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y, owner: kind + ':' + to.name + '|' + from.name });
  const mx = (p1.x + p2.x) / 2, my = (p1.y + p2.y) / 2;
  const off = labelSide === 'left' ? -52 : labelSide === 'right' ? 52 : 0;
  const t = uText(d.gTop, mx + off, my - (off ? 0 : 12), ['«' + kind + '»'], 14, 400, 'italic');
  const bb = t.getBBox();
  const r = ue('rect', { x: bb.x - 3, y: bb.y - 2, width: bb.width + 6, height: bb.height + 4, fill: '#fff' });
  d.gTop.insertBefore(r, t);
  BOXES.push({ x: bb.x - 3, y: bb.y - 2, w: bb.width + 6, h: bb.height + 4, kind: 'label', text: kind });
}
// actor generalisation, routed orthogonally around the diagram
let GEN_N = 0;
function generalise(d, pts) {
  const gid = 'gen' + (++GEN_N);
  ue('polyline', { points: pts.map(p => p.join(',')).join(' '), fill: 'none', stroke: UINK,
    'stroke-width': 1.4, 'marker-end': `url(#${d.id}-tri)` }, d.g);
  for (let i = 0; i < pts.length - 1; i++) {
    LINES.push({ x1: pts[i][0], y1: pts[i][1], x2: pts[i + 1][0], y2: pts[i + 1][1], owner: gid });
  }
}
// fan: place n use cases on an arc around an actor
function fan(actorPt, side, radius, angles) {
  return angles.map(a => {
    const rad = (a * Math.PI) / 180;
    return { x: actorPt.x + side * radius * Math.cos(rad), y: actorPt.y + radius * Math.sin(rad) };
  });
}

/* ---------------- checker ---------------- */
function segInt(a, b) {
  const d1 = (a.x2 - a.x1) * (b.y1 - a.y1) - (a.y2 - a.y1) * (b.x1 - a.x1);
  const d2 = (a.x2 - a.x1) * (b.y2 - a.y1) - (a.y2 - a.y1) * (b.x2 - a.x1);
  const d3 = (b.x2 - b.x1) * (a.y1 - b.y1) - (b.y2 - b.y1) * (a.x1 - b.x1);
  const d4 = (b.x2 - b.x1) * (a.y2 - b.y1) - (b.y2 - b.y1) * (a.x2 - b.x1);
  return ((d1 > 0) !== (d2 > 0)) && ((d3 > 0) !== (d4 > 0));
}
function lineHitsOval(l, o, pad) {
  const steps = 60;
  for (let i = 1; i < steps; i++) {
    const t = i / steps;
    const x = l.x1 + (l.x2 - l.x1) * t, y = l.y1 + (l.y2 - l.y1) * t;
    const v = ((x - o.cx) ** 2) / ((o.rx + pad) ** 2) + ((y - o.cy) ** 2) / ((o.ry + pad) ** 2);
    if (v < 1) return true;
  }
  return false;
}
function ucCheck() {
  const out = [];
  for (let i = 0; i < LINES.length; i++)
    for (let j = i + 1; j < LINES.length; j++)
      if (LINES[i].owner !== LINES[j].owner && segInt(LINES[i], LINES[j])) out.push(`CROSS ${LINES[i].owner} x ${LINES[j].owner}`);
  LINES.forEach(l => OVALS.forEach(o => {
    if (l.owner.includes(o.name)) return;
    if (lineHitsOval(l, o, 3)) out.push(`LINE ${l.owner} through oval "${o.name}"`);
  }));
  for (let i = 0; i < OVALS.length; i++)
    for (let j = i + 1; j < OVALS.length; j++) {
      const a = OVALS[i], b = OVALS[j];
      if (Math.abs(a.cx - b.cx) < a.rx + b.rx + 12 && Math.abs(a.cy - b.cy) < a.ry + b.ry + 12)
        out.push(`OVALS "${a.name}" / "${b.name}" too close`);
    }
  BOXES.forEach((B, i) => {
    OVALS.forEach(o => {
      if (B.x < o.cx + o.rx && o.cx - o.rx < B.x + B.w && B.y < o.cy + o.ry && o.cy - o.ry < B.y + B.h)
        out.push(`${B.kind} "${B.text}" overlaps oval "${o.name}"`);
    });
    LINES.forEach(l => { if (B.kind === 'label' && lineBox(l, B)) out.push(`label "${B.text}" on a line`); });
  });
  return out;
}
function lineBox(l, B) {
  const steps = 40;
  for (let i = 0; i <= steps; i++) {
    const t = i / steps, x = l.x1 + (l.x2 - l.x1) * t, y = l.y1 + (l.y2 - l.y1) * t;
    if (x > B.x && x < B.x + B.w && y > B.y && y < B.y + B.h) return true;
  }
  return false;
}
window.__ucCheck = ucCheck;
