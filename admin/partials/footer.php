<?php
/**
 * Closes the shell opened by partials/header.php (mobile offcanvas sidebar +
 * the outer layout divs) and loads the common scripts. The including page
 * must still add its own inline <script> (which calls mountSidebar(activeKey))
 * followed by </body></html>.
 *
 * Expected variables (set by the including page before requiring this file):
 *   string $extraScripts   Optional. Raw HTML for extra <script> tags loaded
 *                          before assets/app.js (e.g. CodeMirror).
 */
?>
      </main>
    </div>

    <!-- Mobile offcanvas sidebar -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar">
      <div class="offcanvas-header">
        <span class="fs-5 fw-bold">Management</span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
      </div>
      <div class="offcanvas-body d-flex flex-column">
        <ul class="nav nav-pills flex-column mb-auto gap-1" id="navMobile"></ul>
      </div>
    </div>
  </div>

  <div id="toastContainer" class="toast-container position-fixed top-0 end-0 p-3"></div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($extraScripts)): ?>
<?= $extraScripts ?>
<?php endif; ?>
  <script src="assets/app.js?v=10"></script>
