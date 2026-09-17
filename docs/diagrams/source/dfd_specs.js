/* Layout: entity (left) -> process column -> data store column.
   Entity flows fan from the entity's top/bottom edge on nested channels;
   store flows leave the process's right edge on nested channels. Nesting
   order is what keeps every line crossing-free (verified by __check). */
const EX = 30, EW = 120, EH = 88;
const PX = 340, PW = 186, PH = 100;
const SX = 712, SW = 196, SH = 38;
const CANVAS_W = 930;

// Channels are chosen one flow at a time: the first x that introduces no
// crossing with anything already routed. Longest runs are placed first.
function flowPts(f, cx) {
  // top/bottom entry: the vertical must sit inside the store's own width
  if (f._edge !== undefined) return [[PX + PW, f._pa], [cx, f._pa], [cx, f._edge]];
  if (cx === undefined) return [[PX + PW, f._pa], [SX, f._sa]];
  return [[PX + PW, f._pa], [cx, f._pa], [cx, f._sa], [SX, f._sa]];
}
function flowSegs(f, cx) {
  const p = flowPts(f, cx), out = [];
  for (let i = 0; i < p.length - 1; i++) out.push({ x1: p[i][0], y1: p[i][1], x2: p[i + 1][0], y2: p[i + 1][1] });
  return out;
}
function hits(a, b) {
  const ah = a.y1 === a.y2, bh = b.y1 === b.y2;
  if (ah !== bh) {
    const H = ah ? a : b, V = ah ? b : a;
    const hx1 = Math.min(H.x1, H.x2), hx2 = Math.max(H.x1, H.x2);
    const vy1 = Math.min(V.y1, V.y2), vy2 = Math.max(V.y1, V.y2);
    return V.x1 > hx1 + 0.5 && V.x1 < hx2 - 0.5 && H.y1 > vy1 + 0.5 && H.y1 < vy2 - 0.5;
  }
  const r = s => ({ x: Math.min(s.x1, s.x2) - 3, y: Math.min(s.y1, s.y2) - 3,
                    w: Math.abs(s.x2 - s.x1) + 6, h: Math.abs(s.y2 - s.y1) + 6 });
  const A = r(a), B = r(b);
  return A.x < B.x + B.w && B.x < A.x + A.w && A.y < B.y + B.h && B.y < A.y + A.h;
}
function assignChannels(flows) {
  const straight = flows.filter(f => Math.abs(f._sa - f._pa) <= 2 && f._edge === undefined);
  straight.forEach(f => { f._cx = undefined; });
  const elbows = flows.filter(f => Math.abs(f._sa - f._pa) > 2 || f._edge !== undefined);
  const fixed = straight.map(f => flowSegs(f, undefined));

  const tryOrder = (order) => {
    const placed = fixed.slice();
    for (const f of order) {
      let ok = false;
      const slots = f._edge !== undefined
        ? [SX + 34, SX + 58, SX + 82, SX + 106, SX + 130, SX + 22, SX + 146]
        : [0, 1, 2, 3, 4, 5, 6, 7].map(k => PX + PW + 14 + k * 14);
      for (let k = 0; k < slots.length; k++) {
        const cx = slots[k];
        const segs = flowSegs(f, cx);
        if (!placed.some(g => g.some(s2 => segs.some(s1 => hits(s1, s2))))) {
          f._cx = cx; placed.push(segs); ok = true; break;
        }
      }
      if (!ok) return false;
    }
    return true;
  };

  const bySpan = (dir) => elbows.slice().sort((a, b) =>
    dir * (Math.abs(b._sa - b._pa) - Math.abs(a._sa - a._pa)));
  const orders = [bySpan(1), bySpan(-1),
    elbows.slice().sort((a, b) => a._pa - b._pa),
    elbows.slice().sort((a, b) => b._pa - a._pa),
    elbows.slice().sort((a, b) => a._sa - b._sa)];
  for (const o of orders) if (tryOrder(o)) return;
  // deterministic shuffles as a fallback
  let seed = 7;
  const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
  for (let t = 0; t < 400; t++) {
    const o = elbows.slice();
    for (let i = o.length - 1; i > 0; i--) { const j = Math.floor(rnd() * (i + 1)); [o[i], o[j]] = [o[j], o[i]]; }
    if (tryOrder(o)) return;
  }
  tryOrder(bySpan(1));
}

