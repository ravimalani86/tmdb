<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign in — Management</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/style.css?v=8" />
</head>
<body class="bg-body-tertiary">
  <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
    <div class="card shadow-sm border-0" style="max-width: 400px; width: 100%;">
      <div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-grid-1x2-fill fs-3 text-primary"></i>
          <h1 class="h4 fw-bold mb-0">Management</h1>
        </div>
        <p class="text-muted small mb-4">Sign in to manage apps and JSON settings.</p>

        <div class="mb-3">
          <label for="username" class="form-label">Username</label>
          <input id="username" type="text" class="form-control" autocomplete="username" />
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">Password</label>
          <input id="password" type="password" class="form-control" autocomplete="current-password" />
        </div>
        <button type="button" id="btnLogin" class="btn btn-primary w-100">
          <i class="bi bi-box-arrow-in-right"></i> Sign in
        </button>
        <span id="loginMsg"></span>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/app.js?v=5"></script>
  <script>
    const usernameEl = document.getElementById('username');
    const passwordEl = document.getElementById('password');
    const loginMsg = document.getElementById('loginMsg');

    async function doLogin() {
      setMsg(loginMsg, 'Signing in…');
      try {
        await api('/auth/login', {
          username: usernameEl.value.trim(),
          password: passwordEl.value,
        });
        location.href = 'dashboard.php';
      } catch (e) {
        setMsg(loginMsg, e.message || String(e), 'err');
      }
    }

    document.getElementById('btnLogin').onclick = doLogin;
    passwordEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });

    // Already logged in? skip straight to dashboard.
    api('/auth/me', {}).then((data) => {
      if (data.user) location.href = 'dashboard.php';
    }).catch(() => {});
  </script>
</body>
</html>
