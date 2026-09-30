<?php
// Funções geoespaciais simples (sem bibliotecas externas)

// Distância em linha reta (fórmula de Haversine), em km
function distancia_km($lat1, $lon1, $lat2, $lon2)
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * 6371 * asin(sqrt($a));
}

// Devolve as $n unidades mais próximas de um ponto, em linha reta.
// Serve de pré-filtro: só estas vão ao OSRM, que é o passo "caro".
function mais_proximas($lat, $lon, $unidades, $n)
{
    $distancias = [];
    foreach ($unidades as $i => $u) {
        $distancias[$i] = distancia_km($lat, $lon, $u['lat'], $u['lon']);
    }
    asort($distancias);

    $resultado = [];
    foreach (array_slice($distancias, 0, $n, true) as $i => $d) {
        $resultado[] = $unidades[$i];
    }
    return $resultado;
}

// ---------- Contorno de Portugal ----------

// Carrega o GeoJSON uma vez por pedido e guarda o bbox de cada polígono
// para evitar testar pontos contra ilhas que estão longe
function poligonos_portugal()
{
    static $poligonos = null;
    if ($poligonos !== null) {
        return $poligonos;
    }

    $geojson = json_decode(file_get_contents(__DIR__ . '/../data/portugal.geojson'), true);
    $poligonos = [];
    foreach ($geojson['features'] as $feature) {
        foreach ($feature['geometry']['coordinates'] as $poligono) {
            $anel = $poligono[0]; // contorno exterior (o ficheiro não tem buracos)
            $xs = array_column($anel, 0);
            $ys = array_column($anel, 1);
            $poligonos[] = ['anel' => $anel, 'bbox' => [min($xs), min($ys), max($xs), max($ys)]];
        }
    }
    return $poligonos;
}

function dentro_de_portugal($lat, $lon)
{
    foreach (poligonos_portugal() as $p) {
        [$minX, $minY, $maxX, $maxY] = $p['bbox'];
        if ($lon < $minX || $lon > $maxX || $lat < $minY || $lat > $maxY) {
            continue;
        }
        if (ponto_no_poligono($lon, $lat, $p['anel'])) {
            return true;
        }
    }
    return false;
}

// Algoritmo ray casting: conta quantas arestas um raio horizontal atravessa
function ponto_no_poligono($x, $y, $anel)
{
    $dentro = false;
    $n = count($anel);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$xi, $yi] = $anel[$i];
        [$xj, $yj] = $anel[$j];
        if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
            $dentro = !$dentro;
        }
    }
    return $dentro;
}

// ---------- Grelha ----------

// Grelha regular sobre um retângulo, com a marca de quais os pontos que caem em Portugal.
//
// Ao contrário de gerar_celulas(), devolve o retângulo TODO, linha a linha, incluindo mar e
// estrangeiro. É isso que permite desenhar manchas com contorno curvo em vez de quadrados:
// o traçado das manchas (marching squares) precisa de uma grelha completa e regular.
//
// O passo em longitude é calculado uma vez, à latitude média, para a grelha ser mesmo regular.
// Numa zona muito alta isso distorce um pouco as células nos extremos; para as zonas que a
// página analisa (um concelho, um distrito) a diferença é inferior ao próprio passo.
function gerar_raster($sul, $oeste, $norte, $este, $passoKm)
{
    $dLat = $passoKm / 111.0;
    $dLon = $passoKm / (111.0 * cos(deg2rad(($sul + $norte) / 2)));

    $altura = max(2, (int) ceil(($norte - $sul) / $dLat));
    $largura = max(2, (int) ceil(($este - $oeste) / $dLon));

    $pontos = [];
    $terra = [];
    $indicesTerra = [];
    for ($j = 0; $j < $altura; $j++) {
        for ($i = 0; $i < $largura; $i++) {
            $lat = $sul + ($j + 0.5) * $dLat;
            $lon = $oeste + ($i + 0.5) * $dLon;
            $dentro = dentro_de_portugal($lat, $lon);
            $pontos[] = [round($lat, 5), round($lon, 5)];
            $terra[] = $dentro ? 1 : 0;
            if ($dentro) {
                $indicesTerra[] = $j * $largura + $i;
            }
        }
    }

    return [
        'sul' => round($sul, 6),
        'oeste' => round($oeste, 6),
        'dLat' => round($dLat, 8),
        'dLon' => round($dLon, 8),
        'largura' => $largura,
        'altura' => $altura,
        'pontos' => $pontos,
        'terra' => $terra,
        'indices_terra' => $indicesTerra,
    ];
}

// Centros de quadrículas de $passoKm dentro de um retângulo, só em território português.
// A largura em graus de longitude depende da latitude, por isso é calculada por linha.
function gerar_celulas($sul, $oeste, $norte, $este, $passoKm)
{
    $dLat = $passoKm / 111.0;
    $celulas = [];
    for ($lat = $sul + $dLat / 2; $lat < $norte; $lat += $dLat) {
        $dLon = $passoKm / (111.0 * cos(deg2rad($lat)));
        for ($lon = $oeste + $dLon / 2; $lon < $este; $lon += $dLon) {
            if (dentro_de_portugal($lat, $lon)) {
                $celulas[] = [round($lat, 5), round($lon, 5)];
            }
        }
    }
    return $celulas;
}
