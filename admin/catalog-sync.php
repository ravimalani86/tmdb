<?php
$pageTitle = 'Catalog Sync';
$headerSubtitle = 'Queue overview refreshes every 10 seconds. Process uses the saved config.';
require __DIR__ . '/partials/header.php';
?>
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="badge text-bg-secondary" id="connBadge">Connecting…</span>
          <a class="small" href="../api/sync-person-credits.php">Person credits runner</a>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-body">
                <h2 class="h6 text-uppercase text-muted fw-bold">Pending by media type</h2>
                <table class="table table-sm mb-1"><thead><tr><th>media_type</th><th class="text-end">cnt</th></tr></thead><tbody id="pendingBody"></tbody></table>
                <div class="text-muted small" id="pendingMeta"></div>
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-body">
                <h2 class="h6 text-uppercase text-muted fw-bold">Pending by source</h2>
                <table class="table table-sm mb-1"><thead><tr><th>source</th><th class="text-end">cnt</th></tr></thead><tbody id="sourceBody"></tbody></table>
                <div class="text-muted small" id="sourceMeta"></div>
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-body">
                <h2 class="h6 text-uppercase text-muted fw-bold">By status</h2>
                <table class="table table-sm mb-1"><thead><tr><th>status</th><th class="text-end">cnt</th></tr></thead><tbody id="statusBody"></tbody></table>
                <div class="text-muted small" id="statusMeta"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-body">
                <h2 class="h6 text-uppercase text-muted fw-bold">Process cron config</h2>
                <p class="text-muted small">Priority: admin config, then payload, then default. All means no filter.</p>
                <label for="processLimit" class="form-label">limit (1–450)</label>
                <input id="processLimit" type="number" min="1" max="450" value="50" class="form-control mb-3" />
                <div class="mb-2 small" id="mediaTypeRadios">
                  <div class="text-muted mb-1">media_type</div>
                  <label class="me-3"><input type="radio" name="mediaType" value="" checked /> All</label>
                  <label class="me-3"><input type="radio" name="mediaType" value="movie" /> movie</label>
                  <label class="me-3"><input type="radio" name="mediaType" value="tv" /> tv</label>
                  <label><input type="radio" name="mediaType" value="person" /> person</label>
                </div>
                <div class="mb-3 small">
                  <div class="text-muted mb-1">source</div>
                  <label class="me-3"><input type="radio" name="source" value="" checked /> All</label>
                  <label class="me-3"><input type="radio" name="source" value="changes" /> changes</label>
                  <label class="me-3"><input type="radio" name="source" value="discover" /> discover</label>
                  <label><input type="radio" name="source" value="credits" /> credits</label>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <button type="button" class="btn btn-primary btn-sm" id="btnSaveConfig">Save config</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLoadConfig">Reload</button>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="btnRunProcess">Run process once</button>
                </div>
                <span id="configMsg"></span>
                <div class="text-muted small mt-2" id="configMeta"></div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-body">
                <h2 class="h6 text-uppercase text-muted fw-bold">Sync by date range</h2>
                <p class="text-muted small">Enqueue TMDB changes or discover results. Rows already queued for that day are skipped. changes is capped at 14 days. person only supports changes.</p>
                <div class="row g-2 mb-3">
                  <div class="col-sm-6">
                    <label for="rangeStartDate" class="form-label">Start date</label>
                    <input id="rangeStartDate" type="date" class="form-control" />
                  </div>
                  <div class="col-sm-6">
                    <label for="rangeEndDate" class="form-label">End date</label>
                    <input id="rangeEndDate" type="date" class="form-control" />
                  </div>
                </div>
                <div class="mb-2 small">
                  <div class="text-muted mb-1">media_type</div>
                  <label class="me-3"><input type="radio" name="rangeMediaType" value="" checked /> All</label>
                  <label class="me-3"><input type="radio" name="rangeMediaType" value="movie" /> movie</label>
                  <label class="me-3"><input type="radio" name="rangeMediaType" value="tv" /> tv</label>
                  <label><input type="radio" name="rangeMediaType" value="person" /> person</label>
                </div>
                <div class="mb-3 small">
                  <div class="text-muted mb-1">source</div>
                  <label class="me-3"><input type="radio" name="rangeSource" value="" checked /> All</label>
                  <label class="me-3"><input type="radio" name="rangeSource" value="changes" /> changes</label>
                  <label><input type="radio" name="rangeSource" value="discover" /> discover</label>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="btnRangeSync">Sync</button>
                <span id="rangeMsg"></span>
                <div class="text-muted small mt-2" id="rangeMeta"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm border-0">
          <div class="card-body">
            <h2 class="h6 text-uppercase text-muted fw-bold">Lookup and sync</h2>
            <p class="text-muted small">Find one TMDB id, see if it is already in MySQL, then insert or full-update it.</p>
            <div class="small mb-2">
              <label class="me-3"><input type="radio" name="lookupMediaType" value="movie" checked /> movie</label>
              <label class="me-3"><input type="radio" name="lookupMediaType" value="tv" /> tv</label>
              <label><input type="radio" name="lookupMediaType" value="person" /> person</label>
            </div>
            <div class="input-group mb-2" style="max-width: 420px;">
              <input id="lookupTmdbId" type="number" min="1" class="form-control" placeholder="tmdb_id" />
              <button type="button" class="btn btn-outline-primary" id="btnLookupFind">Find</button>
            </div>
            <span id="lookupMsg"></span>
            <div class="border rounded p-3 mt-3 d-none" id="lookupPreview">
              <div class="d-flex gap-3">
                <img id="lookupImg" alt="" class="rounded d-none" style="width:72px;height:108px;object-fit:cover;" />
                <div>
                  <span class="badge text-bg-secondary" id="lookupSyncedPill">—</span>
                  <div class="fw-semibold mt-1" id="lookupName"></div>
                  <div class="small text-muted" id="lookupDetails"></div>
                </div>
              </div>
              <button type="button" class="btn btn-primary btn-sm mt-3" id="btnLookupSync">Sync update</button>
              <div class="text-muted small mt-2" id="lookupMeta"></div>
            </div>
          </div>
        </div>

