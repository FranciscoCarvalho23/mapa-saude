<?php
// Onde colocar N unidades novas em Portugal para melhorar o acesso do maior número de
// pessoas — resolvido como problema de otimização, não por tentativa e erro.
//
// O problema
// ----------
// É uma variante do *maximal covering location problem*: dado um conjunto de locais
// possíveis e a população de cada quadrícula, escolher N locais que minimizem o tempo
// total que as pessoas gastam a chegar a cuidados. É combinatório e NP-difícil — com
// 3.500 locais possíveis e 5 unidades há mais de 10^15 combinações, e não há como
// experimentá-las todas.
//
// O método: arrefecimento simulado
// --------------------------------
// Parte-se de uma solução qualquer e vai-se trocando um local de cada vez. Trocas que
// melhoram são sempre aceites; trocas que pioram são aceites com uma probabilidade que
// começa alta e vai baixando. É isso que permite sair de um óptimo local — descer um
// vale para depois subir um monte mais alto — e é a razão de não ser uma busca gananciosa.
//
// Cada avaliação usa o modelo substituto (includes/modelo.php) em vez do OSRM. Sem ele
// cada troca custava dezenas de pedidos de rede; com ele custa microssegundos, e dá para
// avaliar dezenas de milhares de configurações em segundos.
//
// A aproximação é sempre confirmada no fim: as localizações escolhidas vão ao OSRM a
// sério antes de serem apresentadas como resultado.

require_once __DIR__ . '/modelo.php';
require_once __DIR__ . '/analise.php';

// ---------- preparação ----------

// Junta tudo o que o otimizador precisa numa estrutura plana: quadrículas com população,
// o tempo que têm hoje, e os coeficientes locais do modelo. Arrays paralelos, de propósito
// — dentro do ciclo do arrefecimento simulado, o acesso a arrays associativos pesa.
function preparar_otimizacao($tipo)
{
    $modelo = carregar_modelo($tipo);
    if (!$modelo) {
        throw new Exception("Falta o modelo de tempos. Corre antes: php scripts/treinar_modelo.php $tipo");
    }
    $dados = grelha_nacional($tipo);
    if (!$dados) {
        throw new Exception("Falta data/grelha_$tipo.json. Corre antes: php scripts/gerar_grelha.php $tipo 5");
    }

    $lat = [];
    $lon = [];
    $pop = [];
    $pop65 = [];
    $t0 = [];       // minutos hoje (null = sem rota conhecida)
    $ca = [];       // coeficiente a do modelo local
    $cb = [];       // coeficiente b do modelo local
    $ri = [];
    $kk = [];

    foreach ($dados['rasters'] as $r => $raster) {
        if (!isset($raster['populacao'])) {
            throw new Exception('O mapa nacional foi gerado sem população. '
                . "Corre: php scripts/gerar_grelha.php $tipo 5");
        }
        foreach ($raster['populacao'] as $k => $gente) {
            if ($gente <= 0) {
                continue;
            }
            $j = intdiv($k, $raster['largura']);
            $i = $k % $raster['largura'];
            $coef = $modelo['celulas'][$r][$k] ?? $modelo['global'];

            $lat[] = $raster['sul'] + ($j + 0.5) * $raster['dLat'];
            $lon[] = $raster['oeste'] + ($i + 0.5) * $raster['dLon'];
            $pop[] = $gente;
            $pop65[] = $raster['populacao_65'][$k] ?? 0;
            $t0[] = $raster['valores'][$k];
            $ca[] = $coef[0];
            $cb[] = $coef[1];
            $ri[] = $r;
            $kk[] = $k;
        }
    }

    if (!$pop) {
        throw new Exception('Nenhuma quadrícula com população no mapa nacional.');
    }

    // O modelo é treinado a partir de um mapa nacional concreto. Se o mapa foi
    // regerado depois (unidades novas, correções, outro passo), o modelo ficou a
    // descrever um país que já não existe — e isso tem de se ver, não de se adivinhar.
    $desatualizado = isset($modelo['grelha_gerada_em'], $dados['gerado_em'])
        && $modelo['grelha_gerada_em'] !== $dados['gerado_em'];

    return [
        'lat' => $lat, 'lon' => $lon, 'pop' => $pop, 'pop65' => $pop65,
        't0' => $t0, 'ca' => $ca, 'cb' => $cb, 'ri' => $ri, 'k' => $kk,
        'n' => count($pop),
        'modelo_desatualizado' => $desatualizado,
        'modelo_treinado_em' => $modelo['treinado_em'] ?? null,
        'limiar' => cfg('limiares')[$tipo][1],
        'bom' => cfg('limiares')[$tipo][0],
        'gerado_em' => $dados['gerado_em'] ?? null,
        'passo_km' => $dados['passo_km'] ?? null,
        'modelo_avaliacao' => $modelo['avaliacao'] ?? null,
    ];
}

