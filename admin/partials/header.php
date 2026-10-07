<?php
/**
 * Common page shell shared by every logged-in page: <head> + sidebar + top header.
 * Opens <main>; the including page is responsible for closing it via partials/footer.php.
 *
 * Expected variables (set by the including page before requiring this file):
 *   string $pageTitle         Page title, shown as "<$pageTitle> — Management".
 *   string $headerSubtitle    Optional. One-line subtitle in the top header.
 *   string $headerBackHref    Optional. Back-link URL (shown as a compact button).
 *   string $headerBackLabel   Optional. Back-link label (default "Back").
 *   string $extraHead         Optional. Raw HTML for extra <head> tags.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle ?? 'Management') ?> — Management</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<?php if (!empty($extraHead)): ?>
<?= $extraHead ?>
<?php endif; ?>
  <link rel="stylesheet" href="assets/style.css?v=15" />
</head>
<body>
  <div class="d-flex">
    <!-- Desktop sidebar -->
    <div class="d-none d-md-flex flex-column flex-shrink-0 p-3 border-end sidebar-sticky app-sidebar" style="width: 260px;">
      <a href="dashboard.php" class="d-flex align-items-center gap-2 mb-3 text-decoration-none sidebar-brand">
        <span class="sidebar-brand-icon"><i class="bi bi-grid-1x2-fill"></i></span>
        <span class="fs-5 fw-bold">Management</span>
      </a>
      <hr class="mt-0">
      <div class="sidebar-section-label text-uppercase">Menu</div>
      <ul class="nav nav-pills flex-column mb-auto gap-1" id="navDesktop"></ul>
    </div>

    <div class="flex-grow-1" style="min-width:0;">
      <!-- Header: user profile/logout at top-right, on every breakpoint -->
      <header class="app-header navbar bg-white border-bottom d-flex align-items-center justify-content-between px-3 px-md-4 sticky-top">
        <div class="d-flex align-items-center gap-2 gap-md-3 min-w-0 flex-grow-1 me-2">
          <button class="btn btn-outline-secondary btn-sm d-md-none flex-shrink-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
            <i class="bi bi-list fs-5"></i>
          </button>
<?php if (!empty($headerBackHref)): ?>
          <a href="<?= htmlspecialchars($headerBackHref) ?>" class="app-header-back" title="<?= htmlspecialchars($headerBackLabel ?? 'Back') ?>">
            <i class="bi bi-arrow-left"></i>
            <span class="d-none d-sm-inline"><?= htmlspecialchars($headerBackLabel ?? 'Back') ?></span>
          </a>
<?php endif; ?>
          <div class="app-header-titles min-w-0">
            <div class="app-header-title text-truncate" id="pageHeaderTitle"><?= htmlspecialchars($pageTitle ?? 'Management') ?></div>
            <div class="app-header-subtitle text-truncate<?= empty($headerSubtitle) ? ' d-none' : '' ?>" id="pageHeaderSubtitle"><?= htmlspecialchars($headerSubtitle ?? '') ?></div>
          </div>
        </div>
        <div id="userMenu" class="flex-shrink-0"></div>
      </header>

      <main class="p-3 p-md-4">
