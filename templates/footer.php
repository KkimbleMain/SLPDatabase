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
        <p>&emsp;Feature: Generate printable progress reports</p>
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

<script>
// Footer modal wiring
document.addEventListener('click', function(e){
  try {
    var t = e.target;
    if (t.matches && t.matches('.footer-container a[data-modal]')) {
      e.preventDefault();
      var which = t.getAttribute('data-modal');
      var tpl = document.getElementById('tmpl-footer-' + which);
      if (!tpl) return;
      var frag = tpl.content.cloneNode(true);
      // simple modal insertion
      var container = document.createElement('div');
      container.className = 'footer-modal-host';
      container.appendChild(frag);
      document.body.appendChild(container);
      // close handlers
      container.querySelectorAll('.close-modal, .modal-overlay').forEach(function(btn){ btn.addEventListener('click', function(){ try { container.remove(); } catch(e) { container.style.display = 'none'; } }); });
    }
  } catch (err) { console.warn('footer modal error', err); }
});
</script>
<style>
/* Footer modal styles (match site theme) */
.footer-modal-host .modal { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 11000; }
.footer-modal-host .modal-overlay { position: absolute; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(3px); }
.footer-modal-host .modal-content { position: relative; background: #fff; border-radius: 8px; box-shadow: 0 10px 30px rgba(2,6,23,0.3); max-width: 880px; width: 90%; max-height: 80vh; overflow:auto; padding: 18px; }
.footer-modal-host .modal-content h2 { margin-top: 0; color: #0f172a; }
.footer-modal-host .modal-content p, .footer-modal-host .modal-content ul { color: #334155; line-height:1.5; }
.footer-modal-host .modal-content .close-modal { background:#0ea5a4;color:#fff;border:0;padding:8px 12px;border-radius:6px;cursor:pointer }
.footer-modal-host .modal-content .close-modal:hover{ opacity:0.95 }
@media (max-width:480px) { .footer-modal-host .modal-content { padding:12px; width: 96%; } }
</style>
