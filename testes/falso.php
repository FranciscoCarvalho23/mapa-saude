<?php
// Servidor falso que imita o Overpass, o OSRM e o Nominatim, para testar sem tocar nas APIs reais.
// Uso: php -S 127.0.0.1:8099 testes/falso.php
//
// Comportamento controlado por testes/falso_estado.json:
//   { "falhar": ["-18_82"], "falhar_tudo": false, "lento": 0, "timeout_query": false }

$estadoFicheiro = __DIR__ . '/falso_estado.json';
$estado = is_file($estadoFicheiro) ? json_decode(file_get_contents($estadoFicheiro), true) : [];
$estado += ['falhar' => [], 'falhar_tudo' => false, 'lento' => 0, 'timeout_query' => false, 'contagem' => true];

$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json; charset=utf-8');

function registar($linha)
{
    file_put_contents(__DIR__ . '/falso_pedidos.log', $linha . "\n", FILE_APPEND);
}

// ---------- Overpass ----------
if (strpos($caminho, '/api/interpreter') !== false) {
    $query = $_POST['data'] ?? file_get_contents('php://input');
    if (strpos($query, 'data=') === 0) {
        parse_str($query, $p);
        $query = $p['data'] ?? '';
    }

    // extrai todas as bboxes: a query de um bloco tem uma, a do país tem três
    if (!preg_match_all('/\(\s*(-?[\d.]+),(-?[\d.]+),(-?[\d.]+),(-?[\d.]+)\s*\)/', $query, $todas, PREG_SET_ORDER)) {
        http_response_code(400);
        echo json_encode(['message' => 'sem bbox']);
        exit;
    }
    $caixas = [];
    foreach ($todas as $m) {
        $caixas[implode(',', array_slice($m, 1))] = array_map('floatval', array_slice($m, 1));
    }
    $caixas = array_values($caixas);
    $pais = count($caixas) > 1;

    [$sul, $oeste, $norte, $este] = $caixas[0];
    $g = 0.5;
    $chave = $pais ? 'PAIS' : ((int) floor($oeste / $g + 0.0001)) . '_' . ((int) floor($sul / $g + 0.0001));
    registar("overpass $chave");

    if ($estado['lento']) {
        sleep($estado['lento']);
    }
    if (!empty($estado['falhar_pais']) && $pais) {
        http_response_code(429);
        echo 'Error: runtime error: open64: 0 Success /osm3s_v0.7.62_osm_base Dispatcher_Client::request_read_and_idx::rate_limited. Slot available after: 2026-09-19T01:00:00Z, in 47 seconds.';
        exit;
    }
    if ($estado['falhar_tudo'] || in_array($chave, $estado['falhar'], true)) {
        http_response_code(429);
        echo 'Error: rate_limited. Slot available after: 2026-09-19T01:00:00Z, in 3 seconds.';
        exit;
    }
    if ($estado['timeout_query']) {
        echo json_encode(['version' => 0.6, 'remark' => 'runtime error: Query timed out in "query" at line 3 after 90 seconds.', 'elements' => []]);
        exit;
    }

    // Gera unidades fictícias: uma grelha dentro de cada bbox, com tipos alternados.
    $elementos = [];
    $i = 0;
    foreach ($caixas as [$sul, $oeste, $norte, $este]) {
    for ($lat = $sul + 0.13; $lat < $norte; $lat += 0.22) {
        for ($lon = $oeste + 0.11; $lon < $este; $lon += 0.24) {
            $i++;
            $id = abs((int) (($lat * 1000) * 100000 + $lon * 1000));
            $tipo = $i % 3;
            $tags = ['name' => 'Hospital de Teste ' . $id, 'amenity' => 'hospital', 'emergency' => 'yes'];
            // um em cada quatro hospitais sem a etiqueta da urgência preenchida,
            // para haver marcadores "por confirmar" (contorno) nos testes
            if ($tipo === 0 && $i % 12 === 0) {
                unset($tags['emergency']);
                $tags['name'] = 'Hospital Distrital ' . $id;
            }
            if ($tipo === 1) {
                $tags = ['name' => 'Centro de Saúde ' . $id, 'amenity' => 'clinic'];
            } elseif ($tipo === 2) {
                $tags = ['name' => 'Maternidade ' . $id, 'amenity' => 'hospital', 'healthcare' => 'birthing_centre'];
            }
            // ruído que a classificação tem de descartar
            $elementos[] = ['type' => 'node', 'id' => $id + 7, 'lat' => $lat, 'lon' => $lon, 'tags' => ['name' => 'Clínica Dentária ' . $id, 'amenity' => 'clinic']];
            $elementos[] = ['type' => 'node', 'id' => $id, 'lat' => round($lat, 5), 'lon' => round($lon, 5), 'tags' => $tags];
        }
    }
    }
    echo json_encode(['version' => 0.6, 'elements' => $elementos]);
    exit;
}