// Distância em km entre dois pontos, na aproximação plana. Dentro do ciclo quente do
// otimizador, chamar a fórmula de Haversine milhões de vezes custava demasiado; a
// diferença à escala de Portugal é inferior ao tamanho de uma quadrícula.
function dist_rapida($lat1, $lon1, $lat2, $lon2, $cosLat)
{
    $dy = ($lat2 - $lat1) * 111.19;
    $dx = ($lon2 - $lon1) * 111.19 * $cosLat;
    return sqrt($dy * $dy + $dx * $dx);
}

// Tempos previstos de todas as quadrículas até um local candidato.
function coluna_tempos($d, $candidatoLat, $candidatoLon)
{
    $col = [];
    $cosLat = cos(deg2rad($candidatoLat));
    for ($i = 0; $i < $d['n']; $i++) {
        $dist = dist_rapida($d['lat'][$i], $d['lon'][$i], $candidatoLat, $candidatoLon, $cosLat);
        $t = $d['ca'][$i] + $d['cb'][$i] * $dist;
        $col[$i] = $t < 0.5 ? 0.5 : $t;
    }
    return $col;
}

// Custo de uma configuração. Há duas perguntas legítimas e dão respostas diferentes:
//
//   'tempo'   — minimizar pessoas × minutos. Beneficia muita gente um bocadinho, e é
//               a função contínua que o arrefecimento simulado prefere.
//   'deserto' — minimizar quantas pessoas ficam acima do limiar. Concentra o esforço
//               em quem está pior, ainda que o ganho total de minutos seja menor.
//
// O segundo critério salta em degraus (uma pessoa ou está acima do limiar ou não), o que
// deixaria o otimizador sem saber para que lado ir; por isso leva as pessoas-minuto como
// desempate, com um peso pequeno de mais para alterar a ordem.
function custo_configuracao($d, $colunas, $objetivo = 'tempo')
{
    $pessoasMinuto = 0.0;
    $acimaDoLimiar = 0.0;
    $limiar = $d['limiar'];

    for ($i = 0; $i < $d['n']; $i++) {
        $melhor = $d['t0'][$i];
        foreach ($colunas as $col) {
            if ($melhor === null || $col[$i] < $melhor) {
                $melhor = $col[$i];
            }
        }
        if ($melhor === null) {
            continue;
        }
        $pessoasMinuto += $d['pop'][$i] * $melhor;
        if ($melhor > $limiar) {
            $acimaDoLimiar += $d['pop'][$i];
        }
    }

    return $objetivo === 'deserto'
        ? $acimaDoLimiar + $pessoasMinuto / 1000000.0
        : $pessoasMinuto;
}

// ---------- arrefecimento simulado ----------

