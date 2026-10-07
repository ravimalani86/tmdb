<?php
$pageTitle = 'Daily Battles';
$headerSubtitle = 'One battle per date. Opens at midnight and closes the next midnight in India (IST).';
require __DIR__ . '/partials/header.php';
?>
        <div class="card shadow-sm border-0">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <h2 class="h6 text-uppercase text-muted fw-bold mb-0">Scheduled battles / results</h2>
              <button type="button" class="btn btn-primary btn-sm" id="btnNew">
                <i class="bi bi-plus-lg"></i> Schedule battle
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr class="text-muted small text-uppercase">
                    <th>Date (IST)</th>
                    <th>Movies</th>
                    <th>Status</th>
                    <th>Votes</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="rows">
                  <tr><td colspan="5" class="text-muted">Loading…</td></tr>
                </tbody>
              </table>
            </div>
            <span id="listMsg"></span>
          </div>
        </div>

  <div class="modal fade" id="battleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content app-modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2"><i class="bi bi-calendar-event text-primary"></i> <span id="editorTitle">Schedule battle</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label for="search" class="form-label">Find a movie in the catalog</label>
          <div class="input-group mb-2">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input id="search" class="form-control" placeholder="Movie title" />
            <button type="button" class="btn btn-outline-secondary" id="find">Search</button>
          </div>
          <div id="searchResults" class="d-flex flex-column gap-2 mb-3"></div>
          <form id="battleForm">
            <div class="row g-2">
              <div class="col-sm-6">
                <label for="movieA" class="form-label">Movie A — TMDB ID</label>
                <input id="movieA" type="number" min="1" class="form-control" required />
                <div id="titleA" class="text-muted small"></div>
              </div>
              <div class="col-sm-6">
                <label for="movieB" class="form-label">Movie B — TMDB ID</label>
                <input id="movieB" type="number" min="1" class="form-control" required />
                <div id="titleB" class="text-muted small"></div>
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-sm-6">
                <label for="date" class="form-label">Date (IST)</label>
                <input id="date" type="date" class="form-control" required />
              </div>
              <div class="col-sm-6">
                <label for="status" class="form-label">Status</label>
                <select id="status" class="form-select">
                  <option value="scheduled">Scheduled</option>
                  <option value="disabled">Disabled</option>
                </select>
              </div>
            </div>
            <label for="category" class="form-label mt-2">Category / question context</label>
            <input id="category" class="form-control" maxlength="100" placeholder="Sci-fi favourites" />
            <p class="text-muted small mt-3 mb-0">After the first vote, movie choices and date are locked. You can still update the category or disable today's battle.</p>
            <span id="message"></span>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="battleForm" class="btn btn-primary" id="btnSave"><i class="bi bi-save"></i> Save battle</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content app-modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2"><i class="bi bi-trash text-danger"></i> Delete battle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-1">Delete <strong id="deleteBattleLabel"></strong>?</p>
          <p class="text-muted small mb-0">Date <span id="deleteBattleDate"></span> and any votes for this battle will be removed. This cannot be undone.</p>
          <span id="deleteMsg"></span>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">NO</button>
          <button type="button" class="btn btn-danger" id="btnDeleteYes">YES</button>
        </div>
      </div>
    </div>
  </div>

