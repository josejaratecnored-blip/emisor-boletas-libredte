<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Emisor de boletas</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --text:#1d2330; --muted:#667085; --line:#e4e7ec; --accent:#1f5eff; --ok:#067647; --warn:#b54708; --err:#b42318; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
  header { display:flex; align-items:center; gap:12px; padding:12px 20px; background:var(--card); border-bottom:1px solid var(--line); }
  header h1 { font-size:16px; margin:0; flex:1; }
  main { max-width:960px; margin:24px auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:20px; }
  h2 { font-size:15px; margin:0 0 14px; }
  label { display:block; font-size:13px; color:var(--muted); margin-bottom:4px; }
  input { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:6px; font:inherit; }
  input:focus { outline:2px solid var(--accent); outline-offset:-1px; }
  button, .btn { display:inline-block; padding:8px 14px; border:1px solid var(--line); border-radius:6px; background:var(--card); font:inherit; color:var(--text); cursor:pointer; text-decoration:none; }
  button.primario { background:var(--accent); border-color:var(--accent); color:#fff; }
  button.chico, .btn.chico { padding:3px 9px; font-size:13px; }
  .badge { font-size:12px; padding:2px 8px; border-radius:99px; background:#eef2ff; color:var(--accent); white-space:nowrap; }
  .badge.cert { background:#fffaeb; color:var(--warn); }
  .aceptada { color:var(--ok); } .rechazada { color:var(--err); } .reparos, .emitida, .reservada { color:var(--warn); }
  .flash { padding:10px 14px; border-radius:8px; margin-bottom:16px; background:#ecfdf3; color:var(--ok); }
  .flash.error { background:#fef3f2; color:var(--err); }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:middle; }
  th { font-size:12px; color:var(--muted); font-weight:600; }
  td.num, th.num { text-align:right; }
  .fila-item { display:grid; grid-template-columns:1fr 90px 120px 32px; gap:8px; margin-bottom:8px; }
  .acciones form { display:inline; }
  .scroll { overflow-x:auto; }
  .pestanas { display:flex; gap:4px; margin-bottom:16px; border-bottom:1px solid var(--line); }
  .pestanas a { padding:8px 14px; color:var(--muted); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; }
  .pestanas a[aria-current="page"] { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
  @media (max-width:560px) { .fila-item { grid-template-columns:1fr 70px 90px 32px; } }
</style>
</head>
<body>
