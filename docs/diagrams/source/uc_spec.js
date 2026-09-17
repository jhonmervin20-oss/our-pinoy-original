(function () {
  const W = 1440, H = 1800;
  const M = 720;                      // middle column of use cases
  const d = ucSvg('usecase', 'Use Case Diagram', W, H);
  boundary(d, 290, 150, 860, 1470, ['Website with Restaurant Management and Reservation System']);

  // ---- actors (outside the boundary) ----
  const user     = actor(d, M, 78, 'User');
  const admin    = actor(d, 150, 430, 'Admin');
  const owner    = actor(d, 150, 1100, 'Owner');
  const cashier  = actor(d, 1250, 430, 'Cashier');
  const manager  = actor(d, 1250, 840, 'Manager');
  const customer = actor(d, 1250, 1330, 'Customer');
  const paymongo = actor(d, M, 1700, 'PayMongo');

  // ---- use cases ----
  const login = usecase(d, M, 250, ['Log In']);

  const aPts = fan(admin, +1, 320, [-38, -13, 13, 38]);
  const ucAdmin = [
    ['Manage User', 'Accounts'], ['View Activity Logs'],
    ['Manage Employee', 'Records'], ['Manage Schedules', 'and Holidays'],
  ].map((t, i) => usecase(d, aPts[i].x, aPts[i].y, t));

  const oPts = [[470, 1000], [480, 1095], [475, 1190], [460, 1285], [415, 1375], [335, 1465]]
    .map(p => ({ x: p[0], y: p[1] }));
  const ucOwner = [
    ['Manage Menu', 'and Costing'], ['Manage Suppliers', 'and Purchase Orders'],
    ['View Reports', 'and Analytics'], ['View Demand', 'Forecast'],
    ['Moderate Feedback'], ['Configure System', 'Settings'],
  ].map((t, i) => usecase(d, oPts[i].x, oPts[i].y, t));

  // shared by Owner and Manager
  const shInventory = usecase(d, M, 575, ['Manage Inventory']);
  const shVoid      = usecase(d, M, 720, ['Approve Void Request']);

  // Manager's own use cases, in the middle column
  const mgrOwn = [
    usecase(d, M, 830, ['Receive Purchase', 'Order Stock']),
    usecase(d, M, 920, ['Manage Attendance', 'and Leave']),
    usecase(d, M, 1010, ['Process Payroll Run']),
    usecase(d, M, 1100, ['Monitor Employee', 'Performance']),
  ];

  const cPts = fan(cashier, -1, 320, [-38, -13, 13, 38]);
  const ucCashier = [
    ['Start and Close Shift'], ['Process Order'],
    ['Request Order Void'], ['View Reservations'],
  ].map((t, i) => usecase(d, cPts[i].x, cPts[i].y, t));

  // Customer: fan of five with a gap left for Book Reservation in the middle
  const kPts = fan(customer, -1, 320, [-48, -30, 10, 28, 46]);
  const ucCustomer = [
    ['Register Account'], ['Browse Menu'],
    ['Cancel Reservation'], ['Submit Feedback'], ['Ask Chatbot'],
  ].map((t, i) => usecase(d, kPts[i].x, kPts[i].y, t));

  const advOrder  = usecase(d, M, 1180, ['Place Advance Order']);
  const bookRes   = usecase(d, M, 1270, ['Book Reservation']);
  const payOnline = usecase(d, 700, 1380, ['Pay Reservation', 'Online']);

  // ---- associations ----
  assoc(d, user, login, 95);
  ucAdmin.forEach(o => assoc(d, admin, o, o.cy > admin.y + 60 ? 85 : 26));
  ucOwner.forEach(o => assoc(d, owner, o, o.cy > owner.y + 60 ? 85 : 26));
  [shInventory, shVoid].forEach(o => { assoc(d, owner, o); assoc(d, manager, o); });
  mgrOwn.forEach(o => assoc(d, manager, o, o.cy > manager.y + 60 ? 85 : 26));
  ucCashier.forEach(o => assoc(d, cashier, o, o.cy > cashier.y + 60 ? 85 : 26));
  ucCustomer.forEach(o => assoc(d, customer, o, o.cy > customer.y + 60 ? 85 : 26));
  assoc(d, customer, bookRes);
  assoc(d, paymongo, payOnline);

  // ---- include / extend ----
  rel(d, bookRes, payOnline, 'include', 'right');
  rel(d, advOrder, bookRes, 'extend', 'right');

  // ---- actor generalisation: every role is a User ----
  generalise(d, [[118, 410], [58, 410], [58, 48], [M - 48, 48]]);
  generalise(d, [[118, 1080], [30, 1080], [30, 24], [M - 48, 24]]);
  generalise(d, [[1282, 410], [1342, 410], [1342, 72], [M + 48, 72]]);
  generalise(d, [[1282, 820], [1368, 820], [1368, 48], [M + 48, 48]]);
  generalise(d, [[1282, 1310], [1400, 1310], [1400, 24], [M + 48, 24]]);

  window.__ucResult = ucCheck();
})();
