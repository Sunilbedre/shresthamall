<?php
/**
 * templates/header.php
 * Shared HTML head + top brand bar for all public-facing pages.
 * Expects an optional $pageTitle variable to be set before including.
 */
$pageTitle = $pageTitle ?? 'Shreeshta Family Store – ₹1 Special Offer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="Register for the Shreeshta Family Store ₹1 Special Offer, Malleshwaram, and get your voucher on WhatsApp.">
<meta name="robots" content="noindex, follow">
<meta name="theme-color" content="#7A0026">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          maroon: { DEFAULT: '#7A0026', dark: '#520018' },
          gold: { DEFAULT: '#D8A83E', light: '#F4D47A' },
          ivory: '#FFF9ED',
        },
        fontFamily: {
          sans: ['Roboto', 'ui-sans-serif', 'system-ui'],
          heading: ['Roboto', 'ui-sans-serif', 'system-ui'],
        },
      },
    },
  };
</script>
<style>
  body { font-family: 'Roboto', sans-serif; background-color: #FFF9ED; }
  h1, h2, h3, .font-heading { font-family: 'Roboto', sans-serif; }
  .gold-border { border: 1.5px solid #D8A83E; }
  .gold-ring:focus, .gold-ring:focus-within { outline: none; box-shadow: 0 0 0 3px rgba(216,168,62,0.35); border-color: #D8A83E; }
  /* Comfortable mobile taps + avoid iOS zoom on focus */
  input, select, textarea, button { font-size: 16px; font-family: 'Roboto', sans-serif; }
  .tap-target { min-height: 48px; }
  @supports (padding: env(safe-area-inset-bottom)) {
    .safe-bottom { padding-bottom: max(0.75rem, env(safe-area-inset-bottom)); }
  }
  /* Hide default disclosure triangle inconsistently across browsers */
  summary::-webkit-details-marker { display: none; }
  .subtitle-oneline { scrollbar-width: none; -ms-overflow-style: none; }
  .subtitle-oneline::-webkit-scrollbar { display: none; }
</style>
</head>
<body class="min-h-screen flex flex-col text-maroon-dark antialiased">

<?php if (!empty($compactHeader)): ?>
<header class="bg-maroon text-ivory sticky top-0 z-40 shadow-sm">
  <div class="max-w-md mx-auto px-3.5 py-2.5 flex items-start justify-between gap-3">
    <div class="min-w-0 text-left">
      <p class="text-gold-light text-[10px] font-semibold uppercase tracking-[0.14em] leading-none mb-0.5">Shreeshta Family Store</p>
      <?php if (!empty($headerTitle)): ?>
        <h1 id="header_offer_title" class="font-heading text-[15px] sm:text-base font-bold leading-tight"><?= e($headerTitle) ?></h1>
        <?php if (!empty($headerSubtitle)): ?>
          <p class="text-gold-light text-[14px] sm:text-[16px] font-semibold mt-1.5 leading-none whitespace-nowrap overflow-x-auto max-w-full subtitle-oneline"><?= e($headerSubtitle) ?></p>
        <?php endif; ?>
      <?php else: ?>
        <p class="font-heading text-[15px] font-bold leading-tight truncate">Malleshwaram · ₹1 Offer</p>
      <?php endif; ?>
    </div>
    <?php if (!empty($eventDateFormatted)): ?>
      <span class="shrink-0 text-[11px] font-semibold bg-maroon-dark/50 text-gold-light px-2.5 py-1 rounded-full border border-gold/30 mt-0.5">
        <?= e($eventDateFormatted) ?>
      </span>
    <?php endif; ?>
  </div>
</header>
<?php elseif (empty($skipDefaultHeader)): ?>
<header class="bg-maroon text-ivory shadow-md sticky top-0 z-40">
  <div class="max-w-lg mx-auto px-4 sm:px-5 py-3.5 sm:py-5 text-center">
    <p class="uppercase tracking-[0.18em] text-gold-light text-[11px] sm:text-sm font-semibold mb-0.5">Shreeshta Family Store</p>
    <h1 class="font-heading text-xl sm:text-3xl font-bold leading-tight">Malleshwaram, Bengaluru</h1>
  </div>
</header>
<?php endif; ?>
