<?php
$pageTitle = 'Dashboard';
$headerSubtitle = 'Overview of catalog apps and users in this panel.';
require __DIR__ . '/partials/header.php';
?>
        <div class="row g-3" id="kpiRow">
          <div class="col-sm-6 col-xl-3">
            <a href="catalog-apps.php" class="kpi-card kpi-card-apps text-decoration-none">
              <div class="kpi-icon"><i class="bi bi-phone-fill"></i></div>
              <div class="kpi-body">
                <div class="kpi-label">Total Apps</div>
                <div class="kpi-value" id="kpiApps">—</div>
                <div class="kpi-hint">Catalog apps</div>
              </div>
            </a>
          </div>
          <div class="col-sm-6 col-xl-3" id="kpiUsersCol">
            <a href="users.php" class="kpi-card kpi-card-users text-decoration-none" id="kpiUsersLink">
              <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
              <div class="kpi-body">
                <div class="kpi-label">Total Users</div>
                <div class="kpi-value" id="kpiUsers">—</div>
                <div class="kpi-hint">Admin accounts</div>
              </div>
            </a>
          </div>
        </div>
        <span id="kpiMsg"></span>

<?php require __DIR__ . '/partials/footer.php'; ?>
  <script>
    (async function init() {
      const user = await mountSidebar('dashboard');
      if (!user) return;

      const isSuperAdmin = user.role === 'super_admin';
      const usersLink = document.getElementById('kpiUsersLink');
      if (!isSuperAdmin) {
        usersLink.removeAttribute('href');
        usersLink.classList.add('kpi-card-static');
        usersLink.querySelector('.kpi-hint').textContent = 'Accounts on this panel';
      }

      try {
        const stats = await api('/dashboard/stats', {});
        document.getElementById('kpiApps').textContent = String(stats.apps_count ?? 0);
        document.getElementById('kpiUsers').textContent = String(stats.users_count ?? 0);
      } catch (e) {
        setMsg(document.getElementById('kpiMsg'), e.message || String(e), 'err');
      }
    })();
  </script>
</body>
</html>
