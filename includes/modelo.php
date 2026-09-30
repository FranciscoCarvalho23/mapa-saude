<?php
// Modelo substituto: prevê o tempo de viagem sem chamar o OSRM.
//
// Porquê isto existe
// ------------------
// Procurar o melhor sítio para uma unidade nova obriga a avaliar muitos locais, e avaliar
// um local obriga a recalcular os tempos de toda a grelha. Com o OSRM, cada avaliação são
// dezenas de pedidos e vários segundos — por isso o planeamento antigo só testava três
// candidatos escolhidos à partida. Não é procurar, é adivinhar e confirmar.
//
// Um modelo substituto (surrogate model) troca exatidão por velocidade: aprende a relação
// entre distância em linha reta e tempo real por estrada, a partir dos tempos que o OSRM
// já calculou nos mapas nacionais, e responde em microssegundos. Com ele o otimizador
// avalia dezenas de milhares de configurações em vez de três.
//
// O método: regressão linear local (geograficamente ponderada)
// -----------------------------------------------------------
// Uma regressão global sobre todo o país dá R² de 0,70 e erra 9,5 minutos em média — a
// mesma distância em linha reta significa coisas muito diferentes no Algarve e em
// Trás-os-Montes. A solução é não ajustar uma reta, mas milhares: cada quadrícula aprende
// a SUA relação tempo-distância a partir dos vizinhos mais próximos.
//
// Para cada quadrícula da grelha nacional:
//   1. procuram-se as K quadrículas mais próximas com tempo conhecido;
//   2. ajusta-se t = a + b·d por mínimos quadrados ponderados, com peso gaussiano que
//      dá mais importância aos vizinhos mais chegados;
//   3. guardam-se (a, b). A previsão passa a ser uma soma e um produto.
//
// O `a` é o tempo fixo de chegar à estrada; o `b` é o ritmo local em minutos por km, e é
// aí que a serra se distingue da autoestrada. Com K=28 o erro médio cai para ~7 min e o
// R² sobe para ~0,85.
//
// O modelo é uma aproximação e é tratado como tal: as localizações que o otimizador
// escolher são sempre confirmadas com o OSRM a sério antes de serem apresentadas.

require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/dados_osm.php';
require_once __DIR__ . '/populacao.php';

const MODELO_K = 28;              // vizinhos usados em cada regressão local
const MODELO_LARGURA = 0.5;       // largura do kernel, em fração da distância ao K-ésimo
const MODELO_RITMO_MINIMO = 0.2;  // min/km: trava declives absurdos vindos de poucos pontos

function ficheiro_modelo($tipo)
{
    return __DIR__ . "/../data/modelo_tempo_$tipo.json";
}

function ha_modelo($tipo)
{
    return is_readable(ficheiro_modelo($tipo));
}

function carregar_modelo($tipo)
{
    static $cache = [];
    if (array_key_exists($tipo, $cache)) {
        return $cache[$tipo];
    }
    $f = ficheiro_modelo($tipo);
    $m = is_readable($f) ? json_decode(file_get_contents($f), true) : null;
    $cache[$tipo] = is_array($m) && isset($m['celulas']) ? $m : null;
    return $cache[$tipo];
}

// ---------- regressão local ----------

// Índice espacial simples: agrupa pontos em células de meio grau para não comparar
// cada ponto com todos os outros. Sem isto o treino era O(n²) e demorava minutos.
function indexar_pontos($pontos, $lado = 0.5)
{
    $indice = [];
    foreach ($pontos as $i => $p) {
        $indice[floor($p['lat'] / $lado) . ':' . floor($p['lon'] / $lado)][] = $i;
    }
    return ['lado' => $lado, 'baldes' => $indice];
}

