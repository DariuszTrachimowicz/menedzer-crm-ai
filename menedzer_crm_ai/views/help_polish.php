<?php defined('BASEPATH') or exit('No direct script access allowed'); $u = mcai_e($mcpUrl); ?>
<h4>Pomoc</h4>
<p>Adres serwera MCP tego CRM: <code><?= $u ?></code></p>

<h5 class="tw-font-semibold tw-mt-6">Claude w przeglądarce i Claude Desktop</h5>
<ol>
<li>W Claude otwórz Ustawienia, Konektory, Dodaj własny konektor.</li>
<li>Wklej adres serwera MCP i zapisz.</li>
<li>Kliknij Połącz. Zaloguj się do CRM, wybierz poziom dostępu i termin, zatwierdź.</li>
</ol>

<h5 class="tw-font-semibold tw-mt-6">Claude Code</h5>
<pre>claude mcp add --transport http crm <?= $u ?></pre>
<p>Potem w Claude Code wpisz <code>/mcp</code>, wybierz <code>crm</code> i zaloguj się w przeglądarce.</p>

<h5 class="tw-font-semibold tw-mt-6">Codex (CLI i aplikacja)</h5>
<pre>codex mcp add crm --url <?= $u ?>

codex mcp login crm</pre>
<p>Gdy logowanie w przeglądarce nie działa, utwórz ręczne poświadczenie w zakładce Połączenia i dodaj do <code>~/.codex/config.toml</code>:</p>
<pre>[mcp_servers.crm]
url = "<?= $u ?>"
bearer_token_env_var = "CRM_MCP_TOKEN"</pre>
<p>Token wpisz do zmiennej środowiskowej <code>CRM_MCP_TOKEN</code>, nie do pliku.</p>

<h5 class="tw-font-semibold tw-mt-6">ChatGPT</h5>
<ol>
<li>W ChatGPT włącz tryb dewelopera: Ustawienia, Aplikacje i konektory, Zaawansowane.</li>
<li>Utwórz konektor, wklej adres serwera MCP, uwierzytelnianie OAuth.</li>
<li>Zaloguj się do CRM i zatwierdź dostęp.</li>
</ol>

<h5 class="tw-font-semibold tw-mt-6">Gemini CLI i inne programy MCP</h5>
<p>W <code>~/.gemini/settings.json</code>:</p>
<pre>{ "mcpServers": { "crm": { "httpUrl": "<?= $u ?>" } } }</pre>
<p>Potem w Gemini CLI: <code>/mcp auth crm</code>. Bez OAuth użyj ręcznego poświadczenia w nagłówku <code>Authorization: Bearer mc.…</code>.</p>

<h5 class="tw-font-semibold tw-mt-6">Poziomy dostępu</h5>
<ul>
<li><strong>Odczyt</strong>: przegląd i wyszukiwanie danych, bez zmian.</li>
<li><strong>Praca</strong>: dodatkowo zadania, komentarze, notatki, leady, szkice odpowiedzi w zgłoszeniach i artykuły bazy wiedzy.</li>
<li><strong>Pełny</strong>: dodatkowo klienci, kontakty, projekty i dokumenty sprzedaży.</li>
</ul>
<p>Asystent nigdy nie zrobi więcej, niż pozwala rola osoby w Perfexie. Nie usuwa danych i nic nie wysyła klientom: odpowiedź, fakturę czy ofertę przygotowuje jako szkic, a wysyła pracownik w CRM.</p>

<h5 class="tw-font-semibold tw-mt-6">Gdy logowanie nie działa</h5>
<p>Na zakładce Start uruchom sprawdzenie. Jeśli adresy z <code>/.well-known/</code> zwracają 404, serwer www przechwytuje je przed Perfexem (tak robi domyślna konfiguracja CloudPanel). Wklej pokazaną regułę do konfiguracji nginx tej strony albo użyj ręcznego poświadczenia.</p>
