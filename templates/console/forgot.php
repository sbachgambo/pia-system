<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 */
?>
<div class="auth-wrap">
  <img class="auth-logo" src="/assets/brand/lockup-large.png" alt="ADWOL Investment &amp; Services Ltd.">
  <div class="auth-card">
    <h1>Forgot your password?</h1>
    <p class="hint">Enter the email address on your account and we will send you a link to choose a new password.</p>
    <form method="post" action="/console/forgot-password">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <label>Email
        <input type="email" name="email" autocomplete="username" required autofocus>
      </label>
      <button type="submit">Email me a reset link</button>
    </form>
    <p class="auth-alt"><a href="/console/login">Back to sign in</a></p>
  </div>
</div>
