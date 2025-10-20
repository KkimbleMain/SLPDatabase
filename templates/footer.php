<?php
// templates/footer.php
?>
<footer class="main-footer">
  <div class="footer-container container">
    <div class="footer-section">
      <h3>SLP Database</h3>
      <p class="muted">A tool for Speech Language Pathologists, Educators, and Parents, to manage data for their students, goals, and progress.</p>
    </div>
    <div class="footer-section">
      <h4>Support</h4>
      <ul>
  <li><a href="#" data-modal="contact">Contact us</a></li>
      </ul>
    </div>
    <div class="footer-section">
      <h4>About</h4>
      <ul>
  <li><a href="#" data-modal="learn">Learn more about us</a></li>
  <li><a href="#" data-modal="privacy">Privacy</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">&copy; <?php echo date('Y'); ?> SLP Database</div>
</footer>
<!-- Footer modals (placeholder content) -->
<template id="tmpl-footer-contact">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="contactTitle">
    <div class="modal-overlay"></div>
    <div class="modal-content" style="max-width:700px;padding:18px;">
      <h2 id="contactTitle">Contact Us</h2>
      <p>For help or enquiries, email: support@slp-database.example (this is a placeholder).</p>
      <p>Office hours: Mon–Fri 9:00–17:00</p>
      <div style="margin-top:12px;text-align:right"><button class="btn btn-primary close-modal">Close</button></div>
    </div>
  </div>
</template>

<template id="tmpl-footer-learn">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="learnTitle">
    <div class="modal-overlay"></div>
    <div class="modal-content" style="max-width:700px;padding:18px;">
      <h2 id="learnTitle">Learn more about SLP Database</h2>
      <p>This is placeholder info about the SLP Database project. It helps therapists track goals and progress for their students.</p>
      <ul>
        <p>&emsp;Feature: Track progress by skill</p>
  <?php if (defined('ALLOW_REPORTS') && ALLOW_REPORTS === true): ?>
  <p>&emsp;Feature: Generate printable progress reports</p>
  <?php endif; ?>
        <p>&emsp;Feature: Form storage for evaluations, reports, and custom documents</p>
        <p>&emsp;Feature: Activity log and dashboard</p>
      </ul>
      <div style="margin-top:12px;text-align:right"><button class="btn btn-primary close-modal">Close</button></div>
    </div>
  </div>
</template>

<template id="tmpl-footer-privacy">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="privacyTitle">
    <div class="modal-overlay"></div>
    <div class="modal-content" style="max-width:700px;padding:18px;">
      <h2 id="privacyTitle">Privacy</h2>
      <p>This is placeholder privacy text. In production, replace this with your real privacy policy detailing data storage and retention practices.</p>
      <div style="margin-top:12px;text-align:right"><button class="btn btn-primary close-modal">Close</button></div>
    </div>
  </div>
</template>

<!-- Footer modal wiring/styles handled in global JS/CSS -->
