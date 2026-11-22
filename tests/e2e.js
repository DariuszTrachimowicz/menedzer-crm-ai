// Test przepływu OAuth + MCP na lokalnym Perfexie (docker compose w perfex-test).
// Użycie: node tests/e2e.js [adres] ; wymaga Playwright (PLAYWRIGHT_DIR) i kont testowych z sql/03-seed.sql.
const crypto = require('crypto');
const { chromium } = require((process.env.PLAYWRIGHT_DIR || process.env.TEMP + '/mk') + '/node_modules/playwright');
const BASE = process.argv[2] || 'http://localhost:8088';
const ISS = BASE + '/menedzer_crm_ai';
const REDIRECT = 'http://127.0.0.1:33418/callback';
let failures = 0;
const check = (ok, name, extra = '') => { console.log((ok ? 'OK   ' : 'BŁĄD ') + name + (extra ? ' :: ' + extra : '')); if (!ok) failures++; };
const b64 = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function form(url, fields) {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() });
  return { status: r.status, body: await r.json().catch(() => null) };
}
async function rpc(token, method, params, id = 1) {
  const r = await fetch(ISS + '/mcp', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + token }, body: JSON.stringify({ jsonrpc: '2.0', id, method, params }) });
  return { status: r.status, body: await r.json().catch(() => null) };
}
async function tool(token, name, args = {}) {
  const r = await rpc(token, 'tools/call', { name, arguments: args });
  const res = r.body && r.body.result;
  return { isError: res ? res.isError : true, data: res ? JSON.parse(res.content[0].text) : r.body };
}

async function login(browser, email) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/authentication');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'Test123!');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return { ctx, page };
}

/** Pełny przepływ autoryzacji dla zalogowanego pracownika. Zwraca tokeny albo tekst strony błędu. */
async function authorize(page, clientId, scope) {
  const verifier = b64(crypto.randomBytes(32));
  const challenge = b64(crypto.createHash('sha256').update(verifier).digest());
  const q = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: REDIRECT, code_challenge: challenge, code_challenge_method: 'S256', resource: ISS + '/mcp', state: 'xyz' });
  let code = null;
  // Playwright nie przechwytuje przekierowań, więc kod odbiera prawdziwy serwer na pętli zwrotnej.
  const srv = require('http').createServer((req, res) => { if (req.url.startsWith('/callback')) code = new URL(req.url, 'http://127.0.0.1:33418'); res.end('ok'); });
  await new Promise((r) => srv.listen(33418, '127.0.0.1', r));
  await page.goto(ISS + '/oauth/authorize?' + q);
  if (!(await page.locator('input[name=selected_scope]').count())) { srv.close(); return { error: await page.textContent('main') }; }
  const offered = await page.$$eval('input[name=selected_scope]', (els) => els.map((e) => e.value));
  if (scope) await page.check(`input[name=selected_scope][value=${scope}]`);
  await page.click('button[value=allow]');
  await page.waitForTimeout(800);
  srv.close();
  if (!code) return { error: 'brak przekierowania z kodem: ' + page.url() + ' | ' + (await page.textContent('body')).slice(0, 300), offered };
  check(code.searchParams.get('state') === 'xyz' && code.searchParams.get('iss') === ISS, 'przekierowanie ma state i iss', code.href);
  const t = await form(ISS + '/oauth/token', { grant_type: 'authorization_code', client_id: clientId, code: code.searchParams.get('code'), redirect_uri: REDIRECT, code_verifier: verifier, resource: ISS + '/mcp' });
  return { offered, code: code.searchParams.get('code'), verifier, token: t };
}

