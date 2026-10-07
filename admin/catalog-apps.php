<?php
$pageTitle = 'Catalog apps';
$headerSubtitle = 'Apps stored in TMDB app_configs. Flutter reads them with POST /app-config.';

$apiPublic = 'http://localhost/tmdb';
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || str_starts_with($envLine, '#') || !str_contains($envLine, '=')) {
            continue;
        }
        [$envKey, $envValue] = explode('=', $envLine, 2);
        if (trim($envKey) === 'PUBLIC_BASE_URL') {
            $apiPublic = rtrim(trim($envValue, " \t\"'"), '/');
            break;
        }
    }
}
$appConfigUrl = $apiPublic . '/api/app-config';
$appConfigCurl = 'curl -sS -X POST ' . json_encode($appConfigUrl, JSON_UNESCAPED_SLASHES) . " \\\n"
    . "  -H \"Content-Type: application/json\" \\\n"
    . "  -H \"X-API-Key: YOUR_API_KEY\" \\\n"
    . "  -d '{\"app_id\":\"YOUR_APP_ID\"}'";

require __DIR__ . '/partials/header.php';
?>
        <div class="card shadow-sm border-0">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
              <h2 class="h6 text-uppercase text-muted fw-bold mb-0">Catalog apps</h2>
              <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#appModal" id="btnNew">
                <i class="bi bi-plus-lg"></i> Create app
              </button>
            </div>
            <p class="text-muted small mb-3 d-flex align-items-center gap-1">
              This list is the TMDB API store. Flutter reads it with POST /app-config.
              <button type="button" class="btn btn-link api-info-btn" id="btnApiInfo" title="How to call this API" aria-label="How to call this API">
                <i class="bi bi-info-circle-fill"></i>
              </button>
            </p>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr class="text-muted small text-uppercase">
                    <th>Name</th>
                    <th>App ID</th>
                    <th>Package</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="appsBody">
                  <tr><td colspan="5" class="text-muted">Loading…</td></tr>
                </tbody>
              </table>
            </div>
            <span id="listMsg"></span>
          </div>
        </div>

  <div class="modal fade" id="appModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content app-modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2"><i class="bi bi-braces text-primary"></i> <span id="formTitle">Create app</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="appName" class="form-label">App name</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-badge-ad"></i></span>
                <input id="appName" type="text" class="form-control" maxlength="120" placeholder="e.g. Movflik" />
              </div>
            </div>
            <div class="col-md-6">
              <label for="appId" class="form-label">App ID</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                <input id="appId" type="text" class="form-control" maxlength="64" placeholder="e.g. movflik" />
              </div>
              <div class="form-text">Auto-filled from the app name. Cannot change after create.</div>
            </div>
          </div>
          <div class="mb-3">
            <label for="packageName" class="form-label">Package name</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-box-seam"></i></span>
              <input id="packageName" type="text" class="form-control" maxlength="191" placeholder="e.g. com.company.movflik" />
            </div>
          </div>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <label for="editor" class="form-label mb-0">JSON</label>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnFormat">Format JSON</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnValidate">Validate</button>
            </div>
          </div>
          <p class="text-muted small">Returned as-is by <code>POST /app-config</code>. Root must be an object or array.</p>
          <textarea id="editor" class="form-control font-monospace" rows="14" spellcheck="false"></textarea>
          <span id="editMsg"></span>
          <div class="text-muted small mt-2" id="editMeta"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="btnSaveApp"><i class="bi bi-save"></i> Save</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content app-modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2"><i class="bi bi-trash text-danger"></i> Delete catalog app</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-1">Delete <strong id="deleteAppName"></strong>?</p>
          <p class="text-muted small mb-0">App ID <code id="deleteAppId"></code> and its JSON will be removed. This cannot be undone.</p>
          <span id="deleteMsg"></span>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" id="btnDeleteNo" data-bs-dismiss="modal">NO</button>
          <button type="button" class="btn btn-danger" id="btnDeleteYes">YES</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="apiInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content app-modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2"><i class="bi bi-info-circle-fill text-primary"></i> How to call app config</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Flutter and other clients read one app’s saved JSON with <code>POST /app-config</code>.</p>
          <ul class="small mb-3">
            <li>URL: <code><?= htmlspecialchars($appConfigUrl) ?></code></li>
            <li>Header <code>Content-Type: application/json</code></li>
            <li>Header <code>X-API-Key</code> with the API key from <code>api/.env</code></li>
            <li>Body <code>app_id</code> is the App ID from this table, for example <code>block_blast</code></li>
            <li>The response is that app’s JSON only. There is no wrapper.</li>
            <li>An unknown or deleted app returns <code>404</code>.</li>
          </ul>
          <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <span class="form-label mb-0">curl</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCopyCurl"><i class="bi bi-clipboard"></i> Copy</button>
          </div>
          <pre class="api-curl mb-0" id="apiCurl"><?= htmlspecialchars($appConfigCurl) ?></pre>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

