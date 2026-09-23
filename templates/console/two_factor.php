<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string|null $error
 */
?>
<div class="auth-wrap">
  <img class="auth-logo" src="/assets/brand/lockup-large.png" alt="ADWOL Investment &amp; Services Ltd.">
  <div class="auth-card">
    <h1>Two-factor check</h1>
    <p class="hint">Enter the 6-digit code from your authenticator app. Lost your phone? Use one of your recovery codes instead.</p>
    <?php if ($error): ?>
      <div class="flash flash-error"><?= $this->e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/console/two-factor">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <label>Code
        <input name="code" inputmode="text" autocomplete="one-time-code" required autofocus maxlength="20" class="code-input" spellcheck="false">
      </label>
      <button type="submit">Verify</button>
    </form>
    <p class="auth-alt"><a href="/console/login">Cancel and start over</a></p>
  </div>
</div>
