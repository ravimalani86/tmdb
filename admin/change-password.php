<?php
$pageTitle = 'Change Password';
$headerSubtitle = 'Update your own login password.';
require __DIR__ . '/partials/header.php';
?>
        <div class="card shadow-sm border-0" style="max-width:420px;">
          <div class="card-body">
            <div class="mb-3">
              <label for="currentPassword" class="form-label">Current password</label>
              <input id="currentPassword" type="password" class="form-control" autocomplete="current-password" />
            </div>
            <div class="mb-3">
              <label for="newPassword" class="form-label">New password</label>
              <input id="newPassword" type="password" class="form-control" autocomplete="new-password" />
            </div>
            <div class="mb-3">
              <label for="confirmPassword" class="form-label">Confirm new password</label>
              <input id="confirmPassword" type="password" class="form-control" autocomplete="new-password" />
            </div>
            <button type="button" class="btn btn-primary" id="btnChange"><i class="bi bi-check-lg"></i> Update password</button>
            <span id="changeMsg"></span>
          </div>
        </div>

<?php require __DIR__ . '/partials/footer.php'; ?>
  <script>
    document.getElementById('btnChange').onclick = async () => {
      const current = document.getElementById('currentPassword').value;
      const next = document.getElementById('newPassword').value;
      const confirmVal = document.getElementById('confirmPassword').value;
      const msg = document.getElementById('changeMsg');

      if (next !== confirmVal) {
        setMsg(msg, 'New password and confirmation do not match.', 'err');
        showToast('New password and confirmation do not match.', 'err');
        return;
      }
      setMsg(msg, 'Updating…');
      try {
        await api('/auth/change-password', { current_password: current, new_password: next });
        setMsg(msg, '');
        showToast('Password updated');
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
      } catch (e) {
        setMsg(msg, e.message || String(e), 'err');
        showToast(e.message || String(e), 'err');
      }
    };

    (async function init() {
      const user = await mountSidebar('password');
      if (!user) return;
    })();
  </script>
</body>
</html>