// ---------- OSRM ----------
if (strpos($caminho, '/table/') !== false) {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $coordsTexto = substr($caminho, strrpos($caminho, '/') + 1);
    $coords = array_map(function ($c) {
        return array_map('floatval', explode(',', $c));
    }, explode(';', $coordsTexto));

    $origens = array_map('intval', explode(';', $q['sources'] ?? '0'));
    $destinos = array_map('intval', explode(';', $q['destinations'] ?? '0'));

    $dur = [];
    $dist = [];
    foreach ($origens as $o) {
        $linhaD = [];
        $linhaM = [];
        foreach ($destinos as $d) {
            $km = 111 * sqrt((($coords[$o][1] - $coords[$d][1]) ** 2) + (($coords[$o][0] - $coords[$d][0]) ** 2) * 0.6);
            $linhaD[] = round($km / 60 * 3600, 1); // 60 km/h
            $linhaM[] = round($km * 1000, 1);
        }
        $dur[] = $linhaD;
        $dist[] = $linhaM;
    }
    echo json_encode([
        'code' => 'Ok',
        'durations' => $dur,
        'distances' => $dist,
        'sources' => array_map(function ($o) use ($coords) {
            return ['distance' => 12.5, 'location' => $coords[$o]];
        }, $origens),
        'destinations' => array_map(function ($d) use ($coords) {
            return ['distance' => 10.0, 'location' => $coords[$d]];
        }, $destinos),
    ]);
    exit;
}

if (strpos($caminho, '/route/') !== false) {
    $coordsTexto = substr($caminho, strrpos($caminho, '/') + 1);
    $coords = array_map(function ($c) {
        return array_map('floatval', explode(',', $c));
    }, explode(';', $coordsTexto));
    echo json_encode(['code' => 'Ok', 'routes' => [[
        'duration' => 900, 'distance' => 15000,
        'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
        'legs' => [[
            'steps' => [
                ['name' => 'Rua de Teste', 'distance' => 320, 'duration' => 60, 'maneuver' => ['type' => 'depart', 'modifier' => '']],
                ['name' => 'Avenida Central', 'distance' => 1800, 'duration' => 180, 'maneuver' => ['type' => 'turn', 'modifier' => 'left']],
                ['name' => '', 'distance' => 5, 'duration' => 2, 'maneuver' => ['type' => 'turn', 'modifier' => 'right']],
                ['name' => 'A1', 'distance' => 9000, 'duration' => 480, 'maneuver' => ['type' => 'on ramp', 'modifier' => 'slight right']],
                ['name' => 'Rotunda do Hospital', 'distance' => 400, 'duration' => 90, 'maneuver' => ['type' => 'roundabout', 'modifier' => 'right', 'exit' => 2]],
                ['name' => 'Hospital de Teste', 'distance' => 0, 'duration' => 0, 'maneuver' => ['type' => 'arrive', 'modifier' => '']],
            ],
        ]],
    ]]]);
    exit;
}

// ---------- Nominatim ----------
if (strpos($caminho, '/search') !== false) {
    echo json_encode([[
        'lat' => '41.15', 'lon' => '-8.61', 'display_name' => 'Porto, Portugal', 'type' => 'city',
    ]]);
    exit;
}
if (strpos($caminho, '/reverse') !== false) {
    echo json_encode(['display_name' => 'Sítio de Teste, Portugal']);
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'nao encontrado']);
