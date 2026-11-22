// Test przepływu OAuth + MCP na lokalnym Perfexie (docker compose w perfex-test).
// Użycie: node tests/e2e.js [adres] ; wymaga Playwright (PLAYWRIGHT_DIR) i kont testowych z sql/03-seed.sql.
const crypto = require('crypto');
const { chromium } = require((process.env.PLAYWRIGHT_DIR || process.env.TEMP + '/mk') + '/node_modules/playwright');
const BASE = process.argv[2] || 'http://localhost:8088';
const ISS = BASE + '/menedzer_crm_ai';
const REDIRECT = 'http://127.0.0.1:33418/callback';
let failures = 0;
// Szczegóły tylko przy błędzie, bez tokenów i kodów: maskujemy wszystko, co wygląda jak poświadczenie.
const mask = (s) => String(s).replace(/\b(oa|or|oc|mc)\.[a-f0-9.]{20,}/g, '$1.***');
const check = (ok, name, extra = '') => { console.log((ok ? 'OK   ' : 'BŁĄD ') + name + (!ok && extra ? ' :: ' + mask(extra) : '')); if (!ok) failures++; };
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
  check(!inv.isError && inv.data.resources.customers.count >= 2, 'crm_inventory liczy klientów', JSON.stringify(inv.data.resources.customers));
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
  check(!se.isError && se.data.results.tasks && se.data.results.tasks.length >= 1, 'wyszukiwanie w wielu zasobach', JSON.stringify(Object.keys(se.data.results || {})));
  const unk = await tool(access, 'crm_resource_list', { resource: 'customers', foo: 1 });
  check(unk.isError && unk.data.error === 'invalid_arguments', 'nieznane pole odrzucone');

  // 3. Refresh: rotacja i wykrycie powtórki
  const r1 = await form(ISS + '/oauth/token', { grant_type: 'refresh_token', client_id: client.client_id, refresh_token: a.token.body.refresh_token, resource: ISS + '/mcp' });
  check(r1.status === 200 && r1.body.refresh_token !== a.token.body.refresh_token, 'refresh rotuje token');
  const r2 = await form(ISS + '/oauth/token', { grant_type: 'refresh_token', client_id: client.client_id, refresh_token: a.token.body.refresh_token, resource: ISS + '/mcp' });
  const afterRefreshReplay = await rpc(r1.body.access_token, 'ping', {});
  check(r2.status === 400 && afterRefreshReplay.status === 401, 'ponowny refresh tym samym tokenem zrywa połączenie (RFC 9700)', r2.status + '/' + afterRefreshReplay.status);
  // Powtórka kodu na osobnym, świeżym połączeniu: zły weryfikator nic nie zrywa, dobry zrywa.
  const a3 = await authorize(admin.page, client.client_id, 'read');
  const A3 = a3.token.body.access_token;
  const wrongVerifier = await form(ISS + '/oauth/token', { grant_type: 'authorization_code', client_id: client.client_id, code: a3.code, redirect_uri: REDIRECT, code_verifier: 'x'.repeat(43), resource: ISS + '/mcp' });
  const aliveAfterWrong = await rpc(A3, 'ping', {});
  check(wrongVerifier.status === 400 && aliveAfterWrong.status === 200, 'powtórka kodu bez PKCE nie zrywa połączenia', wrongVerifier.status + '/' + aliveAfterWrong.status);
  const codeAgain = await form(ISS + '/oauth/token', { grant_type: 'authorization_code', client_id: client.client_id, code: a3.code, redirect_uri: REDIRECT, code_verifier: a3.verifier, resource: ISS + '/mcp' });
  const afterReplay = await rpc(A3, 'ping', {});
  check(codeAgain.status === 400 && afterReplay.status === 401, 'powtórka kodu z PKCE zrywa połączenie (RFC 6749)', codeAgain.status + '/' + afterReplay.status);

  // 4. Pracownik spoza listy
  const jan = await login(browser, 'jan@test.local');
  const j = await authorize(jan.page, client.client_id);
  check(j.error && /liście/.test(j.error), 'osoba spoza listy nie może zatwierdzić', (j.error || '').slice(0, 80));

  // 5. Pracownik z listy z poziomem Odczyt (najpierw sprzątanie po poprzednim uruchomieniu)
  await admin.page.goto(BASE + '/admin/menedzer_crm_ai?tab=operators');
  admin.page.on('dialog', (d) => d.accept());
  const leftover = admin.page.locator('form[action$="operator_remove"]:has(input[name=staff_id][value="2"]) button');
  if (await leftover.count()) {
    await Promise.all([admin.page.waitForNavigation(), leftover.click()]);
  }
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
  check(!etasks.isError && etasks.data.items.length >= 1 && etasks.data.items.some((t) => Number(t.id) === 1), 'Ewa widzi zadanie, do którego jest przypisana');

  // 6. Usunięcie z listy odcina dostęp od razu
  await admin.page.goto(BASE + '/admin/menedzer_crm_ai?tab=operators');
  await Promise.all([admin.page.waitForNavigation(), admin.page.click('form[action$="operator_remove"] button')]);
  const gone = await rpc(ea, 'ping', {});
  check(gone.status === 401, 'po usunięciu z listy token Ewy nie działa', gone.status);

  // 7. Zapisy: podgląd, wykonanie, sprawdzenie, cofnięcie
  const a2 = await authorize(admin.page, client.client_id, 'full');
  const T = a2.token.body.access_token;
  const tl = (await rpc(T, 'tools/list', {})).body.result.tools.map((t) => t.name);
  check(tl.includes('crm_operation_preview') && !tl.some((n) => /delete|remove|send/.test(n)), 'narzędzia zapisu są, usuwania i wysyłki brak', tl.join(','));
  let key = 0;
  const run = async (action, change, target_id) => {
    const pv = await tool(T, 'crm_operation_preview', target_id ? { action, change, target_id } : { action, change });
    if (pv.isError) return { pv, ex: { isError: true, data: pv.data } };
    const ex = await tool(T, 'crm_operation_execute', { operation_id: pv.data.operation_id, confirm: true, idempotency_key: 'k' + (++key) });
    return { pv, ex, key: 'k' + key };
  };
  const c1 = await run('task.create', { name: 'Zadanie od AI', assignees: [2], rel_type: 'customer', rel_id: 1, duedate: '2026-12-31' });
  check(!c1.ex.isError && c1.ex.data.status === 'verified' && c1.ex.data.new_id > 0, 'task.create wykonane i sprawdzone', JSON.stringify(c1.ex.data));
  const nt = await tool(T, 'crm_resource_get', { resource: 'tasks', id: c1.ex.data.new_id });
  check(nt.data.item && nt.data.item.name === 'Zadanie od AI' && nt.data.related.assignees.some((x) => Number(x.staffid) === 2), 'nowe zadanie ma przypisaną osobę');
  const same = await tool(T, 'crm_operation_execute', { operation_id: c1.pv.data.operation_id, confirm: true, idempotency_key: c1.key });
  check(!same.isError && same.data.new_id === c1.ex.data.new_id, 'ponowienie z tym samym kluczem zwraca ten sam wynik');
  const other = await tool(T, 'crm_operation_execute', { operation_id: c1.pv.data.operation_id, confirm: true, idempotency_key: 'inny' });
  check(other.isError && other.data.error === 'already_executed', 'inny klucz nie wykona drugi raz');
  const rb1 = await tool(T, 'crm_operation_rollback', { operation_id: c1.pv.data.operation_id, confirm: true });
  check(rb1.isError && rb1.data.error === 'rollback_unavailable', 'utworzenia zadania AI nie cofa', JSON.stringify(rb1.data));
  check(c1.pv.data.rollback === false && c1.ex.data.rollback_available === false, 'podgląd uprzedza, że bez cofania');

  const u1 = await run('task.update', { status: 5 }, 1);
  check(!u1.ex.isError && u1.pv.data.before.status === '1', 'task.update status z podglądem stanu przed', JSON.stringify(u1.pv.data.before || u1.ex.data));
  const t1 = await tool(T, 'crm_resource_get', { resource: 'tasks', id: 1 });
  check(t1.data.item.status === '5' && !!t1.data.item.datefinished, 'status 5 i data ukończenia ustawione');
  const rb2 = await tool(T, 'crm_operation_rollback', { operation_id: u1.pv.data.operation_id, confirm: true });
  const t1b = await tool(T, 'crm_resource_get', { resource: 'tasks', id: 1 });
  check(!rb2.isError && t1b.data.item.status === '1' && !t1b.data.item.datefinished, 'cofnięcie przywraca status i datę', JSON.stringify(rb2.data));

  const pr = await run('project.update', { status: 4 }, 1);
  const prg = await tool(T, 'crm_resource_get', { resource: 'projects', id: 1 });
  check(!pr.ex.isError && prg.data.item.status === '4' && !!prg.data.item.date_finished, 'zamknięcie projektu ustawia datę zakończenia', JSON.stringify(pr.ex.data));
  const prb = await tool(T, 'crm_operation_rollback', { operation_id: pr.pv.data.operation_id, confirm: true });
  const prg2 = await tool(T, 'crm_resource_get', { resource: 'projects', id: 1 });
  check(!prb.isError && prg2.data.item.status === '2' && !prg2.data.item.date_finished, 'cofnięcie przywraca status i czyści datę', JSON.stringify(prb.data));

  const pA = await tool(T, 'crm_operation_preview', { action: 'task.update', target_id: 1, change: { name: 'Wersja A' } });
  const pB = await run('task.update', { name: 'Wersja B' }, 1);
  const exA = await tool(T, 'crm_operation_execute', { operation_id: pA.data.operation_id, confirm: true, idempotency_key: 'A' });
  check(exA.isError && exA.data.error === 'conflict', 'zmiana po podglądzie = konflikt, bez nadpisania', JSON.stringify(exA.data));
  await tool(T, 'crm_operation_rollback', { operation_id: pB.pv.data.operation_id, confirm: true });

  const d1 = await run('ticket.draft_reply', { message: 'Dzień dobry, proszę spróbować zresetować hasło.' }, 1);
  const tk2 = await tool(T, 'crm_resource_get', { resource: 'tickets', id: 1 });
  check(!d1.ex.isError && tk2.data.related.notes.length >= 1 && tk2.data.related.replies.length === 0, 'szkic odpowiedzi jako notatka, bez odpowiedzi do klienta', JSON.stringify(d1.ex.data));

  const cc = await run('customer.create', { company: 'Firma od AI', city: 'Kraków' });
  const mail = 'ala' + Date.now() + '@nowa.test';
  const ko = await run('contact.create', { userid: cc.ex.data.new_id, firstname: 'Ala', lastname: 'Nowa', email: mail });
  check(!ko.ex.isError, 'kontakt dla nowego klienta', JSON.stringify(ko.ex.data));
  const kg = await tool(T, 'crm_resource_get', { resource: 'contacts', id: ko.ex.data.new_id });
  check(kg.data.item && kg.data.item.is_primary === '1', 'pierwszy kontakt jest główny');
  check(kg.data.item && kg.data.item.invoice_emails === '0' && kg.data.item.ticket_emails === '0', 'nowy kontakt bez maili z fakturami i zgłoszeniami', JSON.stringify(kg.data.item));
  const dup = await tool(T, 'crm_operation_preview', { action: 'contact.create', change: { userid: 1, firstname: 'X', lastname: 'Y', email: mail } });
  check(dup.isError && /istnieje/.test(dup.data.message), 'duplikat e-maila kontaktu odrzucony');
  const rbC = await tool(T, 'crm_operation_rollback', { operation_id: cc.pv.data.operation_id, confirm: true });
  check(rbC.isError && rbC.data.error === 'rollback_unavailable', 'utworzenia klienta AI nie cofa (usuwa człowiek)');
  const cm = await run('task.comment', { content: '<p>Notatka robocza</p>' }, 1);
  const rbM = await tool(T, 'crm_operation_rollback', { operation_id: cm.pv.data.operation_id, confirm: true });
  check(!cm.ex.isError && !rbM.isError && rbM.data.status === 'rolled_back', 'własny komentarz da się cofnąć', JSON.stringify(rbM.data));
  const rbD = await tool(T, 'crm_operation_rollback', { operation_id: d1.pv.data.operation_id, confirm: true });
  check(!rbD.isError && rbD.data.status === 'rolled_back', 'szkic odpowiedzi da się cofnąć', JSON.stringify(rbD.data));

  const badField = await tool(T, 'crm_operation_preview', { action: 'customer.update', target_id: 1, change: { active: 0 } });
  check(badField.isError && badField.data.error === 'invalid_change', 'pole spoza listy (active) odrzucone');
  const group = Number((await tool(T, 'crm_inventory')).data.lookups.kb_groups[0].id);
  const kb = await run('kb.create', { subject: 'Jak zresetować hasło', description: '<p>Kroki</p><script>alert(1)</script>', articlegroup: group });
  const kbg = await tool(T, 'crm_resource_get', { resource: 'knowledge_base', id: kb.ex.data.new_id });
  check(kbg.data.item && kbg.data.item.active === '0' && kbg.data.item.staff_article === '1' && !/script/.test(kbg.data.item.description), 'artykuł jako wewnętrzny szkic, bez skryptu', JSON.stringify(kb.ex.data));
  const kbActive = await tool(T, 'crm_operation_preview', { action: 'kb.create', change: { subject: 'X', description: 'Y', articlegroup: group, active: 1 } });
  check(kbActive.isError && kbActive.data.error === 'invalid_change', 'AI nie może opublikować artykułu (pole active odrzucone)');
  const ctTarget = await tool(T, 'crm_operation_preview', { action: 'contact.create', target_id: 1, change: { userid: 1, firstname: 'A', lastname: 'B', email: 'ab@x.test' } });
  check(ctTarget.isError && /target_id/.test(ctTarget.data.message), 'contact.create odrzuca zbędny target_id');
  const badId = await tool(T, 'crm_operation_status', { operation_id: c1.pv.data.operation_id.toUpperCase() });
  check(badId.isError && badId.data.error === 'invalid_arguments', 'id operacji tylko w postaci kanonicznej');
  const getRevoke = await admin.page.goto(BASE + '/admin/menedzer_crm_ai/connections_revoke_all');
  const stillOk = await rpc(T, 'ping', {});
  check(getRevoke.status() === 405 && stillOk.status === 200, 'GET nie odcina połączeń (CSRF)', getRevoke.status() + '/' + stillOk.status);

  // 8. Ewa na poziomie Praca: komentarz tak, nowy klient nie
  await admin.page.goto(BASE + '/admin/menedzer_crm_ai?tab=operators');
  await admin.page.selectOption('form[action$="operator_save"] select[name=staff_id]', '2');
  await admin.page.selectOption('form[action$="operator_save"]:has(select[name=staff_id]) select[name=level]', 'work');
  await Promise.all([admin.page.waitForNavigation(), admin.page.click('form[action$="operator_save"]:has(select[name=staff_id]) button')]);
  const e2 = await authorize(ewa.page, client.client_id, 'work');
  const E = e2.token.body.access_token;
  const ecom = await tool(E, 'crm_operation_preview', { action: 'task.comment', target_id: 1, change: { content: 'Zrobione w połowie' } });
  check(!ecom.isError, 'Ewa może skomentować swoje zadanie', JSON.stringify(ecom.data));
  // Wykonanie przez osobę z ograniczoną widocznością: blokady w podzapytaniach widoczności (LOCK IN SHARE MODE).
  const ecx = await tool(E, 'crm_operation_execute', { operation_id: ecom.data.operation_id, confirm: true, idempotency_key: 'ewa1' });
  check(!ecx.isError && ecx.data.status === 'verified', 'Ewa wykonuje komentarz (widoczność z blokadą)', JSON.stringify(ecx.data));
  const erb = await tool(E, 'crm_operation_rollback', { operation_id: ecom.data.operation_id, confirm: true });
  check(!erb.isError && erb.data.status === 'rolled_back', 'Ewa cofa własny komentarz', JSON.stringify(erb.data));
  const ecust = await tool(E, 'crm_operation_preview', { action: 'customer.create', change: { company: 'X' } });
  check(ecust.isError, 'Ewa (Praca) nie tworzy klientów', JSON.stringify(ecust.data));
  const eupd = await tool(E, 'crm_operation_preview', { action: 'task.update', target_id: 1, change: { name: 'Zmiana' } });
  check(eupd.isError && eupd.data.error === 'forbidden', 'Ewa bez prawa edycji zadań zmienia tylko status', JSON.stringify(eupd.data));
  const etask = await tool(E, 'crm_resource_get', { resource: 'tasks', id: 1 });
  check(!etask.isError && !('hourly_rate' in etask.data.item), 'bez prawa edycji zadań stawka cudzego zadania ukryta', JSON.stringify(etask.data.item));
  const estaff = await tool(E, 'crm_resource_get', { resource: 'staff', id: 1 });
  check(!estaff.isError && estaff.data.item.firstname && !('email' in estaff.data.item) && !('last_login' in estaff.data.item), 'bez prawa do personelu tylko imię i nazwisko', JSON.stringify(estaff.data));

  await browser.close();
  console.log(failures ? `\n${failures} błędów` : '\nWSZYSTKO OK');
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(mask(e && e.stack || e)); process.exit(2); });
