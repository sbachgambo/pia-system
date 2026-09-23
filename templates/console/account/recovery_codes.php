<?php
/**
 * Shown exactly once, straight from a POST response (never via session/flash).
 *
 * @var \App\Http\View\Renderer $this
 * @var list<string> $codes
 * @var string $message
 */
?>
<div class="page-head">
  <h1>Save your recovery codes</h1>
</div>

<div class="flash flash-success"><?= $this->e($message) ?></div>

<section class="card">
  <p><strong>These are shown only once.</strong> Each code works one time if you can't use your authenticator app.
    Store them in a password manager or print them and keep them somewhere safe — not on the phone that has your authenticator.</p>
  <ul class="recovery-codes">
    <?php foreach ($codes as $c): ?>
      <li><code><?= $this->e($c) ?></code></li>
    <?php endforeach; ?>
  </ul>
  <p><a class="btn" href="/console/account">I've saved them — done</a></p>
</section>
