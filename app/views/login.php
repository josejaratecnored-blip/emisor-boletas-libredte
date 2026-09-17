<?php require __DIR__ . '/layout_inicio.php'; ?>
<main style="max-width:380px; margin-top:12vh">
  <div class="card">
    <h2>Emisor de boletas · Ingresar</h2>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/login">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <p><label for="email">Correo</label><input id="email" name="email" type="email" autocomplete="username" required autofocus></p>
      <p><label for="password">Contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" required></p>
      <button class="primario" type="submit">Ingresar</button>
    </form>
  </div>
</main>
</body>
</html>
