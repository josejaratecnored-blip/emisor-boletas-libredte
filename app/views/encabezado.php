<?php require __DIR__ . '/layout_inicio.php'; ?>
<header>
  <h1>Emisor de boletas</h1>
  <span class="badge <?= ambiente() === 'cert' ? 'cert' : '' ?>"><?= ambiente() === 'cert' ? 'Certificación (pruebas)' : 'Producción' ?></span>
  <span style="color:var(--muted)"><?= e($usuario['nombre']) ?></span>
  <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="chico">Salir</button></form>
</header>
<main>
  <nav class="pestanas" aria-label="Tipo de documento">
    <a href="/" <?= $pestana === 'boletas' ? 'aria-current="page"' : '' ?>>Boletas</a>
    <a href="/notas-credito" <?= $pestana === 'notas-credito' ? 'aria-current="page"' : '' ?>>Notas de crédito</a>
  </nav>
  <?php if ($flash): ?><div class="flash <?= e($flash['tipo']) ?>"><?= e($flash['mensaje']) ?></div><?php endif; ?>