<?php require __DIR__ . '/partials/footer.php'; ?>
  <script>
    (async function init() {
      const user = await mountSidebar('catalog-apps');
      if (!user) return;

      const appsBody = document.getElementById('appsBody');
      const appModalEl = document.getElementById('appModal');
      const appModal = new bootstrap.Modal(appModalEl);
      const formTitle = document.getElementById('formTitle');
      const appNameEl = document.getElementById('appName');
      const appIdEl = document.getElementById('appId');
      const packageNameEl = document.getElementById('packageName');
      const editor = document.getElementById('editor');
      const editMsg = document.getElementById('editMsg');
      const editMeta = document.getElementById('editMeta');
      const listMsg = document.getElementById('listMsg');
      const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
      const apiInfoModal = new bootstrap.Modal(document.getElementById('apiInfoModal'));
      const deleteMsg = document.getElementById('deleteMsg');
      const btnDeleteYes = document.getElementById('btnDeleteYes');
      let editing = false;
      let pendingDelete = null;
      let appIdTouched = false;
      let rows = [];

      function slugifyAppId(name) {
        return name.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9._-]/g, '');
      }

      appNameEl.addEventListener('input', () => {
        if (editing || appIdTouched) return;
        appIdEl.value = slugifyAppId(appNameEl.value);
      });
      appIdEl.addEventListener('input', () => { appIdTouched = true; });

      function parseEditorJson() {
        const raw = editor.value.trim() === '' ? '{}' : editor.value;
        const obj = JSON.parse(raw);
        if (obj === null || typeof obj !== 'object') {
          throw new Error('Root must be a JSON object or array');
        }
        return { obj: obj };
      }

      function openModal(mode, app) {
        editing = mode === 'edit';
        appIdTouched = editing;
        formTitle.textContent = editing ? 'Edit app' : 'Create app';
        appNameEl.value = app ? (app.app_name || '') : '';
        appIdEl.value = app ? (app.app_id || '') : '';
        appIdEl.disabled = editing;
        packageNameEl.value = app ? (app.package_name || '') : '';
        if (app && app.raw) {
          try { editor.value = JSON.stringify(JSON.parse(app.raw), null, 2); }
          catch (_) { editor.value = app.raw; }
        } else {
          editor.value = '{\n  \n}';
        }
        editMeta.textContent = app && app.updated_at ? 'updated_at=' + app.updated_at : '';
        setMsg(editMsg, '');
        appModal.show();
      }

      async function loadApps() {
        setMsg(listMsg, 'Loading…');
        const data = await api('/tmdb/apps/list', {});
        rows = data.data || [];
        if (rows.length === 0) {
          appsBody.innerHTML = '<tr><td colspan="5" class="text-muted">No catalog apps yet. Click Create app.</td></tr>';
        } else {
          appsBody.innerHTML = rows.map(function (row) {
            return '<tr>' +
              '<td>' + escapeHtml(row.app_name) + '</td>' +
              '<td><code>' + escapeHtml(row.app_id) + '</code></td>' +
              '<td><code>' + escapeHtml(row.package_name) + '</code></td>' +
              '<td class="text-muted small">' + escapeHtml(row.updated_at || '') + '</td>' +
              '<td class="text-nowrap text-end">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary btn-edit" data-id="' + escapeHtml(row.app_id) + '"><i class="bi bi-pencil"></i></button> ' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="' + escapeHtml(row.app_id) + '" data-name="' + escapeHtml(row.app_name) + '"><i class="bi bi-trash"></i></button>' +
              '</td></tr>';
          }).join('');
          appsBody.querySelectorAll('.btn-edit').forEach(function (btn) {
            btn.onclick = function () {
              const found = rows.find(function (r) { return r.app_id === btn.getAttribute('data-id'); });
              if (found) openModal('edit', found);
            };
          });
          appsBody.querySelectorAll('.btn-delete').forEach(function (btn) {
            btn.onclick = function () {
              pendingDelete = {
                app_id: btn.getAttribute('data-id') || '',
                app_name: btn.getAttribute('data-name') || '',
              };
              document.getElementById('deleteAppName').textContent = pendingDelete.app_name;
              document.getElementById('deleteAppId').textContent = pendingDelete.app_id;
              setMsg(deleteMsg, '');
              deleteModal.show();
            };
          });
        }
        setMsg(listMsg, rows.length + ' app' + (rows.length === 1 ? '' : 's') + '.', 'ok');
      }

      document.getElementById('btnNew').addEventListener('click', () => openModal('create', null));
      document.getElementById('btnApiInfo').onclick = () => apiInfoModal.show();
      document.getElementById('btnCopyCurl').onclick = async () => {
        const curl = document.getElementById('apiCurl').textContent || '';
        try {
          await navigator.clipboard.writeText(curl);
          showToast('Curl copied.', 'ok');
        } catch (e) {
          showToast('Could not copy the curl.', 'err');
        }
      };

      btnDeleteYes.onclick = async function () {
        if (!pendingDelete || !pendingDelete.app_id) return;
        btnDeleteYes.disabled = true;
        setMsg(deleteMsg, 'Deleting…');
        try {
          await api('/tmdb/apps/delete', { app_id: pendingDelete.app_id });
          pendingDelete = null;
          deleteModal.hide();
          showToast('Catalog app deleted');
          setMsg(deleteMsg, '');
          await loadApps();
        } catch (e) {
          setMsg(deleteMsg, e.message || String(e), 'err');
          showToast(e.message || String(e), 'err');
        } finally {
          btnDeleteYes.disabled = false;
        }
      };

      document.getElementById('btnFormat').onclick = function () {
        try {
          editor.value = JSON.stringify(parseEditorJson().obj, null, 2);
          setMsg(editMsg, 'Formatted.', 'ok');
        } catch (e) {
          setMsg(editMsg, 'Invalid JSON: ' + e.message, 'err');
        }
      };
      document.getElementById('btnValidate').onclick = function () {
        try {
          parseEditorJson();
          setMsg(editMsg, 'Valid JSON.', 'ok');
        } catch (e) {
          setMsg(editMsg, 'Invalid JSON: ' + e.message, 'err');
        }
      };
      document.getElementById('btnSaveApp').onclick = async function () {
        let parsed;
        try { parsed = parseEditorJson(); }
        catch (e) {
          setMsg(editMsg, 'Fix JSON before save: ' + e.message, 'err');
          showToast('Fix JSON before save: ' + e.message, 'err');
          return;
        }
        const payload = {
          app_name: appNameEl.value.trim(),
          app_id: appIdEl.value.trim(),
          package_name: packageNameEl.value.trim(),
          raw: JSON.stringify(parsed.obj),
        };
        setMsg(editMsg, 'Saving…');
        try {
          const data = await api(editing ? '/tmdb/apps/update' : '/tmdb/apps/create', payload);
          appModal.hide();
          showToast(data.action === 'created' ? 'Catalog app created' : 'Catalog app updated');
          setMsg(editMsg, '');
          await loadApps();
        } catch (e) {
          setMsg(editMsg, e.message || String(e), 'err');
          showToast(e.message || String(e), 'err');
        }
      };

      try { await loadApps(); }
      catch (e) { setMsg(listMsg, e.message || String(e), 'err'); }
    })();
  </script>
</body>
</html>