function otimizar_localizacoes($tipo, $quantas = 3, $opcoes = [])
{
    $inicio = microtime(true);
    $d = preparar_otimizacao($tipo);
    $quantas = max(1, min(12, (int) $quantas));

    $objetivo = ($opcoes['objetivo'] ?? 'tempo') === 'deserto' ? 'deserto' : 'tempo';
    $iteracoes = (int) ($opcoes['iteracoes'] ?? 6000);
    $reinicios = (int) ($opcoes['reinicios'] ?? 3);
    $semente = (int) ($opcoes['semente'] ?? 20260921);
    $segundosMax = (float) ($opcoes['segundos_max'] ?? 25);
    mt_srand($semente);

    // Locais possíveis: as próprias quadrículas com população. Uma unidade nova serve para
    // servir gente, por isso não vale a pena considerar o meio de uma serra vazia — e isso
    // reduz o espaço de procura de 3.600 para os sítios que interessam.
    $candidatos = range(0, $d['n'] - 1);

    // custo de partida, sem construir nada
    $custoInicial = custo_configuracao($d, [], $objetivo);
    $pessoasMinutoInicial = custo_configuracao($d, [], 'tempo');

    $melhorGlobal = null;
    $historico = [];
    $avaliacoes = 0;

    for ($r = 0; $r < $reinicios; $r++) {
        if (microtime(true) - $inicio > $segundosMax) {
            break;
        }

        // partida: sorteia locais com probabilidade proporcional ao peso do problema
        // (pessoas × minutos acima do limiar). Começar já perto do problema poupa
        // milhares de iterações a vaguear pelo litoral, onde não há nada a melhorar.
        $estado = sortear_inicio($d, $quantas);
        $colunas = [];
        foreach ($estado as $c) {
            $colunas[] = coluna_tempos($d, $d['lat'][$c], $d['lon'][$c]);
        }
        $custo = custo_configuracao($d, $colunas, $objetivo);
        $avaliacoes++;

        $melhorLocal = ['estado' => $estado, 'custo' => $custo];
        $tempInicial = max(1.0, abs($custoInicial) * 0.002);
        $tempFinal = max(0.01, $tempInicial / 2000);

        for ($passo = 0; $passo < $iteracoes; $passo++) {
            if (($passo & 255) === 0 && microtime(true) - $inicio > $segundosMax) {
                break;
            }
            $fracao = $passo / max(1, $iteracoes - 1);
            $temperatura = $tempInicial * pow($tempFinal / $tempInicial, $fracao);

            $j = mt_rand(0, $quantas - 1);
            $novo = proximo_candidato($d, $candidatos, $estado, $j);
            if ($novo === null) {
                continue;
            }

            $colunaNova = coluna_tempos($d, $d['lat'][$novo], $d['lon'][$novo]);
            $colunasTeste = $colunas;
            $colunasTeste[$j] = $colunaNova;
            $custoNovo = custo_configuracao($d, $colunasTeste, $objetivo);
            $avaliacoes++;

            $delta = $custoNovo - $custo;
            if ($delta < 0 || mt_rand() / mt_getrandmax() < exp(-$delta / $temperatura)) {
                $estado[$j] = $novo;
                $colunas = $colunasTeste;
                $custo = $custoNovo;
                if ($custo < $melhorLocal['custo']) {
                    $melhorLocal = ['estado' => $estado, 'custo' => $custo];
                }
            }
        }

        $historico[] = round($custoInicial - $melhorLocal['custo'], 1);
        if ($melhorGlobal === null || $melhorLocal['custo'] < $melhorGlobal['custo']) {
            $melhorGlobal = $melhorLocal;
        }
    }

    if ($melhorGlobal === null) {
        throw new Exception('O otimizador não chegou a correr. Aumenta o tempo máximo.');
    }

    return relatorio_otimizacao($tipo, $d, $melhorGlobal, $pessoasMinutoInicial, [
        'objetivo' => $objetivo,
        'iteracoes' => $iteracoes,
        'reinicios' => count($historico),
        'avaliacoes' => $avaliacoes,
        'ganho_por_reinicio' => $historico,
        'segundos' => round(microtime(true) - $inicio, 1),
        'semente' => $semente,
    ]);
}