function buildDfd(spec) {
  const d = newSvg(spec.id, spec.title, spec.W || CANVAS_W, spec.H);
  const tasks = [];
  const rows = spec.rows, stores = spec.stores;
  const eTop = spec.entityY - EH / 2, eBot = spec.entityY + EH / 2;

  // ---- entity flows -------------------------------------------------
  const byRow = {};
  spec.eflows.forEach(f => { (byRow[f.p] = byRow[f.p] || []).push(f); });
  const routed = [];
  spec.eflows.forEach(f => {
    const pair = byRow[f.p].length > 1;
    const attachY = rows[f.p].y + (pair ? (f.dir === 'in' ? -15 : 15) : 0);
    routed.push({ f, attachY });
  });
  const above = routed.filter(r => r.attachY < eTop + 10).sort((a, b) => a.attachY - b.attachY);
  const below = routed.filter(r => r.attachY > eBot - 10).sort((a, b) => b.attachY - a.attachY);
  const chanX = (i, n) => EX + 16 + i * Math.min(15, (EW - 32) / Math.max(1, n - 1));
  above.forEach((r, i) => { r.cx = chanX(i, above.length); r.edgeY = eTop; });
  below.forEach((r, i) => { r.cx = chanX(i, below.length); r.edgeY = eBot; });

  routed.forEach(r => {
    const y = r.attachY, f = r.f, owner = 'e' + f.p + f.dir;
    let pts;
    if (r.cx === undefined) {
      pts = f.dir === 'in' ? [[EX + EW, y], [PX, y]] : [[PX, y], [EX + EW, y]];
    } else if (f.dir === 'in') {
      pts = [[r.cx, r.edgeY], [r.cx, y], [PX, y]];
    } else {
      pts = [[PX, y], [r.cx, y], [r.cx, r.edgeY]];
    }
    polyline(d, pts, owner);
    const pref = byRow[f.p].length > 1 ? (f.dir === 'in' ? 'above' : 'below') : 'above';
    const alt = pref === 'above' ? 'below' : 'above';
    tasks.push([f.label, [
      { x: PX - 13, y, place: pref, align: 'end' },
      { x: PX - 13, y, place: alt, align: 'end' },
      { x: PX - 48, y, place: pref, align: 'end' },
      { x: PX - 48, y, place: alt, align: 'end' },
    ]]);
  });

  // ---- store flows ---------------------------------------------------
  const byProc = {}, byStore = {};
  spec.sflows.forEach((f, i) => {
    f._i = i;
    (byProc[f.p] = byProc[f.p] || []).push(f);
    (byStore[f.s] = byStore[f.s] || []).push(f);
  });
  const spread = n => [[0], [0], [-20, 20], [-27, 0, 27], [-33, -11, 11, 33]][n] || [-33, -11, 11, 33];
  Object.keys(byProc).forEach(p => {
    const list = byProc[p].sort((a, b) => stores[a.s].y - stores[b.s].y);
    // stores above the row attach high on the edge, level stores dead centre,
    // stores below attach low -- so a level store always gets a straight line
    const rowY = rows[p].y;
    const above = list.filter(f => stores[f.s].y < rowY - 34);
    const level = list.filter(f => Math.abs(stores[f.s].y - rowY) <= 34);
    const below = list.filter(f => stores[f.s].y > rowY + 34);
    above.forEach((f, i) => { f._pa = rowY - 46 + i * (above.length > 1 ? 22 / (above.length - 1) : 0); f._side = 'up'; });
    level.forEach((f, i) => { f._pa = rowY + (level.length === 1 ? 0 : -11 + i * 22); f._side = 'level'; });
    below.forEach((f, i) => { f._pa = rowY + 24 + i * (below.length > 1 ? 22 / (below.length - 1) : 0); f._side = 'down'; });

  });
  Object.keys(byStore).forEach(s => {
    const list = byStore[s].sort((a, b) => rows[a.p].y - rows[b.p].y);
    const offs = [[0], [0], [-11, 11], [-12, 0, 12], [-12, -4, 4, 12], [-12, -6, 0, 6, 12]][list.length] || [-12, 0, 12];
    const levels = list.filter(f => f._side === 'level');
    const lOffs = [[0], [0], [-11, 11], [-12, 0, 12]][levels.length] || [-12, 0, 12];
    levels.forEach((f, i) => { f._sa = stores[s].y + lOffs[i]; f._pa = f._sa; });
    list.filter(f => f._side !== 'level').forEach(f => {
      f._sa = stores[s].y;
      f._edge = f._side === 'up' ? stores[s].y + SH / 2 : stores[s].y - SH / 2;
    });
  });
  assignChannels(spec.sflows);
  spec.sflows.forEach(f => {
    const owner = 's' + f._i;
    const chain = flowPts(f, f._cx);
    polyline(d, f.dir === 'write' ? chain : chain.slice().reverse(), owner);
    const near = (f._cx === undefined ? PX + PW : f._cx) + 11;
    const pref = f._sa < f._pa ? 'below' : 'above';
    const alt = pref === 'above' ? 'below' : 'above';
    tasks.push([f.label, f._edge === undefined
      ? [{ x: SX - 11, y: f._sa, place: 'above', align: 'end' },
         { x: SX - 11, y: f._sa, place: 'below', align: 'end' },
         { x: near, y: f._pa, place: 'above', align: 'start' },
         { x: near, y: f._pa, place: 'below', align: 'start' }]
      : [{ x: near, y: f._pa, place: pref, align: 'start' },
         { x: near, y: f._pa, place: alt, align: 'start' },
         { x: SX - 11, y: f._pa, place: pref, align: 'end' },
         { x: SX - 11, y: f._pa, place: alt, align: 'end' },
         { x: near + 30, y: f._pa, place: pref, align: 'start' }]]);
  });

  // ---- process-to-process flows ---------------------------------------
  (spec.pflows || []).forEach((f, i) => {
    const cx = PX + PW / 2;
    const a = rows[f.from].y, b = rows[f.to].y;
    const from = a < b ? a + PH / 2 : a - PH / 2;
    const to = a < b ? b - PH / 2 : b + PH / 2;
    polyline(d, [[cx, from], [cx, to]], 'p' + i);
    tasks.push([f.label, [
      { x: cx + 9, y: (from + to) / 2, place: 'mid', align: 'start' },
      { x: cx - 9, y: (from + to) / 2, place: 'mid', align: 'end' },
    ]]);
  });

  // ---- nodes -----------------------------------------------------------
  rows.forEach(r => processBox(d, PX, r.y - PH / 2, PW, PH, r.num, r.name));
  stores.forEach(s => storeBox(d, SX, s.y - SH / 2, SW, SH, s.id, s.name));
  box(d, EX, eTop, EW, EH, [spec.entity], 16);
  (spec.extras || []).forEach(x => box(d, x.x, x.y, x.w, x.h, [x.name], 16));
  (spec.xflows || []).forEach((f, i) => {
    polyline(d, f.pts, 'x' + i);
    tasks.push([f.label, [
      { x: f.lx, y: f.ly, place: f.place || 'above', align: f.align || 'middle' },
      { x: f.lx, y: f.ly, place: f.place === 'below' ? 'above' : 'below', align: f.align || 'middle' },
    ]]);
  });
  tasks.forEach(t => placeLabel(d, t[0], t[1]));
  window.__results[spec.id] = check();
  return d;
}