<?php require __DIR__ . '/partials/footer.php'; ?>
  <script>
    (async function init() {
      const user = await mountSidebar('catalog-sync');
      if (!user) return;

      const $ = (id) => document.getElementById(id);

      function selected(name) {
        const el = document.querySelector('input[name="' + name + '"]:checked');
        return el ? el.value : '';
      }

      function fillTable(tbodyId, map, emptyLabel) {
        const tbody = $(tbodyId);
        const keys = Object.keys(map || {});
        if (!keys.length) {
          tbody.innerHTML = '<tr><td colspan="2" class="text-muted">' + emptyLabel + '</td></tr>';
          return;
        }
        keys.sort();
        tbody.innerHTML = keys.map((key) =>
          '<tr><td>' + escapeHtml(key) + '</td><td class="text-end">' + escapeHtml(map[key]) + '</td></tr>'
        ).join('');
      }

      async function refreshOverview() {
        try {
          const data = await api('/tmdb/sync/queue-overview', {});
          $('connBadge').className = 'badge text-bg-success';
          $('connBadge').textContent = 'Connected';
          fillTable('pendingBody', data.pending_by_type, 'No pending rows');
          fillTable('sourceBody', data.pending_by_source, 'No pending rows');
          fillTable('statusBody', data.by_status, 'No rows');
          const now = new Date().toLocaleTimeString();
          const sourceTotal = Object.values(data.pending_by_source || {}).reduce((sum, n) => sum + n, 0);
          $('pendingMeta').textContent = 'pending total: ' + (data.pending_total || 0) + ' · updated ' + now;
          $('sourceMeta').textContent = 'pending total: ' + sourceTotal + ' · updated ' + now;
          $('statusMeta').textContent = 'queue total: ' + (data.total || 0) + ' · updated ' + now;
        } catch (e) {
          $('connBadge').className = 'badge text-bg-danger';
          $('connBadge').textContent = 'Offline';
          const html = '<tr><td colspan="2" class="text-danger">' + escapeHtml(e.message) + '</td></tr>';
          $('pendingBody').innerHTML = html;
          $('sourceBody').innerHTML = html;
          $('statusBody').innerHTML = html;
        }
      }

      function applyConfigForm(cfg) {
        $('processLimit').value = cfg && cfg.process_limit != null ? cfg.process_limit : 50;
        const mt = (cfg && cfg.media_type) ? cfg.media_type : '';
        const mtRadio = document.querySelector('input[name="mediaType"][value="' + mt + '"]')
          || document.querySelector('input[name="mediaType"][value=""]');
        if (mtRadio) mtRadio.checked = true;
        const src = (cfg && cfg.source) ? cfg.source : '';
        const srcRadio = document.querySelector('input[name="source"][value="' + src + '"]')
          || document.querySelector('input[name="source"][value=""]');
        if (srcRadio) srcRadio.checked = true;
        $('configMeta').textContent = cfg && cfg.updated_at
          ? 'Last saved: ' + cfg.updated_at + ' (UTC)'
          : 'No saved config yet (defaults apply until save).';
      }

      async function loadConfig() {
        try {
          const data = await api('/tmdb/sync/config', {});
          applyConfigForm(data.config || {});
          setMsg($('configMsg'), '');
        } catch (e) {
          setMsg($('configMsg'), e.message, 'err');
        }
      }

      $('btnSaveConfig').onclick = async function () {
        const limit = parseInt($('processLimit').value, 10);
        if (!limit || limit < 1 || limit > 450) {
          setMsg($('configMsg'), 'limit must be 1–450', 'err');
          return;
        }
        setMsg($('configMsg'), 'Saving…');
        try {
          const data = await api('/tmdb/sync/config/save', {
            limit: limit,
            media_type: selected('mediaType') || '',
            source: selected('source') || '',
          });
          applyConfigForm(data.config || {});
          setMsg($('configMsg'), 'Config saved. Process cron will use these values.', 'ok');
        } catch (e) {
          setMsg($('configMsg'), e.message, 'err');
        }
      };

      $('btnLoadConfig').onclick = loadConfig;
      $('btnRunProcess').onclick = async function () {
        setMsg($('configMsg'), 'Running process…');
        try {
          const data = await api('/tmdb/sync/process', {});
          const p = data.process || {};
          setMsg($('configMsg'),
            'Process done: processed=' + (p.processed || 0) +
            ' done=' + (p.done || 0) +
            ' failed=' + (p.failed || 0) +
            ' limit=' + (p.limit ?? '?') +
            ' media_type=' + (p.media_type || 'all') +
            ' source=' + (p.source || 'all'),
            'ok');
          refreshOverview();
        } catch (e) {
          setMsg($('configMsg'), e.message, 'err');
        }
      };

      $('btnRangeSync').onclick = async function () {
        const startDate = $('rangeStartDate').value;
        if (!startDate) {
          setMsg($('rangeMsg'), 'Pick a start date', 'err');
          return;
        }
        let endDate = $('rangeEndDate').value;
        if (!endDate || endDate < startDate) {
          endDate = startDate;
          $('rangeEndDate').value = endDate;
        }
        setMsg($('rangeMsg'), 'Syncing ' + startDate + ' → ' + endDate + '…');
        try {
          const data = await api('/tmdb/sync/enqueue-range', {
            start_date: startDate,
            end_date: endDate,
            media_type: selected('rangeMediaType') || '',
            source: selected('rangeSource') || '',
          });
          const enq = data.enqueue || {};
          setMsg($('rangeMsg'),
            'Enqueued: movie=' + (enq.movie_enqueued || 0) +
            ' tv=' + (enq.tv_enqueued || 0) +
            ' person=' + (enq.person_enqueued || 0) +
            ' · skipped: movie=' + (enq.movie_skipped || 0) +
            ' tv=' + (enq.tv_skipped || 0) +
            ' person=' + (enq.person_skipped || 0),
            'ok');
          $('rangeMeta').textContent = 'sync_day (queue) = ' + (enq.sync_day || endDate) + ' · range ' + startDate + ' to ' + endDate;
          refreshOverview();
        } catch (e) {
          setMsg($('rangeMsg'), e.message, 'err');
        }
      };

      function hideLookupPreview() { $('lookupPreview').classList.add('d-none'); }

      function showLookupPreview(data) {
        const preview = data.preview || {};
        $('lookupName').textContent = preview.name || '(untitled)';
        const pill = $('lookupSyncedPill');
        pill.textContent = data.synced ? 'synced' : 'not synced';
        pill.className = 'badge ' + (data.synced ? 'text-bg-success' : 'text-bg-danger');
        const img = $('lookupImg');
        if (preview.image_url) {
          img.src = preview.image_url;
          img.alt = preview.name || '';
          img.classList.remove('d-none');
        } else {
          img.removeAttribute('src');
          img.classList.add('d-none');
        }
        const lines = [];
        if (data.media_type === 'person') {
          if (preview.known_for_department) lines.push(['department', preview.known_for_department]);
          if (preview.date) lines.push(['birthday', preview.date]);
          if (preview.popularity != null) lines.push(['popularity', preview.popularity]);
        } else {
          if (preview.date) lines.push([data.media_type === 'tv' ? 'first_air_date' : 'release_date', preview.date]);
          if (preview.original_language) lines.push(['original_language', preview.original_language]);
          if (preview.vote_average != null) lines.push(['vote_average', preview.vote_average]);
        }
        lines.push(['tmdb_id', data.tmdb_id]);
        const local = data.local || {};
        if (data.media_type === 'tv' && data.synced) {
          lines.push(['seasons in DB', (local.regular_seasons_in_db ?? 0) + ' / ' + (local.number_of_seasons ?? '—')]);
          lines.push(['episodes in DB', (local.episodes_in_db ?? 0) + ' / ' + (local.number_of_episodes ?? '—')]);
        }
        $('lookupDetails').innerHTML = lines.map(function (row) {
          return '<div><span>' + escapeHtml(row[0]) + ':</span> ' + escapeHtml(row[1]) + '</div>';
        }).join('');
        $('lookupPreview').classList.remove('d-none');
      }

      async function lookupFind() {
        const tmdbId = parseInt($('lookupTmdbId').value, 10);
        if (!tmdbId || tmdbId < 1) {
          setMsg($('lookupMsg'), 'Enter a positive tmdb_id', 'err');
          hideLookupPreview();
          return;
        }
        setMsg($('lookupMsg'), 'Looking up…');
        hideLookupPreview();
        try {
          const data = await api('/tmdb/sync/lookup', {
            media_type: selected('lookupMediaType') || 'movie',
            tmdb_id: tmdbId,
          });
          showLookupPreview(data);
          setMsg($('lookupMsg'), 'Found on TMDB.', 'ok');
          $('lookupMeta').textContent = '';
        } catch (e) {
          hideLookupPreview();
          setMsg($('lookupMsg'), e.message, 'err');
        }
      }

      $('btnLookupFind').onclick = lookupFind;
      $('lookupTmdbId').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') lookupFind();
      });
      $('btnLookupSync').onclick = async function () {
        const tmdbId = parseInt($('lookupTmdbId').value, 10);
        const mediaType = selected('lookupMediaType') || 'movie';
        if (!tmdbId || tmdbId < 1) {
          setMsg($('lookupMsg'), 'Enter a positive tmdb_id', 'err');
          return;
        }
        setMsg($('lookupMsg'), 'Syncing… (TV with many seasons can take a while)');
        try {
          const data = await api('/tmdb/sync/item', { media_type: mediaType, tmdb_id: tmdbId });
          setMsg($('lookupMsg'), 'Sync ' + (data.action || 'done') + ': ' + (data.media_type || mediaType) + ' ' + (data.tmdb_id || tmdbId), 'ok');
          try {
            showLookupPreview(await api('/tmdb/sync/lookup', { media_type: mediaType, tmdb_id: tmdbId }));
          } catch (_) {
            $('lookupSyncedPill').textContent = 'synced';
            $('lookupSyncedPill').className = 'badge text-bg-success';
          }
          $('lookupMeta').textContent = 'action=' + (data.action || '');
          refreshOverview();
        } catch (e) {
          setMsg($('lookupMsg'), e.message, 'err');
        }
      };

      await refreshOverview();
      await loadConfig();
      setInterval(refreshOverview, 10000);
    })();
  </script>
</body>
</html>