// Sorteio inicial enviesado para onde o problema pesa mais.
function sortear_inicio($d, $quantas)
{
    $pesos = [];
    $total = 0.0;
    for ($i = 0; $i < $d['n']; $i++) {
        $t = $d['t0'][$i];
        $peso = ($t === null) ? 0.0 : $d['pop'][$i] * max(0.0, $t - $d['bom']);
        $pesos[$i] = $peso;
        $total += $peso;
    }

    $estado = [];
    $tentativas = 0;
    while (count($estado) < $quantas && $tentativas < 500) {
        $tentativas++;
        $alvo = ($total > 0) ? (mt_rand() / mt_getrandmax()) * $total : 0;
        $acumulado = 0.0;
        $escolhido = mt_rand(0, $d['n'] - 1);
        if ($total > 0) {
            for ($i = 0; $i < $d['n']; $i++) {
                $acumulado += $pesos[$i];
                if ($acumulado >= $alvo) {
                    $escolhido = $i;
                    break;
                }
            }
        }
        if (!in_array($escolhido, $estado, true)) {
            $estado[] = $escolhido;
        }
    }
    while (count($estado) < $quantas) {
        $c = mt_rand(0, $d['n'] - 1);
        if (!in_array($c, $estado, true)) {
            $estado[] = $c;
        }
    }
    return $estado;
}

// Vizinho a propor: com 3 em 4 hipóteses uma quadrícula perto da atual (afinar), e com
// 1 em 4 uma qualquer do país (saltar para outra região). Sem os saltos, a solução ficava
// presa à zona onde calhou começar.
function proximo_candidato($d, $candidatos, $estado, $j)
{
    $atual = $estado[$j];
    for ($tentativa = 0; $tentativa < 30; $tentativa++) {
        if (mt_rand(0, 3) > 0) {
            $raio = 0.45; // ~50 km
            $lat = $d['lat'][$atual] + (mt_rand() / mt_getrandmax() * 2 - 1) * $raio;
            $lon = $d['lon'][$atual] + (mt_rand() / mt_getrandmax() * 2 - 1) * $raio;
            $melhor = null;
            $melhorDist = INF;
            $cosLat = cos(deg2rad($lat));
            foreach ($candidatos as $c) {
                $dd = dist_rapida($d['lat'][$c], $d['lon'][$c], $lat, $lon, $cosLat);
                if ($dd < $melhorDist) {
                    $melhorDist = $dd;
                    $melhor = $c;
                }
            }
            $novo = $melhor;
        } else {
            $novo = $candidatos[mt_rand(0, count($candidatos) - 1)];
        }
        if ($novo !== null && !in_array($novo, $estado, true)) {
            return $novo;
        }
    }
    return null;
}

// ---------- relatório ----------

