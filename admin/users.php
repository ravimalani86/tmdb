<?php
$pageTitle = 'Users';
$headerSubtitle = 'Sub-admins can create and manage their own apps only.';
require __DIR__ . '/partials/header.php';
?>
        <div id="forbidden" class="alert alert-danger d-none">Forbidden — only the super admin can manage users.</div>

        <div id="usersArea" class="d-none">
          <div class="card shadow-sm border-0">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 text-uppercase text-muted fw-bold mb-0">All Users</h2>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal">
                  <i class="bi bi-person-plus"></i> Create user
                </button>
              </div>
              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr class="text-muted small text-uppercase">
                      <th>Username</th>
                      <th>Role</th>
                      <th>Created</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="usersBody">
                    <tr><td colspan="4" class="text-muted">Loading…</td></tr>
                  </tbody>
                </table>
              </div>
              <span id="listMsg"></span>
            </div>
          </div>
        </div>

  <!-- Create user modal -->
  <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create user</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">New users get normal admin access — they only see and manage apps they create.</p>
          <div class="mb-3">
            <label for="newUsername" class="form-label">Username</label>
            <input id="newUsername" type="text" class="form-control" maxlength="64" />
          </div>
          <div class="mb-2">
            <label for="newPassword" class="form-label">Password</label>
            <input id="newPassword" type="password" class="form-control" autocomplete="new-password" />
          </div>
          <span id="formMsg"></span>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="btnSaveUser">Create</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Reset password modal -->
  <div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reset password for <code id="resetUsername"></code></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label for="resetPassword" class="form-label">New password</label>
          <input id="resetPassword" type="password" class="form-control" autocomplete="new-password" />
          <span id="resetMsg"></span>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="btnDoReset">Reset password</button>
        </div>
      </div>
    </div>
  </div>

<?php require __DIR__ . '/partials/footer.php'; ?>
  <script>
    const usersBody = document.getElementById('usersBody');
    const listMsg = document.getElementById('listMsg');
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    const resetModalEl = document.getElementById('resetModal');
    const resetModal = new bootstrap.Modal(resetModalEl);
    const formMsg = document.getElementById('formMsg');
    const resetMsg = document.getElementById('resetMsg');
    let resetTargetId = null;
    let rows = [];

    async function loadUsers() {
      setMsg(listMsg, 'Loading…');
      try {
        const data = await api('/users/list', {});
        rows = data.data || [];
        usersBody.innerHTML = rows.map(function (u) {
          const roleLabel = u.role === 'super_admin' ? 'Super Admin' : 'Admin';
          const canManage = u.role !== 'super_admin';
          const actions = canManage
            ? '<button type="button" class="btn btn-sm btn-outline-secondary btn-reset" data-id="' + u.id + '" data-username="' + escapeHtml(u.username) + '"><i class="bi bi-key"></i> Reset password</button> ' +
              '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="' + u.id + '" data-username="' + escapeHtml(u.username) + '"><i class="bi bi-trash"></i> Delete</button>'
            : '<span class="text-muted">—</span>';
          return '<tr>' +
            '<td>' + escapeHtml(u.username) + '</td>' +
            '<td><span class="badge rounded-pill ' + (u.role === 'super_admin' ? 'text-bg-primary' : 'text-bg-secondary') + '">' + roleLabel + '</span></td>' +
            '<td class="text-muted small">' + escapeHtml(u.created_at) + '</td>' +
            '<td class="text-nowrap text-end">' + actions + '</td>' +
            '</tr>';
        }).join('');

        usersBody.querySelectorAll('.btn-reset').forEach((btn) => {
          btn.onclick = () => {
            resetTargetId = parseInt(btn.getAttribute('data-id'), 10);
            document.getElementById('resetUsername').textContent = btn.getAttribute('data-username');
            document.getElementById('resetPassword').value = '';
            setMsg(resetMsg, '');
            resetModal.show();
          };
        });
        usersBody.querySelectorAll('.btn-delete').forEach((btn) => {
          btn.onclick = async () => {
            const username = btn.getAttribute('data-username');
            if (!confirm('Delete user "' + username + '"? Their apps are not deleted, but nobody but the super admin will be able to manage them.')) return;
            try {
              await api('/users/delete', { id: parseInt(btn.getAttribute('data-id'), 10) });
              showToast('User deleted');
              await loadUsers();
            } catch (e) {
              showToast(e.message || String(e), 'err');
            }
          };
        });

        setMsg(listMsg, rows.length + ' user' + (rows.length === 1 ? '' : 's') + '.', 'ok');
      } catch (e) {
        setMsg(listMsg, e.message || String(e), 'err');
      }
    }

    document.getElementById('userModal').addEventListener('show.bs.modal', () => {
      document.getElementById('newUsername').value = '';
      document.getElementById('newPassword').value = '';
      setMsg(formMsg, '');
    });

    document.getElementById('btnSaveUser').onclick = async () => {
      const username = document.getElementById('newUsername').value.trim();
      const password = document.getElementById('newPassword').value;
      setMsg(formMsg, 'Creating…');
      try {
        await api('/users/create', { username, password });
        userModal.hide();
        showToast('User created');
        setMsg(formMsg, '');
        await loadUsers();
      } catch (e) {
        setMsg(formMsg, e.message || String(e), 'err');
        showToast(e.message || String(e), 'err');
      }
    };

    document.getElementById('btnDoReset').onclick = async () => {
      const newPassword = document.getElementById('resetPassword').value;
      setMsg(resetMsg, 'Saving…');
      try {
        await api('/users/reset-password', { id: resetTargetId, new_password: newPassword });
        resetModal.hide();
        showToast('Password reset');
        setMsg(resetMsg, '');
      } catch (e) {
        setMsg(resetMsg, e.message || String(e), 'err');
        showToast(e.message || String(e), 'err');
      }
    };

    (async function init() {
      const user = await mountSidebar('users');
      if (!user) return;
      if (user.role !== 'super_admin') {
        document.getElementById('forbidden').classList.remove('d-none');
        return;
      }
      document.getElementById('usersArea').classList.remove('d-none');
      await loadUsers();
    })();
  </script>
</body>
</html>
