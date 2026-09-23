<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $qr      inline SVG (trusted: generated server-side)
 * @var string $secret  the seed, grouped for manual entry
 * @var string|null $error
 */
?>
<div class="page-head">
  <h1>Set up two-factor</h1>
  <a href="/console/account">Cancel</a>
</div>

<div class="setup-grid">
  <section class="card">
    <h2>1. Scan this code</h2>
    <p class="hint">Open your authenticator app, choose <em>Add account</em> / <em>Scan QR code</em>, and point it here.</p>
    <div class="qr-box" role="img" aria-label="QR code for your authenticator app"><?= $qr ?></div>
    <p class="hint">Can't scan? Enter this key by hand (time-based, 6 digits):</p>
    <p><code class="secret-key"><?= $this->e($secret) ?></code></p>
  </section>

  <section class="card">
    <h2>2. Enter the code it shows</h2>
    <?php if ($error): ?><div class="flash flash-error"><?= $this->e($error) ?></div><?php endif; ?>
    <form method="post" action="/console/account/two-factor/confirm" class="record-form nobox">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <label>6-digit code
        <input name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" required autofocus maxlength="7" class="code-input">
      </label>
      <button type="submit">Turn on two-factor</button>
    </form>
    <p class="hint">Next you'll get 10 one-time recovery codes. Keep them somewhere safe — they are the only way in if you lose your phone.</p>
  </section>
</div>