function relatorio_otimizacao($tipo, $d, $melhor, $pessoasMinutoInicial, $execucao)
{
    $colunas = [];
    foreach ($melhor['estado'] as $c) {
        $colunas[] = coluna_tempos($d, $d['lat'][$c], $d['lon'][$c]);
    }

    $antes = ['pessoas' => 0, 'deserto' => 0, 'deserto65' => 0, 'somaPeso' => 0.0, 'peso' => 0];
    $depois = $antes;
    $melhoram = 0;
    // Pessoas-minuto só das quadrículas que já tinham rota antes: comparar "antes" e
    // "depois" sobre conjuntos diferentes de quadrículas dava poupanças negativas.
    // As que não tinham rota nenhuma e passam a ter contam-se à parte.
    $comparavelAntes = 0.0;
    $comparavelDepois = 0.0;
    $semRotaServidas = 0;
    $novosTempos = [];
    $atribuicao = array_fill(0, count($melhor['estado']), ['pessoas' => 0, 'pessoas65' => 0]);

    for ($i = 0; $i < $d['n']; $i++) {
        $gente = $d['pop'][$i];
        $g65 = $d['pop65'][$i];
        $antes['pessoas'] += $gente;
        $depois['pessoas'] += $gente;

        $t = $d['t0'][$i];
        $novo = $t;
        $dono = null;
        foreach ($colunas as $j => $col) {
            if ($novo === null || $col[$i] < $novo) {
                $novo = $col[$i];
                $dono = $j;
            }
        }
        $novosTempos[$i] = $novo;
        if ($dono !== null) {
            $atribuicao[$dono]['pessoas'] += $gente;
            $atribuicao[$dono]['pessoas65'] += $g65;
        }

        // O "antes" e o "depois" têm de ser medidos sobre as MESMAS quadrículas, senão
        // construir uma unidade podia fazer crescer o deserto: uma quadrícula que não
        // tinha rota nenhuma (e por isso não contava para lado nenhum) passava a ter
        // tempo e entrava no deserto do lado de lá. Essas contam-se à parte.
        if ($t === null) {
            if ($novo !== null) {
                $semRotaServidas += $gente;
            }
            continue;
        }

        $antes['somaPeso'] += $gente * $t;
        $antes['peso'] += $gente;
        $comparavelAntes += $gente * $t;
        if ($t > $d['limiar']) {
            $antes['deserto'] += $gente;
            $antes['deserto65'] += $g65;
        }

        $depois['somaPeso'] += $gente * $novo;
        $depois['peso'] += $gente;
        $comparavelDepois += $gente * $novo;
        if ($novo > $d['limiar']) {
            $depois['deserto'] += $gente;
            $depois['deserto65'] += $g65;
        }
        if ($novo !== null && $novo < $t - 0.05) {
            $melhoram += $gente;
        }
    }

    $locais = [];
    foreach ($melhor['estado'] as $j => $c) {
        $locais[] = [
            'ordem' => $j + 1,
            'lat' => round($d['lat'][$c], 5),
            'lon' => round($d['lon'][$c], 5),
            'minutos_hoje' => $d['t0'][$c],
            'pessoas_servidas' => $atribuicao[$j]['pessoas'],
            'pessoas_servidas_65' => $atribuicao[$j]['pessoas65'],
        ];
    }
    // do que serve mais gente para o que serve menos, que é como se lê uma lista destas
    usort($locais, function ($a, $b) {
        return $b['pessoas_servidas'] <=> $a['pessoas_servidas'];
    });
    foreach ($locais as $j => &$l) {
        $l['ordem'] = $j + 1;
    }
    unset($l);

    return [
        'tipo' => $tipo,
        'quantas' => count($locais),
        'locais' => $locais,
        'limiar' => $d['limiar'],
        'antes' => [
            'minutos_medios' => $antes['peso'] ? round($antes['somaPeso'] / $antes['peso'], 1) : null,
            'deserto' => $antes['deserto'],
            'deserto_65' => $antes['deserto65'],
            'pessoas_minuto' => round($antes['somaPeso']),
        ],
        'depois' => [
            'minutos_medios' => $depois['peso'] ? round($depois['somaPeso'] / $depois['peso'], 1) : null,
            'deserto' => $depois['deserto'],
            'deserto_65' => $depois['deserto65'],
            'pessoas_minuto' => round($depois['somaPeso']),
        ],
        'ganho' => [
            'saem_do_deserto' => max(0, $antes['deserto'] - $depois['deserto']),
            'saem_do_deserto_65' => max(0, $antes['deserto65'] - $depois['deserto65']),
            'pessoas_que_melhoram' => $melhoram,
            'pessoas_minuto_poupados' => round($comparavelAntes - $comparavelDepois),
            'horas_pessoa_poupadas' => round(($comparavelAntes - $comparavelDepois) / 60),
            'pct_pessoas_minuto' => $comparavelAntes > 0
                ? round(100 * ($comparavelAntes - $comparavelDepois) / $comparavelAntes, 1) : 0,
            'sem_rota_passam_a_ter' => $semRotaServidas,
        ],
        'populacao_total' => $antes['pessoas'],
        'execucao' => $execucao + [
            'quadriculas' => $d['n'],
            'passo_km' => $d['passo_km'],
            'grelha_gerada_em' => $d['gerado_em'],
        ],
        'modelo' => $d['modelo_avaliacao'],
        'modelo_desatualizado' => !empty($d['modelo_desatualizado']),
        'aviso' => 'Tempos estimados pelo modelo substituto (erro médio de '
            . ($d['modelo_avaliacao']['mae'] ?? '?') . ' min). '
            . 'Confirma com o OSRM antes de citar estes números.',
    ];
}

