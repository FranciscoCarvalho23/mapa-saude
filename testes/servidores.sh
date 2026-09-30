#!/bin/bash
AQUI="$(cd "$(dirname "$0")" && pwd)"
# funciona com a pasta testes/ dentro do projeto ou ao lado dele
if [ -f "$AQUI/../index.php" ]; then RAIZ="$(cd "$AQUI/.." && pwd)"; else RAIZ="$(cd "$AQUI/../mapa-saude" && pwd)"; fi
cat > "$RAIZ/config.local.php" <<'PHP'
<?php
return [
  'overpass_urls' => ['http://127.0.0.1:8099/api/interpreter'],
  'nominatim_url' => 'http://127.0.0.1:8099',
  'osrm_url' => 'http://127.0.0.1:8099',
  'intervalo_overpass' => 0.0,
  'intervalo_osrm' => 0.0,
  'intervalo_nominatim' => 0.0,
  'overpass_espera' => 2,
  'overpass_descansos' => ['recusado' => 2, 'sobrecarregado' => 2, 'sem_ligacao' => 2],
];
PHP
mkdir -p "$RAIZ/cache"; chmod 777 "$RAIZ/cache"
find "$RAIZ/cache" -type f ! -name '.htaccess' -delete
php -S 127.0.0.1:8099 "$(dirname "$0")/falso.php" >/dev/null 2>&1 &
echo $! > /tmp/falso.pid
php -S 127.0.0.1:8098 -t "$RAIZ" >/dev/null 2>&1 &
echo $! > /tmp/app.pid
sleep 2
echo "servidores a correr"
