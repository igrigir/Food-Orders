<?php
declare(strict_types=1);
require_admin();
$resetId = positive_id($_GET['reset'] ?? null);
$resetUser = query('SELECT id,name FROM users WHERE id=?',[$resetId])->fetch();
if (!$resetUser) throw new RuntimeException('User not found.');
?>
<section class="panel form-panel password-reset-panel" id="password-reset"><span class="eyebrow">ADMINISTRATOR ACTION</span><h2>Reset password for <?= e($resetUser['name']) ?></h2><p class="muted">Set a new password and share it with the user securely. The old password will stop working.</p><form method="post" class="stack"><?= token_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= $resetId ?>"><label>New password<input type="password" name="password" required minlength="10" maxlength="72" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="password_confirmation" required minlength="10" maxlength="72" autocomplete="new-password"></label><small class="muted">Use 10–72 bytes.</small><div class="form-actions"><button class="button primary">Reset password</button><a class="button" href="index.php?page=users">Cancel</a></div></form></section>