// ---------- confirmação com o OSRM ----------

// O modelo é uma aproximação. Antes de o resultado valer alguma coisa, os locais
// escolhidos são postos à prova com o OSRM a sério numa amostra de quadrículas, e
// compara-se o que o modelo previu com o que o OSRM diz.
function confirmar_com_osrm($tipo, $locais, $amostra = 120)
{
    $d = preparar_otimizacao($tipo);
    $virtuais = [];
    foreach ($locais as $j => $l) {
        $virtuais[] = [
            'id' => 'proposta/' . $j,
            'nome' => 'Unidade proposta ' . ($j + 1),
            'lat' => $l['lat'],
            'lon' => $l['lon'],
            'tipos' => [$tipo],
            'urgencia' => 'sim',
            'operador' => '',
            'localidade' => '',
        ];
    }

    // amostra: as quadrículas que o modelo diz que mais beneficiam
    $ganhos = [];
    $colunas = [];
    foreach ($locais as $l) {
        $colunas[] = coluna_tempos($d, $l['lat'], $l['lon']);
    }
    for ($i = 0; $i < $d['n']; $i++) {
        $t = $d['t0'][$i];
        if ($t === null) {
            continue;
        }
        $novo = $t;
        foreach ($colunas as $col) {
            if ($col[$i] < $novo) {
                $novo = $col[$i];
            }
        }
        if ($novo < $t - 0.5) {
            $ganhos[$i] = ($t - $novo) * $d['pop'][$i];
        }
    }
    if (!$ganhos) {
        return ['amostra' => 0, 'nota' => 'O modelo não prevê melhoria em nenhuma quadrícula.'];
    }
    arsort($ganhos);
    $escolhidas = array_slice(array_keys($ganhos), 0, $amostra);

    $pontos = [];
    foreach ($escolhidas as $i) {
        $pontos[] = [$d['lat'][$i], $d['lon'][$i]];
    }
    $reais = minutos_para_pontos($pontos, $virtuais);

    $abs = 0.0;
    $quad = 0.0;
    $n = 0;
    $previstos = [];
    foreach ($escolhidas as $p => $i) {
        if ($reais[$p] === null) {
            continue;
        }
        $previsto = INF;
        foreach ($colunas as $col) {
            if ($col[$i] < $previsto) {
                $previsto = $col[$i];
            }
        }
        $erro = $previsto - $reais[$p];
        $abs += abs($erro);
        $quad += $erro * $erro;
        $previstos[] = ['previsto' => round($previsto, 1), 'real' => round($reais[$p], 1)];
        $n++;
    }

    return [
        'amostra' => $n,
        'mae' => $n ? round($abs / $n, 2) : null,
        'rmse' => $n ? round(sqrt($quad / $n), 2) : null,
        'exemplos' => array_slice($previstos, 0, 10),
        'nota' => $n
            ? 'Erro do modelo nas quadrículas que mais beneficiam com as unidades propostas, '
              . 'medido contra o OSRM.'
            : 'Nenhuma quadrícula da amostra teve rota por estrada até às unidades propostas.',
    ];
}