// Candidatos perto de um ponto, alargando o anel de baldes até haver pelo menos $minimo.
function vizinhos_candidatos($indice, $lat, $lon, $minimo)
{
    $lado = $indice['lado'];
    $bj = floor($lat / $lado);
    $bi = floor($lon / $lado);
    $encontrados = [];
    for ($anel = 0; $anel <= 6; $anel++) {
        for ($dj = -$anel; $dj <= $anel; $dj++) {
            for ($di = -$anel; $di <= $anel; $di++) {
                if ($anel > 0 && abs($dj) !== $anel && abs($di) !== $anel) {
                    continue; // só a orla do anel; o interior já foi visto
                }
                $chave = ($bj + $dj) . ':' . ($bi + $di);
                if (isset($indice['baldes'][$chave])) {
                    foreach ($indice['baldes'][$chave] as $i) {
                        $encontrados[] = $i;
                    }
                }
            }
        }
        if (count($encontrados) >= $minimo) {
            break;
        }
    }
    return $encontrados;
}

// Ajusta t = a + b·d nos K vizinhos mais próximos de (lat, lon), com peso gaussiano.
// Devolve [a, b] ou null se não houver vizinhos suficientes.
function ajuste_local($pontos, $indice, $lat, $lon, $excluir = null, $K = MODELO_K)
{
    $candidatos = vizinhos_candidatos($indice, $lat, $lon, $K + 1);
    if (count($candidatos) < 4) {
        return null;
    }

    $dist = [];
    foreach ($candidatos as $i) {
        if ($excluir !== null && $i === $excluir) {
            continue;
        }
        // distância ao quadrado em graus, com a longitude encolhida pela latitude média
        $dlat = $pontos[$i]['lat'] - $lat;
        $dlon = ($pontos[$i]['lon'] - $lon) * 0.75;
        $dist[$i] = $dlat * $dlat + $dlon * $dlon;
    }
    if (count($dist) < 4) {
        return null;
    }
    asort($dist);
    $vizinhos = array_slice($dist, 0, $K, true);
    $h = max(1e-9, end($vizinhos));

    $sw = 0.0;
    $sx = 0.0;
    $sy = 0.0;
    $sxx = 0.0;
    $sxy = 0.0;
    foreach ($vizinhos as $i => $d2) {
        $w = exp(-$d2 / ($h * MODELO_LARGURA));
        $d = $pontos[$i]['d'];
        $t = $pontos[$i]['minutos'];
        $sw += $w;
        $sx += $w * $d;
        $sy += $w * $t;
        $sxx += $w * $d * $d;
        $sxy += $w * $d * $t;
    }

    $den = $sw * $sxx - $sx * $sx;
    if (abs($den) < 1e-9 || $sw <= 0) {
        return null;
    }
    $b = ($sw * $sxy - $sx * $sy) / $den;
    if ($b < MODELO_RITMO_MINIMO) {
        $b = MODELO_RITMO_MINIMO;  // nunca mais rápido do que 300 km/h; protege de ruído
    }
    $a = ($sy - $b * $sx) / $sw;
    return [round($a, 4), round($b, 5)];
}

// ---------- previsão ----------

// Minutos previstos para percorrer $d km em linha reta a partir da quadrícula ($ri, $k).
function prever_na_celula($modelo, $ri, $k, $d)
{
    $c = $modelo['celulas'][$ri][$k] ?? $modelo['global'];
    return max(0.5, $c[0] + $c[1] * $d);
}

// Versão para um ponto qualquer: usa a quadrícula que o contém, ou a global.
function prever_minutos($modelo, $d, $lat, $lon)
{
    if (!$modelo) {
        return 4.0 + $d;  // sem modelo: ~60 km/h e 4 min para chegar à estrada
    }
    foreach ($modelo['rasters'] as $ri => $r) {
        $j = (int) floor(($lat - $r['sul']) / $r['dLat']);
        $i = (int) floor(($lon - $r['oeste']) / $r['dLon']);
        if ($j >= 0 && $j < $r['altura'] && $i >= 0 && $i < $r['largura']) {
            return prever_na_celula($modelo, $ri, $j * $r['largura'] + $i, $d);
        }
    }
    return max(0.5, $modelo['global'][0] + $modelo['global'][1] * $d);
}

// ---------- treino ----------

