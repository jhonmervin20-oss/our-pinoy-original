const fs = require('fs');
const CDP_PORT = 9336;
const BUILD = 'file:///' + require('path').join(__dirname, 'cfd_build.html').split(require('path').sep).join('/');
const OUT = require('path').join(__dirname, '..').split(require('path').sep).join('/') + '/';
const SCALE = 3;
const FILES = [
  ['context',  '01_context_flow_diagram'],
  ['admin',    '02_cfd_admin'],
  ['owner',    '03_cfd_owner'],
  ['manager',  '04_cfd_manager'],
  ['cashier',  '05_cfd_cashier'],
  ['customer', '06_cfd_customer'],
];

function connect(url) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(url);
    ws.addEventListener('open', () => resolve(ws));
    ws.addEventListener('error', reject);
  });
}
function send(ws, method, params = {}) {
  return new Promise((resolve) => {
    const id = Math.floor(Math.random() * 1e9);
    const h = (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id === id) { ws.removeEventListener('message', h); resolve(msg.result || msg); }
    };
    ws.addEventListener('message', h);
    ws.send(JSON.stringify({ id, method, params }));
  });
}
const evalJs = async (ws, expr) => (await send(ws, 'Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;

(async () => {
  const info = await (await fetch(`http://localhost:${CDP_PORT}/json/new?about:blank`, { method: 'PUT' })).json();
  const ws = await connect(info.webSocketDebuggerUrl);
  await send(ws, 'Page.enable');
  await send(ws, 'Runtime.enable');
  await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
  await send(ws, 'Page.navigate', { url: BUILD });
  for (let i = 0; i < 40 && !(await evalJs(ws, 'window.__built === true')); i++) await new Promise(r => setTimeout(r, 150));

  for (const [id, name] of FILES) {
    const { svg, w, h } = await evalJs(ws, `exportSvg(${JSON.stringify(id)})`);
    fs.writeFileSync(OUT + name + '.svg', svg, 'utf8');
    await evalJs(ws, `show(${JSON.stringify(id)})`);
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: SCALE, mobile: false });
    await new Promise(r => setTimeout(r, 250));
    const shot = await send(ws, 'Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
    fs.writeFileSync(OUT + name + '.png', Buffer.from(shot.data, 'base64'));
    console.log(`${name}: ${w}x${h} -> png ${w * SCALE}x${h * SCALE}`);
  }
  ws.close();
  process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
