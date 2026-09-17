const fs = require('fs');
const SP = DIR + '/';
const OUT = require('path').join(__dirname, '..').split(require('path').sep).join('/') + '/';
const SCALE = 3;
const send = (ws, method, params = {}) => new Promise(res => {
  const id = Math.floor(Math.random() * 1e9);
  const h = ev => { const m = JSON.parse(ev.data); if (m.id === id) { ws.removeEventListener('message', h); res(m.result); } };
  ws.addEventListener('message', h); ws.send(JSON.stringify({ id, method, params }));
});
async function page(url) {
  const info = await (await fetch('http://localhost:9337/json/new?about:blank', { method: 'PUT' })).json();
  const ws = new WebSocket(info.webSocketDebuggerUrl);
  await new Promise(r => ws.addEventListener('open', r));
  await send(ws, 'Page.enable'); await send(ws, 'Runtime.enable');
  await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1500, height: 1200, deviceScaleFactor: 1, mobile: false });
  await send(ws, 'Page.navigate', { url });
  for (let i = 0; i < 50; i++) { const r = await send(ws, 'Runtime.evaluate', { expression: 'window.__built===true', returnByValue: true }); if (r.result.value) break; await new Promise(r2 => setTimeout(r2, 150)); }
  return ws;
}
async function save(ws, id, name, solo) {
  const e = await send(ws, 'Runtime.evaluate', { expression: `JSON.stringify(exportSvg(${JSON.stringify(id)}))`, returnByValue: true });
  const { svg, w, h } = JSON.parse(e.result.value);
  fs.writeFileSync(OUT + name + '.svg', svg, 'utf8');
  if (solo) await send(ws, 'Runtime.evaluate', { expression: `show(${JSON.stringify(id)})` });
  await send(ws, 'Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: SCALE, mobile: false });
  await new Promise(r => setTimeout(r, 220));
  const shot = await send(ws, 'Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  fs.writeFileSync(OUT + name + '.png', Buffer.from(shot.data, 'base64'));
  console.log(`${name}: ${w}x${h} -> ${w * SCALE}x${h * SCALE}`);
}
(async () => {
  const a = await page('file:///' + DIR + '/dfd_build.html');
  for (const [id, name] of [['dfd_admin','02_dfd_admin'], ['dfd_owner','03_dfd_owner'],
      ['dfd_manager','04_dfd_manager'], ['dfd_cashier','05_dfd_cashier'], ['dfd_customer','06_dfd_customer']])
    await save(a, id, name, true);
  a.close();
  const b = await page('file:///' + DIR + '/uc_build.html');
  await save(b, 'usecase', '07_use_case_diagram', false);
  b.close();
  process.exit(0);
})();