// Constrói o conjunto de treino a partir de um mapa nacional já calculado.
// Cada linha: distância em linha reta à unidade mais próxima -> minutos que o OSRM deu.
//
// A composição é consistente: como o tempo cresce com a distância, o mínimo dos tempos
// corresponde quase sempre ao mínimo das distâncias. Onde não corresponde — há uma
// unidade mais longe em linha reta mas mais rápida por autoestrada — isso fica no
// resíduo, e é parte do que a regressão local aprende sobre aquela zona.
function dados_de_treino($tipo, $incluirSemTag = null)
{
    $dados = grelha_nacional($tipo);
    if (!$dados) {
        throw new Exception("Falta data/grelha_$tipo.json. Corre antes: php scripts/gerar_grelha.php $tipo 5");
    }
    if ($incluirSemTag === null) {
        $incluirSemTag = $dados['urgencia_inclui_sem_tag'] ?? cfg('urgencia_inclui_sem_tag');
    }
    $unidades = unidades_do_tipo(obter_unidades()['unidades'], $tipo, $incluirSemTag);
    if (!$unidades) {
        throw new Exception("Não há unidades do tipo $tipo.");
    }

    $pontos = [];
    $geometria = [];
    foreach ($dados['rasters'] as $ri => $r) {
        $geometria[$ri] = [
            'sul' => $r['sul'], 'oeste' => $r['oeste'],
            'dLat' => $r['dLat'], 'dLon' => $r['dLon'],
            'largura' => $r['largura'], 'altura' => $r['altura'],
            'regiao' => $r['regiao'] ?? null,
        ];
        foreach ($r['valores'] as $k => $minutos) {
            if ($minutos === null || empty($r['terra'][$k])) {
                continue;
            }
            $j = intdiv($k, $r['largura']);
            $i = $k % $r['largura'];
            $lat = $r['sul'] + ($j + 0.5) * $r['dLat'];
            $lon = $r['oeste'] + ($i + 0.5) * $r['dLon'];

            $d = INF;
            foreach ($unidades as $u) {
                $dd = distancia_km($lat, $lon, $u['lat'], $u['lon']);
                if ($dd < $d) {
                    $d = $dd;
                }
            }
            $pontos[] = ['ri' => $ri, 'k' => $k, 'lat' => $lat, 'lon' => $lon,
                         'd' => $d, 'minutos' => (float) $minutos];
        }
    }

    return [
        'pontos' => $pontos,
        'rasters' => $geometria,
        'gerado_em' => $dados['gerado_em'] ?? null,
        'passo_km' => $dados['passo_km'] ?? null,
        'unidades' => count($unidades),
    ];
}