(async () => {
  // 1. Rejestracja klienta (DCR)
  const reg = await fetch(ISS + '/oauth/register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ client_name: 'Test Claude Code', redirect_uris: [REDIRECT], token_endpoint_auth_method: 'none', grant_types: ['authorization_code', 'refresh_token'], response_types: ['code'] }) });
  const client = await reg.json();
  check(reg.status === 201 && /^mcai_/.test(client.client_id), 'DCR rejestruje klienta', reg.status);
  const bad = await fetch(ISS + '/oauth/register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ redirect_uris: ['http://zly.example/cb'] }) });
  check(bad.status === 400, 'DCR odrzuca redirect http spoza pętli zwrotnej', bad.status);

  const browser = await chromium.launch({ channel: 'chrome' });

  // 2. Administrator: zgoda, tokeny, narzędzia
  const admin = await login(browser, 'admin@test.local');
  const a = await authorize(admin.page, client.client_id, 'full');
  check(a.token && a.token.status === 200 && /^oa\./.test(a.token.body.access_token), 'administrator dostaje token', JSON.stringify(a.error || a.token && a.token.body));
  check(JSON.stringify(a.offered) === '["read","work","full"]', 'administrator ma do wyboru 3 poziomy', JSON.stringify(a.offered));
  const access = a.token.body.access_token;
  const init = await rpc(access, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } });
  check(init.status === 200 && init.body.result.serverInfo.name === 'menedzer-crm-ai', 'initialize');
  const list = await rpc(access, 'tools/list', {});
  const names = list.body.result.tools.map((t) => t.name);
  check(names.includes('crm_status') && names.includes('crm_search'), 'tools/list', names.join(','));
  const st = await tool(access, 'crm_status');
  check(!st.isError && st.data.level === 'full' && st.data.user.admin === true, 'crm_status administratora', JSON.stringify(st.data).slice(0, 200));
  const inv = await tool(access, 'crm_inventory');
  check(!inv.isError && inv.data.resources.customers.count === 2, 'crm_inventory liczy klientów', JSON.stringify(inv.data.resources.customers));
  const cl = await tool(access, 'crm_resource_list', { resource: 'customers', search: 'Testowa' });
  check(!cl.isError && cl.data.items.length === 1 && cl.data.items[0].company.startsWith('Testowa'), 'lista klientów z wyszukiwaniem');
  const cg = await tool(access, 'crm_resource_get', { resource: 'customers', id: 1 });
  check(!cg.isError && cg.data.related.contacts.length === 1 && !('password' in cg.data.related.contacts[0]), 'klient z kontaktami, bez haseł');
  const ct = await tool(access, 'crm_resource_get', { resource: 'contacts', id: 1 });
  check(!ct.isError && !('password' in ct.data.item) && !('new_pass_key' in ct.data.item), 'kontakt bez pól z hasłem');
  const tk = await tool(access, 'crm_resource_get', { resource: 'tickets', id: 1 });
  check(!tk.isError && !('ticketkey' in tk.data.item), 'zgłoszenie bez klucza publicznego linku');
  const sf = await tool(access, 'crm_resource_get', { resource: 'staff', id: 2 });
  check(!sf.isError && !('password' in sf.data.item) && sf.data.item.firstname === 'Ewa', 'pracownik bez hasła');
  const se = await tool(access, 'crm_search', { query: 'ofert' });
  check(!se.isError && se.data.results.tasks && se.data.results.tasks.length === 1, 'wyszukiwanie w wielu zasobach', JSON.stringify(Object.keys(se.data.results || {})));
  const unk = await tool(access, 'crm_resource_list', { resource: 'customers', foo: 1 });
  check(unk.isError && unk.data.error === 'invalid_arguments', 'nieznane pole odrzucone');

  // 3. Refresh: rotacja i wykrycie powtórki
  const r1 = await form(ISS + '/oauth/token', { grant_type: 'refresh_token', client_id: client.client_id, refresh_token: a.token.body.refresh_token, resource: ISS + '/mcp' });
  check(r1.status === 200 && r1.body.refresh_token !== a.token.body.refresh_token, 'refresh rotuje token');
  const codeAgain = await form(ISS + '/oauth/token', { grant_type: 'authorization_code', client_id: client.client_id, code: a.code, redirect_uri: REDIRECT, code_verifier: a.verifier, resource: ISS + '/mcp' });
  check(codeAgain.status === 400 && codeAgain.body.error === 'invalid_grant', 'ponowne użycie kodu odrzucone');
  const afterReplay = await rpc(r1.body.access_token, 'ping', {});
  check(afterReplay.status === 401, 'powtórka kodu odcina połączenie (RFC 6749)', afterReplay.status);

  // 4. Pracownik spoza listy
  const jan = await login(browser, 'jan@test.local');
  const j = await authorize(jan.page, client.client_id);
  check(j.error && /liście/.test(j.error), 'osoba spoza listy nie może zatwierdzić', (j.error || '').slice(0, 80));

  // 5. Pracownik z listy z poziomem Odczyt
  await admin.page.goto(BASE + '/admin/menedzer_crm_ai?tab=operators');
  await admin.page.selectOption('form[action$="operator_save"] select[name=staff_id]', '2');
  await admin.page.selectOption('form[action$="operator_save"]:has(select[name=staff_id]) select[name=level]', 'read');
  await Promise.all([admin.page.waitForNavigation(), admin.page.click('form[action$="operator_save"]:has(select[name=staff_id]) button')]);
  const ewa = await login(browser, 'ewa@test.local');
  const e = await authorize(ewa.page, client.client_id);
  check(e.token && e.token.status === 200 && JSON.stringify(e.offered) === '["read"]', 'Ewa (Odczyt) dostaje tylko odczyt', JSON.stringify(e.offered || e.error));
  const ea = e.token.body.access_token;
  const est = await tool(ea, 'crm_status');
  check(est.data.level === 'read', 'poziom Ewy = odczyt');
  const einv = await tool(ea, 'crm_inventory');
  console.log('     dostęp Ewy:', JSON.stringify(Object.fromEntries(Object.entries(einv.data.resources).map(([k, v]) => [k, v.access + ':' + v.count]))));
  const etasks = await tool(ea, 'crm_resource_list', { resource: 'tasks' });
  check(!etasks.isError && etasks.data.items.length === 1, 'Ewa widzi zadanie, do którego jest przypisana');

  // 6. Usunięcie z listy odcina dostęp od razu
  await admin.page.goto(BASE + '/admin/menedzer_crm_ai?tab=operators');
  admin.page.on('dialog', (d) => d.accept());
  await Promise.all([admin.page.waitForNavigation(), admin.page.click('form[action$="operator_remove"] button')]);
  const gone = await rpc(ea, 'ping', {});
  check(gone.status === 401, 'po usunięciu z listy token Ewy nie działa', gone.status);

  await browser.close();
  console.log(failures ? `\n${failures} błędów` : '\nWSZYSTKO OK');
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