/* ============================ CASHIER ============================== */
buildDfd({
  id: 'dfd_cashier', title: 'Level 1 DFD of the Cashier', entity: 'Cashier',
  H: 700, entityY: 232,
  rows: [
    { num: '1.0', name: ['Log In'], y: 92 },
    { num: '2.0', name: ['Start and', 'Close Shift'], y: 232 },
    { num: '3.0', name: ['Process Order'], y: 400 },
    { num: '4.0', name: ['Link Reservation'], y: 540 },
    { num: '5.0', name: ['Request', 'Order Void'], y: 640 },
  ],
  stores: [
    { id: 'D1', name: 'Users', y: 92 },
    { id: 'D7', name: 'Cash Balances', y: 232 },
    { id: 'D4', name: 'Orders', y: 292 },
    { id: 'D2', name: 'Menu', y: 400 },
    { id: 'D3', name: 'Inventory', y: 478 },
    { id: 'D5', name: 'Reservations', y: 546 },
    { id: 'D8', name: 'Void Requests', y: 640 },
  ],
  eflows: [
    { p: 0, dir: 'in', label: ['Email and password'] },
    { p: 0, dir: 'out', label: ['Login status'] },
    { p: 1, dir: 'in', label: ['Opening cash float', 'and counted cash'] },
    { p: 1, dir: 'out', label: ['Remittance report'] },
    { p: 2, dir: 'in', label: ['Order items, discount,', 'and payment'] },
    { p: 2, dir: 'out', label: ['Receipt'] },
    { p: 3, dir: 'in', label: ['Reservation number'] },
    { p: 4, dir: 'in', label: ['Void request', 'and reason'] },
  ],
  sflows: [
    { p: 0, s: 0, dir: 'read', label: ['User account'] },
    { p: 1, s: 1, dir: 'write', label: ['Shift record'] },
    { p: 1, s: 2, dir: 'read', label: ['Shift sales'] },
    { p: 2, s: 2, dir: 'write', label: ['Order and payment'] },
    { p: 2, s: 3, dir: 'read', label: ['Menu items and prices'] },
    { p: 2, s: 4, dir: 'write', label: ['Stock deduction'] },
    { p: 3, s: 5, dir: 'read', label: ['Reservation and', 'advance order'] },
    { p: 4, s: 6, dir: 'write', label: ['Void request'] },
  ],
  pflows: [{ from: 3, to: 2, label: ['Advance order', 'and credit'] }],
});