// Treina e avalia.
//
// A avaliação usa 20% dos pontos que ficam completamente de fora do treino — incluindo a
// própria quadrícula, senão o modelo estaria a ser avaliado com a resposta na mão. O
// modelo final é depois reajustado com tudo, que é a prática habitual.
function treinar_modelo($tipo, $semente = 20260921)
{
    $conjunto = dados_de_treino($tipo);
    $pontos = $conjunto['pontos'];
    if (count($pontos) < 200) {
        throw new Exception('Poucos pontos para treinar (' . count($pontos) . ').');
    }

    // referência global, também usada como recurso para quadrículas sem vizinhos
    $global = ajuste_global($pontos);

    // ---- avaliação honesta ----
    mt_srand($semente);
    $indices = range(0, count($pontos) - 1);
    shuffle($indices);
    $corte = (int) floor(count($indices) * 0.8);
    $treino = array_slice($indices, 0, $corte);
    $teste = array_slice($indices, $corte);

    $pontosTreino = [];
    foreach ($treino as $i) {
        $pontosTreino[] = $pontos[$i];
    }
    $indiceTreino = indexar_pontos($pontosTreino);
    $globalTreino = ajuste_global($pontosTreino);

    $avaliacao = ['n' => 0, 'abs' => 0.0, 'quad' => 0.0, 'ate5' => 0, 'media' => 0.0];
    foreach ($teste as $i) {
        $avaliacao['media'] += $pontos[$i]['minutos'];
    }
    $avaliacao['media'] /= count($teste);

    $ssRes = 0.0;
    $ssTot = 0.0;
    foreach ($teste as $i) {
        $p = $pontos[$i];
        $c = ajuste_local($pontosTreino, $indiceTreino, $p['lat'], $p['lon']) ?: $globalTreino;
        $previsto = max(0.5, $c[0] + $c[1] * $p['d']);
        $erro = $previsto - $p['minutos'];
        $avaliacao['n']++;
        $avaliacao['abs'] += abs($erro);
        $avaliacao['quad'] += $erro * $erro;
        if (abs($erro) <= 5) {
            $avaliacao['ate5']++;
        }
        $ssRes += $erro * $erro;
        $ssTot += ($p['minutos'] - $avaliacao['media']) ** 2;
    }

    $n = $avaliacao['n'];
    $metricas = [
        'n' => $n,
        'mae' => round($avaliacao['abs'] / $n, 2),
        'rmse' => round(sqrt($avaliacao['quad'] / $n), 2),
        'r2' => $ssTot > 0 ? round(1 - $ssRes / $ssTot, 4) : null,
        'pct_erro_ate_5min' => round(100 * $avaliacao['ate5'] / $n, 1),
        'minutos_medios' => round($avaliacao['media'], 1),
    ];

    // ---- modelo final: uma regressão local por quadrícula, com todos os pontos ----
    $indiceTudo = indexar_pontos($pontos);
    $celulas = [];
    foreach ($conjunto['rasters'] as $ri => $r) {
        $celulas[$ri] = [];
    }
    foreach ($pontos as $p) {
        $c = ajuste_local($pontos, $indiceTudo, $p['lat'], $p['lon']);
        $celulas[$p['ri']][$p['k']] = $c ?: $global;
    }

    return [
        'tipo' => $tipo,
        'metodo' => 'regressão linear local ponderada (K=' . MODELO_K . ', kernel gaussiano)',
        'k_vizinhos' => MODELO_K,
        'global' => $global,
        'celulas' => $celulas,
        'rasters' => $conjunto['rasters'],
        'treinado_em' => date('c'),
        'pontos' => count($pontos),
        'pontos_treino' => count($treino),
        'pontos_teste' => count($teste),
        'unidades_no_treino' => $conjunto['unidades'],
        'grelha_gerada_em' => $conjunto['gerado_em'],
        'passo_km' => $conjunto['passo_km'],
        'avaliacao' => $metricas,
        'base_global' => avaliar_global($pontos, $teste, $globalTreino),
    ];
}

// Uma única reta para o país inteiro. Serve de recurso e de termo de comparação.
function ajuste_global($pontos)
{
    $n = count($pontos);
    $sx = 0.0;
    $sy = 0.0;
    $sxx = 0.0;
    $sxy = 0.0;
    foreach ($pontos as $p) {
        $sx += $p['d'];
        $sy += $p['minutos'];
        $sxx += $p['d'] * $p['d'];
        $sxy += $p['d'] * $p['minutos'];
    }
    $den = $n * $sxx - $sx * $sx;
    if (abs($den) < 1e-9) {
        return [4.0, 1.0];
    }
    $b = ($n * $sxy - $sx * $sy) / $den;
    if ($b < MODELO_RITMO_MINIMO) {
        $b = MODELO_RITMO_MINIMO;
    }
    return [round(($sy - $b * $sx) / $n, 4), round($b, 5)];
}

function avaliar_global($pontos, $teste, $global)
{
    $abs = 0.0;
    $quad = 0.0;
    $media = 0.0;
    foreach ($teste as $i) {
        $media += $pontos[$i]['minutos'];
    }
    $media /= count($teste);
    $ssRes = 0.0;
    $ssTot = 0.0;
    foreach ($teste as $i) {
        $erro = max(0.5, $global[0] + $global[1] * $pontos[$i]['d']) - $pontos[$i]['minutos'];
        $abs += abs($erro);
        $quad += $erro * $erro;
        $ssRes += $erro * $erro;
        $ssTot += ($pontos[$i]['minutos'] - $media) ** 2;
    }
    $n = count($teste);
    return [
        'mae' => round($abs / $n, 2),
        'rmse' => round(sqrt($quad / $n), 2),
        'r2' => $ssTot > 0 ? round(1 - $ssRes / $ssTot, 4) : null,
        'km_por_hora' => $global[1] > 0 ? round(60 / $global[1], 1) : null,
    ];
}

function guardar_modelo($modelo)
{
    $f = ficheiro_modelo($modelo['tipo']);
    file_put_contents($f, json_encode($modelo, JSON_UNESCAPED_UNICODE));
    @chmod($f, 0666);
    return $f;
}
