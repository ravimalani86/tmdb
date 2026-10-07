function apiBase() {
  const path = location.pathname.replace(/\/[^/]*$/, '');
  return location.origin + path;
}

async function api(path, body) {
  const res = await fetch(apiBase() + path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  if (res.status === 401 && !path.startsWith('/auth/')) {
    location.href = 'login.php';
    throw new Error('Unauthorized');
  }
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch (_) {
    const snippet = (text || '').replace(/\s+/g, ' ').slice(0, 280);
    throw new Error('Non-JSON response HTTP ' + res.status + (snippet ? (': ' + snippet) : ' (empty body)'));
  }
  if (!res.ok) {
    throw new Error((data && data.error) || ('HTTP ' + res.status));
  }
  return data;
}

/** Sets a small Bootstrap-colored status line: kind is 'ok' | 'err' | 'warn' | '' */
function setMsg(el, text, kind) {
  el.textContent = text || '';
  const map = { ok: 'text-success', err: 'text-danger', warn: 'text-warning' };
  el.className = 'small mt-2 d-block ' + (map[kind] || 'text-muted');
}

/**
 * Bootstrap 5 toast (no extra library). kind: 'ok' | 'err' | 'warn'
 * Used for Add / Edit / Delete / Save feedback.
 */
function showToast(message, kind) {
  kind = kind === 'err' || kind === 'warn' ? kind : 'ok';
  const icons = {
    ok: 'bi-check-circle-fill',
    err: 'bi-exclamation-circle-fill',
    warn: 'bi-exclamation-triangle-fill',
  };
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    document.body.appendChild(container);
  }
  const el = document.createElement('div');
  el.className = 'toast app-toast app-toast-' + kind;
  el.setAttribute('role', 'alert');
  el.setAttribute('aria-live', 'polite');
  el.innerHTML =
    '<div class="toast-body d-flex align-items-start gap-2">' +
      '<i class="bi ' + icons[kind] + '"></i>' +
      '<div class="flex-grow-1">' + escapeHtml(message) + '</div>' +
      '<button type="button" class="btn-close btn-close-toast" data-bs-dismiss="toast" aria-label="Close"></button>' +
    '</div>';
  container.appendChild(el);
  const toast = bootstrap.Toast.getOrCreateInstance(el, {
    delay: kind === 'err' ? 5000 : 3500,
    autohide: true,
  });
  el.addEventListener('hidden.bs.toast', () => el.remove());
  toast.show();
}

function escapeHtml(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** Reads a <input type=file> image and resolves to a base64 data URL. */
function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new Error('Could not read file'));
    reader.readAsDataURL(file);
  });
}

/** Redirects to login.php if there is no active session. Call at top of protected pages. */
async function requireLoginOrRedirect() {
  try {
    const data = await api('/auth/me', {});
    if (!data.user) {
      location.href = 'login.php';
      return null;
    }
    return data.user;
  } catch (e) {
    location.href = 'login.php';
    return null;
  }
}

/**
 * Standard admin panel shell: fills the desktop sidebar (#navDesktop / #footDesktop)
 * and the mobile offcanvas (#navMobile / #footMobile) with nav links (hiding
 * super-admin-only items for regular admins) and wires sign out.
 * Call once per protected page, right after the DOM exists.
 * Returns the current user, or null if redirected to login.
 */
async function mountSidebar(activeKey) {
  const user = await requireLoginOrRedirect();
  if (!user) return null;

  const isSuper = user.role === 'super_admin';
  const items = [
    { key: 'dashboard', label: 'Dashboard', href: 'dashboard.php', icon: 'bi-speedometer2', accent: 'nav-accent-blue' },
    { key: 'catalog-apps', label: 'Catalog apps', href: 'catalog-apps.php', icon: 'bi-braces', accent: 'nav-accent-teal' },
    { key: 'catalog-sync', label: 'Catalog Sync', href: 'catalog-sync.php', icon: 'bi-arrow-repeat', accent: 'nav-accent-blue' },
    { key: 'battles', label: 'Daily Battles', href: 'battles.php', icon: 'bi-trophy-fill', accent: 'nav-accent-amber' },
    { key: 'users', label: 'Users', href: 'users.php', icon: 'bi-people-fill', accent: 'nav-accent-violet', superOnly: true },
    { key: 'password', label: 'Change Password', href: 'change-password.php', icon: 'bi-key-fill', accent: 'nav-accent-amber' },
  ];
  const navHtml = items
    .filter((i) => !i.superOnly || isSuper)
    .map((i) => (
      '<li class="nav-item">' +
        '<a href="' + i.href + '" class="nav-link d-flex align-items-center gap-2' + (i.key === activeKey ? ' active' : '') + '">' +
          '<span class="nav-icon ' + i.accent + '"><i class="bi ' + i.icon + '"></i></span>' +
          '<span class="nav-label">' + i.label + '</span>' +
        '</a>' +
      '</li>'
    ))
    .join('');

  const initial = escapeHtml(user.username.charAt(0).toUpperCase());
  const roleTagHtml =
    '<span class="user-role-tag ' + (isSuper ? 'role-super' : 'role-admin') + '">' +
      '<i class="bi ' + (isSuper ? 'bi-patch-check-fill' : 'bi-person-fill') + '"></i> ' +
      (isSuper ? 'Super Admin' : 'Admin') +
    '</span>';

  const menuHtml =
    '<div class="dropdown">' +
      '<button type="button" class="btn user-menu-toggle dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false">' +
        '<span class="avatar-circle">' + initial + '<span class="avatar-status"></span></span>' +
        '<span class="d-none d-sm-flex flex-column align-items-start lh-sm">' +
          '<span class="fw-semibold small">' + escapeHtml(user.username) + '</span>' +
          roleTagHtml +
        '</span>' +
      '</button>' +
      '<ul class="dropdown-menu dropdown-menu-end user-dropdown">' +
        '<li>' +
          '<div class="dropdown-user-card d-flex align-items-center gap-2 px-2 py-2">' +
            '<span class="avatar-circle avatar-circle-lg">' + initial + '<span class="avatar-status"></span></span>' +
            '<div class="d-flex flex-column lh-sm overflow-hidden">' +
              '<span class="fw-semibold text-truncate">' + escapeHtml(user.username) + '</span>' +
              roleTagHtml +
            '</div>' +
          '</div>' +
        '</li>' +
        '<li><a class="dropdown-item user-menu-item" href="change-password.php"><span class="user-menu-icon user-menu-icon-key"><i class="bi bi-key-fill"></i></span>Change Password</a></li>' +
        '<li><button type="button" class="dropdown-item user-menu-item user-menu-item-danger btn-logout"><span class="user-menu-icon user-menu-icon-out"><i class="bi bi-box-arrow-right"></i></span>Sign out</button></li>' +
      '</ul>' +
    '</div>';

  const navDesktop = document.getElementById('navDesktop');
  const navMobile = document.getElementById('navMobile');
  const userMenu = document.getElementById('userMenu');
  if (navDesktop) navDesktop.innerHTML = navHtml;
  if (navMobile) navMobile.innerHTML = navHtml;
  if (userMenu) userMenu.innerHTML = menuHtml;

  document.querySelectorAll('.btn-logout').forEach((btn) => {
    btn.onclick = async () => {
      try { await api('/auth/logout', {}); } catch (e) {}
      location.href = 'login.php';
    };
  });

  return user;
}