<?php require __DIR__ . '/partials/footer.php'; ?>
  <script>
    (async function init() {
      const user = await mountSidebar('battles');
      if (!user) return;

      const el = (id) => document.getElementById(id);
      const battleModal = new bootstrap.Modal(el('battleModal'));
      const deleteModal = new bootstrap.Modal(el('deleteModal'));
      let editingId = 0;
      let pendingDelete = null;
      const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
      el('date').min = today;

      function reset() {
        editingId = 0;
        el('battleForm').reset();
        el('search').value = '';
        el('searchResults').replaceChildren();
        el('date').value = today;
        el('movieA').disabled = el('movieB').disabled = el('date').disabled = false;
        el('titleA').textContent = el('titleB').textContent = '';
        el('editorTitle').textContent = 'Schedule battle';
        setMsg(el('message'), '');
      }

      function openCreate() {
        reset();
        battleModal.show();
      }

      function openEdit(battle) {
        editingId = Number(battle.id);
        el('search').value = '';
        el('searchResults').replaceChildren();
        el('movieA').value = battle.movie_a_tmdb_id;
        el('movieB').value = battle.movie_b_tmdb_id;
        el('date').value = battle.battle_date;
        el('category').value = battle.category;
        el('status').value = battle.status;
        el('titleA').textContent = battle.movie_a_title || '';
        el('titleB').textContent = battle.movie_b_title || '';
        const locked = Number(battle.total_votes) > 0;
        el('movieA').disabled = el('movieB').disabled = el('date').disabled = locked;
        el('editorTitle').textContent = 'Edit battle #' + editingId;
        setMsg(el('message'), '');
        battleModal.show();
      }

      async function load() {
        const data = await api('/tmdb/battles/list', {});
        const rows = data.data || [];
        el('rows').replaceChildren();
        if (!rows.length) {
          const tr = document.createElement('tr');
          const td = document.createElement('td');
          td.colSpan = 5;
          td.className = 'text-muted';
          td.textContent = 'No battles yet. Click Schedule battle.';
          tr.append(td);
          el('rows').append(tr);
          return;
        }
        for (const battle of rows) {
          const tr = document.createElement('tr');
          for (const value of [
            battle.battle_date,
            (battle.movie_a_title || battle.movie_a_tmdb_id) + ' vs ' + (battle.movie_b_title || battle.movie_b_tmdb_id),
            battle.status,
            battle.total_votes,
          ]) {
            const td = document.createElement('td');
            td.textContent = value;
            tr.append(td);
          }
          const td = document.createElement('td');
          td.className = 'text-end text-nowrap';
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn btn-outline-secondary btn-sm';
          button.innerHTML = '<i class="bi bi-pencil"></i>';
          button.title = 'Edit';
          button.disabled = battle.battle_date < today;
          button.onclick = () => openEdit(battle);
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'btn btn-outline-danger btn-sm ms-1';
          remove.innerHTML = '<i class="bi bi-trash"></i>';
          remove.title = 'Delete';
          remove.onclick = () => {
            pendingDelete = { id: Number(battle.id) };
            el('deleteBattleLabel').textContent = (battle.movie_a_title || battle.movie_a_tmdb_id) + ' vs ' + (battle.movie_b_title || battle.movie_b_tmdb_id);
            el('deleteBattleDate').textContent = battle.battle_date;
            setMsg(el('deleteMsg'), '');
            deleteModal.show();
          };
          td.append(button, remove);
          tr.append(td);
          el('rows').append(tr);
        }
      }

      el('btnDeleteYes').onclick = async () => {
        if (!pendingDelete || !pendingDelete.id) return;
        const yes = el('btnDeleteYes');
        yes.disabled = true;
        setMsg(el('deleteMsg'), '');
        try {
          await api('/tmdb/battles/delete', { id: pendingDelete.id });
          pendingDelete = null;
          deleteModal.hide();
          showToast('Battle deleted.', 'ok');
          await load();
        } catch (e) {
          setMsg(el('deleteMsg'), e.message || String(e), 'err');
          showToast(e.message || String(e), 'err');
        } finally {
          yes.disabled = false;
        }
      };
      el('btnNew').onclick = openCreate;
      el('battleForm').onsubmit = async (event) => {
        event.preventDefault();
        const save = el('btnSave');
        save.disabled = true;
        setMsg(el('message'), '');
        try {
          await api('/tmdb/battles/save', {
            id: editingId,
            battle_date: el('date').value,
            movie_a_tmdb_id: Number(el('movieA').value),
            movie_b_tmdb_id: Number(el('movieB').value),
            category: el('category').value,
            status: el('status').value,
          });
          battleModal.hide();
          reset();
          await load();
          showToast('Battle saved.', 'ok');
        } catch (e) {
          setMsg(el('message'), e.message || String(e), 'err');
          showToast(e.message || String(e), 'err');
        } finally {
          save.disabled = false;
        }
      };
      el('find').onclick = async () => {
        const find = el('find');
        find.disabled = true;
        setMsg(el('message'), '');
        try {
          const query = el('search').value.trim();
          if (!query) throw new Error('Enter a movie title');
          const data = await api('/tmdb/battles/movies', { search: query });
          el('searchResults').replaceChildren();
          const movies = data.data || [];
          for (const movie of movies) {
            const row = document.createElement('div');
            row.className = 'border rounded p-2';
            const label = document.createElement('div');
            label.className = 'small mb-1';
            label.textContent = movie.title + ' (' + movie.tmdb_id + ')';
            row.append(label);
            const actions = document.createElement('div');
            actions.className = 'd-flex gap-2';
            for (const side of ['A', 'B']) {
              const button = document.createElement('button');
              button.type = 'button';
              button.className = 'btn btn-outline-secondary btn-sm';
              button.textContent = 'Use as Movie ' + side;
              button.onclick = () => {
                if (el('movie' + side).disabled) return;
                el('movie' + side).value = movie.tmdb_id;
                el('title' + side).textContent = movie.title;
              };
              actions.append(button);
            }
            row.append(actions);
            el('searchResults').append(row);
          }
          if (!movies.length) el('searchResults').textContent = 'No catalog movies found.';
        } catch (e) {
          setMsg(el('message'), e.message || String(e), 'err');
        } finally {
          find.disabled = false;
        }
      };

      try { await load(); }
      catch (e) { setMsg(el('listMsg'), e.message || String(e), 'err'); }
    })();
  </script>
</body>
</html>
