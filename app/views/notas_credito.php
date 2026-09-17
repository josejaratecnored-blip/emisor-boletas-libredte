<?php $pestana = 'notas-credito'; require __DIR__ . '/encabezado.php'; ?>

  <div class="card">
    <h2>Nueva nota de crédito (anulación total de una boleta)</h2>
    <form method="get" action="/notas-credito" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap">
      <p style="margin:0; flex:0 1 220px"><label for="folio">Folio de la boleta</label>
        <input id="folio" name="folio" type="number" min="1" step="1" value="<?= e($folioBuscado ?? '') ?>" required></p>
      <button type="submit">Buscar</button>
    </form>

    <?php if ($folioBuscado !== null): ?>
      <?php if ($boleta === null): ?>
        <p class="rechazada">No existe la boleta folio <?= e($folioBuscado) ?>.</p>
      <?php else: ?>
        <p>
          Boleta <strong>N° <?= e($boleta['folio']) ?></strong> ·
          <?= e($boleta['fecha_emision'] ?? '—') ?> ·
          <strong>$<?= number_format((int) $boleta['monto_total'], 0, ',', '.') ?></strong> ·
          <span class="<?= e($boleta['estado']) ?>"><?= e($boleta['estado']) ?></span>
        </p>
        <?php if ($boleta['nc_id'] !== null): ?>
          <p class="reparos">Esta boleta ya fue anulada con la nota de crédito N° <?= e($boleta['nc_folio']) ?>.</p>
        <?php elseif (!in_array($boleta['estado'], ['aceptada', 'reparos'], true)): ?>
          <p class="reparos">Solo se pueden anular boletas aceptadas por el SII.</p>
        <?php else: ?>
          <form method="post" action="/boletas/<?= (int) $boleta['id'] ?>/anular" id="form-anular"
                data-folio="<?= e($boleta['folio']) ?>" data-total="<?= e(number_format((int) $boleta['monto_total'], 0, ',', '.')) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="primario" type="submit">Emitir nota de crédito</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Últimas notas de crédito</h2>
    <div class="scroll">
    <table>
      <thead><tr><th>Folio</th><th>Fecha</th><th class="num">Total</th><th>Anula boleta</th><th>Estado SII</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($notas as $nc): ?>
        <tr>
          <td><?= e($nc['folio']) ?></td>
          <td><?= e($nc['fecha_emision'] ?? '—') ?></td>
          <td class="num"><?= $nc['monto_total'] === null ? '—' : '$' . number_format((int) $nc['monto_total'], 0, ',', '.') ?></td>
          <td>N° <?= e($nc['boleta_folio']) ?></td>
          <td class="<?= e($nc['estado']) ?>"><?= e($nc['estado']) ?></td>
          <td class="acciones">
            <?php if ($nc['tiene_xml']): ?><a class="btn chico" href="<?= e(urlPdf((int) $nc['id'], 'nc')) ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
            <?php if ($nc['estado'] === 'enviada'): ?>
              <form method="post" action="/notas-credito/<?= (int) $nc['id'] ?>/estado"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="chico">Actualizar estado</button></form>
            <?php elseif ($nc['estado'] === 'emitida'): ?>
              <form method="post" action="/notas-credito/<?= (int) $nc['id'] ?>/reenviar"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="chico">Reenviar al SII</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$notas): ?><tr><td colspan="6" style="color:var(--muted)">Aún no hay notas de crédito.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</main>
<script>
  const anular = document.getElementById('form-anular');
  if (anular) {
    anular.addEventListener('submit', ev => {
      const aviso = `¿Anular la boleta folio ${anular.dataset.folio} por $${anular.dataset.total}?\n\nSe emitirá una nota de crédito ante el SII. No se puede deshacer.`;
      if (!confirm(aviso)) { ev.preventDefault(); return; }
      const boton = anular.querySelector('button');
      boton.disabled = true; boton.textContent = 'Emitiendo…';
    });
  }
</script>
</body>
</html>
