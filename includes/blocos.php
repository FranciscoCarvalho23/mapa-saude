<?php
// Divisão do território em blocos fixos, para carregar as unidades de saúde por zonas
// em vez de pedir o país inteiro ao Overpass de uma só vez.
//
// Cada bloco é um quadrado de `bloco_graus` graus, alinhado com a origem das coordenadas,
// identificado por "<ix>_<iy>" com ix = floor(lon/g) e iy = floor(lat/g). Assim a mesma zona
// tem sempre a mesma chave, o que permite guardar cada bloco em cache de forma independente.

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/geo.php';

// ---------- geometria dos blocos ----------

function bloco_chave($ix, $iy)
{
    return $ix . '_' . $iy;
}

// Bloco que contém um ponto
function bloco_de($lat, $lon)
{
    $g = cfg('bloco_graus');
    return bloco_chave((int) floor($lon / $g), (int) floor($lat / $g));
}

// Aceita apenas chaves com o formato esperado (vêm do browser)
function bloco_indices($chave)
{
    if (!preg_match('/^(-?\d{1,4})_(-?\d{1,4})$/', $chave, $m)) {
        return null;
    }
    return [(int) $m[1], (int) $m[2]];
}

// [sul, oeste, norte, este]
function bloco_caixa($chave)
{
    $indices = bloco_indices($chave);
    if ($indices === null) {
        return null;
    }
    [$ix, $iy] = $indices;
    $g = cfg('bloco_graus');
    return [$iy * $g, $ix * $g, ($iy + 1) * $g, ($ix + 1) * $g];
}

function bloco_centro($chave)
{
    [$sul, $oeste, $norte, $este] = bloco_caixa($chave);
    return [($sul + $norte) / 2, ($oeste + $este) / 2];
}

// Distância em km de um ponto ao bloco (0 se o ponto estiver lá dentro).
// Serve para ordenar os blocos por proximidade e para saber quais tocam um raio.
function bloco_distancia_km($chave, $lat, $lon)
{
    [$sul, $oeste, $norte, $este] = bloco_caixa($chave);
    $latMaisPerto = max($sul, min($lat, $norte));
    $lonMaisPerto = max($oeste, min($lon, $este));
    return distancia_km($lat, $lon, $latMaisPerto, $lonMaisPerto);
}

// ---------- que blocos têm território português ----------

// Blocos que vale a pena pedir ao Overpass. Um bloco entra na lista se algum vértice do
// contorno de Portugal cair lá dentro (apanha a orla costeira e as ilhas pequenas) ou se
// algum ponto de uma amostra interior estiver em Portugal (apanha o interior do país).
function blocos_relevantes()
{
    static $blocos = null;
    if ($blocos !== null) {
        return $blocos;
    }

    $g = cfg('bloco_graus');
    $chave = 'blocos_relevantes_' . $g;
    $guardados = cache_ler($chave, 30 * 24 * 3600);
    if (is_array($guardados) && $guardados) {
        $blocos = $guardados;
        return $blocos;
    }

    $conjunto = [];

    // 1) blocos onde cai um vértice do contorno
    foreach (poligonos_portugal() as $p) {
        foreach ($p['anel'] as [$lon, $lat]) {
            $conjunto[bloco_de($lat, $lon)] = true;
        }
    }

    // 2) blocos interiores: testa uma amostra 4x4 dentro de cada bloco da caixa envolvente
    foreach (poligonos_portugal() as $p) {
        [$minX, $minY, $maxX, $maxY] = $p['bbox'];
        for ($iy = (int) floor($minY / $g); $iy <= (int) floor($maxY / $g); $iy++) {
            for ($ix = (int) floor($minX / $g); $ix <= (int) floor($maxX / $g); $ix++) {
                $c = bloco_chave($ix, $iy);
                if (isset($conjunto[$c])) {
                    continue;
                }
                for ($a = 1; $a <= 4 && !isset($conjunto[$c]); $a++) {
                    for ($b = 1; $b <= 4; $b++) {
                        $lat = ($iy + $a / 5) * $g;
                        $lon = ($ix + $b / 5) * $g;
                        if (dentro_de_portugal($lat, $lon)) {
                            $conjunto[$c] = true;
                            break;
                        }
                    }
                }
            }
        }
    }

    $blocos = array_keys($conjunto);
    sort($blocos);
    cache_guardar($chave, $blocos);
    return $blocos;
}

function bloco_e_relevante($chave)
{
    static $indice = null;
    if ($indice === null) {
        $indice = array_flip(blocos_relevantes());
    }
    return isset($indice[$chave]);
}

// Blocos relevantes que tocam o círculo de raio $raioKm à volta do ponto, do mais perto ao mais longe
function blocos_no_raio($lat, $lon, $raioKm)
{
    $lista = [];
    foreach (blocos_relevantes() as $chave) {
        $d = bloco_distancia_km($chave, $lat, $lon);
        if ($d <= $raioKm) {
            $lista[$chave] = $d;
        }
    }
    asort($lista);
    return array_keys($lista);
}
