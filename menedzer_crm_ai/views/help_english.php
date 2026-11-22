<?php defined('BASEPATH') or exit('No direct script access allowed'); $u = mcai_e($mcpUrl); ?>
<h4>Help</h4>
<p>MCP server address of this CRM: <code><?= $u ?></code></p>

<h5 class="tw-font-semibold tw-mt-6">Claude on the web and Claude Desktop</h5>
<ol>
<li>In Claude open Settings, Connectors, Add custom connector.</li>
<li>Paste the MCP server address and save.</li>
<li>Click Connect. Sign in to the CRM, choose the access level and end date, approve.</li>
</ol>

<h5 class="tw-font-semibold tw-mt-6">Claude Code</h5>
<pre>claude mcp add --transport http crm <?= $u ?></pre>
<p>Then type <code>/mcp</code> in Claude Code, pick <code>crm</code> and sign in in the browser.</p>

<h5 class="tw-font-semibold tw-mt-6">Codex (CLI and app)</h5>
<pre>codex mcp add crm --url <?= $u ?>

codex mcp login crm</pre>
<p>If browser sign-in does not work, create a manual credential on the Connections tab and add to <code>~/.codex/config.toml</code>:</p>
<pre>[mcp_servers.crm]
url = "<?= $u ?>"
bearer_token_env_var = "CRM_MCP_TOKEN"</pre>
<p>Put the token in the <code>CRM_MCP_TOKEN</code> environment variable, not in the file.</p>

<h5 class="tw-font-semibold tw-mt-6">ChatGPT</h5>
<ol>
<li>Enable developer mode in ChatGPT: Settings, Apps &amp; Connectors, Advanced.</li>
<li>Create a connector, paste the MCP server address, authentication OAuth.</li>
<li>Sign in to the CRM and approve access.</li>
</ol>

<h5 class="tw-font-semibold tw-mt-6">Gemini CLI and other MCP clients</h5>
<p>In <code>~/.gemini/settings.json</code>:</p>
<pre>{ "mcpServers": { "crm": { "httpUrl": "<?= $u ?>" } } }</pre>
<p>Then in Gemini CLI: <code>/mcp auth crm</code>. Without OAuth use a manual credential in the <code>Authorization: Bearer mc.…</code> header.</p>

<h5 class="tw-font-semibold tw-mt-6">Access levels</h5>
<ul>
<li><strong>Read</strong>: browse and search data, no changes.</li>
<li><strong>Work</strong>: also tasks, comments, notes, leads, ticket reply drafts and knowledge base articles.</li>
<li><strong>Full</strong>: also customers, contacts, projects and sales documents.</li>
</ul>
<p>The assistant never does more than the person's Perfex role allows. It does not delete data and never sends anything to customers: replies, invoices and proposals are prepared as drafts and sent by a staff member in the CRM.</p>

<h5 class="tw-font-semibold tw-mt-6">If sign-in does not work</h5>
<p>Run the check on the Start tab. If <code>/.well-known/</code> addresses return 404, the web server intercepts them before Perfex (the default CloudPanel configuration does). Add the rule shown to the site's nginx configuration or use a manual credential.</p>
