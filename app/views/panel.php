<?php require __DIR__ . '/layout_inicio.php'; ?>
<header>
  <h1>Emisor de boletas</h1>
  <span class="badge <?= ambiente() === 'cert' ? 'cert' : '' ?>"><?= ambiente() === 'cert' ? 'Certificación (pruebas)' : 'Producción' ?></span>
  <span style="color:var(--muted)"><?= e($usuario['nombre']) ?></span>
  <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="chico">Salir</button></form>
</header>
<main>
  <?php if ($flash): ?><div class="flash <?= e($flash['tipo']) ?>"><?= e($flash['mensaje']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Nueva boleta</h2>
    <form method="post" action="/emitir" id="form-boleta">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="fila-item" style="margin-bottom:2px"><label>Ítem</label><label>Cantidad</label><label>Precio c/IVA</label><span></span></div>
      <div id="items">
        <div class="fila-item">
          <input name="nombre[]" maxlength="80" required>
          <input name="cantidad[]" type="number" min="0.01" step="any" value="1" required>
          <input name="precio[]" type="number" min="1" step="1" required>
          <button type="button" class="chico quitar" aria-label="Quitar ítem">×</button>
        </div>
      </div>
      <p style="display:flex; gap:12px; align-items:center; flex-wrap:wrap">
        <button type="button" id="agregar">+ Agregar ítem</button>
        <strong style="flex:1; text-align:right">Total: $<span id="total">0</span></strong>
        <button class="primario" type="submit">Emitir boleta</button>
      </p>
    </form>
  </div>

  <div class="card">
    <h2>Últimas boletas</h2>
    <div class="scroll">
    <table>
      <thead><tr><th>Folio</th><th>Fecha</th><th class="num">Total</th><th>Estado SII</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($boletas as $b): ?>
        <tr>
          <td><?= e($b['folio']) ?></td>
          <td><?= e($b['fecha_emision'] ?? '—') ?></td>
          <td class="num"><?= $b['monto_total'] === null ? '—' : '$' . number_format((int) $b['monto_total'], 0, ',', '.') ?></td>
          <td class="<?= e($b['estado']) ?>"><?= e($b['estado']) ?></td>
          <td class="acciones">
            <?php if ($b['tiene_xml']): ?><a class="btn chico" href="<?= e(urlPdf((int) $b['id'])) ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
            <?php if ($b['estado'] === 'enviada'): ?>
              <form method="post" action="/boletas/<?= (int) $b['id'] ?>/estado"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="chico">Actualizar estado</button></form>
            <?php elseif ($b['estado'] === 'emitida'): ?>
              <form method="post" action="/boletas/<?= (int) $b['id'] ?>/reenviar"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="chico">Reenviar al SII</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$boletas): ?><tr><td colspan="5" style="color:var(--muted)">Aún no hay boletas.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</main>
<script>
  const items = document.getElementById('items');
  const plantilla = items.firstElementChild.cloneNode(true);
  function recalcular() {
    let total = 0;
    items.querySelectorAll('.fila-item').forEach(f => {
      total += Math.round((parseFloat(f.children[1].value) || 0) * (parseInt(f.children[2].value) || 0));
    });
    document.getElementById('total').textContent = total.toLocaleString('es-CL');
  }
  document.getElementById('agregar').addEventListener('click', () => {
    const fila = plantilla.cloneNode(true);
    fila.querySelectorAll('input').forEach(i => i.value = i.name === 'cantidad[]' ? '1' : '');
    items.appendChild(fila);
    fila.querySelector('input').focus();
  });
  items.addEventListener('click', ev => {
    if (ev.target.classList.contains('quitar') && items.children.length > 1) { ev.target.parentElement.remove(); recalcular(); }
  });
  items.addEventListener('input', recalcular);
  document.getElementById('form-boleta').addEventListener('submit', ev => {
    const boton = ev.target.querySelector('button[type=submit]');
    boton.disabled = true; boton.textContent = 'Emitiendo…';
  });
</script>
</body>
</html>
