<?php if (!empty($compactFooter)): ?>
<footer class="mt-auto px-3.5 py-3 text-center text-[10px] text-maroon-dark/45 leading-relaxed border-t border-gold/20 bg-ivory">
  <p>Shreeshta Family Store, Malleshwaram · Terms apply</p>
</footer>
<?php else: ?>
<footer class="mt-auto border-t border-gold/40 bg-maroon-dark text-ivory/80 text-xs sm:text-sm">
  <div class="max-w-7xl mx-auto px-4 sm:px-5 py-4 text-center space-y-0.5">
    <p>&copy; <?= date('Y') ?> Shreeshta Family Store, Malleshwaram, Bengaluru. All rights reserved.</p>
    <p>Terms and conditions apply.</p>
  </div>
</footer>
<?php endif; ?>
</body>
</html>
