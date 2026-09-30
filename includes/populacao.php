<?php
// População residente por quadrícula, e tudo o que se pode responder com ela.
//
// O ficheiro data/populacao_pt.csv (células de 1 km) é produzido uma vez por
// scripts/preparar_populacao.php a partir da grelha do Census 2021.
//
// Fonte: Eurostat, Census 2021 population grid (© União Europeia, 2026).
// Dados filtrados para Portugal, reprojetados de ETRS89-LAEA para WGS84
// e agregados em quadrículas por este projeto.
//
// Porquê: um mapa de área diz "40% do território fica longe". Isso soa dramático e
// não é: quase todo esse território é serra sem ninguém. A pergunta que interessa a
// quem decide, e a quem lá vive, é quantas PESSOAS ficam longe.

require_once __DIR__ . '/funcoes.php';

function ficheiro_populacao()
{
    return __DIR__ . '/../data/populacao_pt.csv';
}

function ha_populacao()
{
    return is_readable(ficheiro_populacao());
}

// Lê o CSV uma vez por pedido. São ~41 mil linhas: cabe à vontade em memória e
// evita reler o ficheiro em cada raster (a análise de zona chega a fazer 4).
function celulas_populacao()
{
    static $celulas = null;
    if ($celulas !== null) {
        return $celulas;
    }

    $celulas = [];
    $fp = @fopen(ficheiro_populacao(), 'r');
    if (!$fp) {
        throw new Exception('Falta o data/populacao_pt.csv. Corre antes: '
            . 'php scripts/preparar_populacao.php <ESTAT_Census_2021_V3.csv>');
    }
    fgetcsv($fp, 0, ',', '"', ''); // cabeçalho
    while (($linha = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        if (count($linha) < 4) {
            continue;
        }
        $celulas[] = [(float) $linha[0], (float) $linha[1], (int) $linha[2], (int) $linha[3]];
    }
    fclose($fp);
    return $celulas;
}

// ---------- agregação para um raster ----------

// Soma a população das células de 1 km nas quadrículas do raster que as contêm.
//
// Devolve ['populacao' => int[], 'populacao_65' => int[], 'resumo' => [...]],
// com os arrays do tamanho largura*altura e na mesma ordem do 'valores' do raster.
//
// Células cujo centro cai numa quadrícula marcada como mar são encostadas à
// quadrícula de terra vizinha mais próxima: a população portuguesa é muito
// costeira e, sem isto, perdiam-se povoações inteiras à beira-mar.
function populacao_por_raster($raster)
{
    $largura = $raster['largura'];
    $altura = $raster['altura'];
    $sul = $raster['sul'];
    $oeste = $raster['oeste'];
    $dLat = $raster['dLat'];
    $dLon = $raster['dLon'];
    $terra = $raster['terra'];

    $populacao = array_fill(0, $largura * $altura, 0);
    $populacao65 = array_fill(0, $largura * $altura, 0);
    $resumo = ['dentro' => 0, 'encostada' => 0, 'perdida' => 0, 'fora' => 0];

    foreach (celulas_populacao() as [$lat, $lon, $t, $t65]) {
        $j = (int) floor(($lat - $sul) / $dLat);
        $i = (int) floor(($lon - $oeste) / $dLon);
        if ($j < 0 || $j >= $altura || $i < 0 || $i >= $largura) {
            $resumo['fora'] += $t; // outra região: este raster não a cobre
            continue;
        }

        $k = $j * $largura + $i;
        if (!$terra[$k]) {
            $k = quadricula_de_terra_vizinha($raster, $j, $i, $lat, $lon);
            if ($k === null) {
                $resumo['perdida'] += $t;
                continue;
            }
            $resumo['encostada'] += $t;
        } else {
            $resumo['dentro'] += $t;
        }

        $populacao[$k] += $t;
        $populacao65[$k] += $t65;
    }

    return ['populacao' => $populacao, 'populacao_65' => $populacao65, 'resumo' => $resumo];
}

// Entre as oito quadrículas à volta, a de terra cujo centro está mais perto do ponto.
function quadricula_de_terra_vizinha($raster, $j, $i, $lat, $lon)
{
    $melhor = null;
    $melhorDist = INF;

    for ($dj = -1; $dj <= 1; $dj++) {
        for ($di = -1; $di <= 1; $di++) {
            if ($dj === 0 && $di === 0) {
                continue;
            }
            $jj = $j + $dj;
            $ii = $i + $di;
            if ($jj < 0 || $jj >= $raster['altura'] || $ii < 0 || $ii >= $raster['largura']) {
                continue;
            }
            $k = $jj * $raster['largura'] + $ii;
            if (!$raster['terra'][$k]) {
                continue;
            }
            $cLat = $raster['sul'] + ($jj + 0.5) * $raster['dLat'];
            $cLon = $raster['oeste'] + ($ii + 0.5) * $raster['dLon'];
            $dist = ($cLat - $lat) * ($cLat - $lat) + ($cLon - $lon) * ($cLon - $lon);
            if ($dist < $melhorDist) {
                $melhorDist = $dist;
                $melhor = $k;
            }
        }
    }
    return $melhor;
}

// ---------- classificação ----------

// Mesma regra que o classificar() do JavaScript, para os números do servidor e do
// browser nunca discordarem.
function classe_por_minutos($tipo, $minutos)
{
    if ($minutos === null) {
        return 'sem_dados';
    }
    [$bom, $limite] = cfg('limiares')[$tipo];
    if ($minutos <= $bom) {
        return 'bom';
    }
    return $minutos <= $limite ? 'limitado' : 'deserto';
}

// Resumo populacional de um raster já calculado: quantas pessoas em cada classe de
// acesso, quantas acima de cada limiar, e o mesmo só para os 65+.
//
// O escalão 65+ está aqui de propósito: é quem mais usa urgências, quem menos conduz
// e quem vive mais longe. Um mapa que só dê o total esconde exatamente o problema.
function resumo_populacao($valores, $populacao, $populacao65, $tipo)
{
    [$bom, $limite] = cfg('limiares')[$tipo];

    $classes = ['bom' => 0, 'limitado' => 0, 'deserto' => 0, 'sem_dados' => 0];
    $classes65 = $classes;
    $total = 0;
    $total65 = 0;
    $somaPesoMin = 0.0;  // pessoas x minutos, para a média ponderada
    $pesoValido = 0;

    foreach ($populacao as $k => $pessoas) {
        if ($pessoas <= 0) {
            continue;
        }
        $p65 = $populacao65[$k] ?? 0;
        $total += $pessoas;
        $total65 += $p65;

        $minutos = $valores[$k] ?? null;
        $classe = classe_por_minutos($tipo, $minutos);
        $classes[$classe] += $pessoas;
        $classes65[$classe] += $p65;

        if ($minutos !== null) {
            $somaPesoMin += $pessoas * $minutos;
            $pesoValido += $pessoas;
        }
    }

    return [
        'total' => $total,
        'total_65' => $total65,
        'classes' => $classes,
        'classes_65' => $classes65,
        // Média que conta pessoas, não quadrículas. A média por quadrícula dá o mesmo
        // peso a uma serra vazia e a um bairro de Lisboa; esta não.
        'minutos_medios' => $pesoValido ? round($somaPesoMin / $pesoValido, 1) : null,
        'limiares' => ['bom' => $bom, 'limite' => $limite],
        'acima_do_limite' => $classes['deserto'],
        'acima_do_limite_65' => $classes65['deserto'],
        'pct_acima' => $total ? round(100 * $classes['deserto'] / $total, 1) : 0,
        'pct_acima_65' => $total65 ? round(100 * $classes65['deserto'] / $total65, 1) : 0,
    ];
}

// Quantas pessoas vivem acima de cada limiar de tempo indicado.
function pessoas_por_limiar($valores, $populacao, $populacao65, $limiares)
{
    $total = 0;
    $total65 = 0;
    $semTempo = 0;
    $acima = [];
    $acima65 = [];
    foreach ($limiares as $minutos) {
        $acima[(string) $minutos] = 0;
        $acima65[(string) $minutos] = 0;
    }

    foreach ($populacao as $k => $pessoas) {
        if ($pessoas <= 0) {
            continue;
        }
        $total += $pessoas;
        $total65 += $populacao65[$k];

        if (!isset($valores[$k]) || $valores[$k] === null) {
            $semTempo += $pessoas;
            continue;
        }
        foreach ($limiares as $minutos) {
            if ($valores[$k] > $minutos) {
                $acima[(string) $minutos] += $pessoas;
                $acima65[(string) $minutos] += $populacao65[$k];
            }
        }
    }

    return [
        'total' => $total,
        'total_65' => $total65,
        'sem_tempo' => $semTempo, // não classificadas: têm de ser declaradas, não escondidas
        'acima_de' => $acima,
        'acima_de_65' => $acima65,
    ];
}

// ---------- o país inteiro ----------

// Lê o mapa nacional pré-calculado de um tipo de cuidado.
function grelha_nacional($tipo)
{
    $ficheiro = __DIR__ . "/../data/grelha_$tipo.json";
    if (!is_readable($ficheiro)) {
        return null;
    }
    $dados = json_decode(file_get_contents($ficheiro), true);
    return is_array($dados) && !empty($dados['rasters']) ? $dados : null;
}

// Distribuição nacional (minutos, pessoas), ordenada por minutos. É a base do percentil
// e do ranking. Fica em cache porque percorrer os três mapas nacionais em cada pedido
// seria desperdício: os ficheiros só mudam quando o gerar_grelha.php volta a correr.
function distribuicao_nacional($tipo)
{
    $dados = grelha_nacional($tipo);
    if (!$dados) {
        return null;
    }
    $chave = "distribuicao-$tipo-" . ($dados['gerado_em'] ?? '');
    $guardado = cache_ler($chave, 30 * 24 * 3600);
    if ($guardado !== null) {
        return $guardado;
    }

    $pares = [];
    $total = 0;
    $total65 = 0;
    $semTempo = 0;
    foreach ($dados['rasters'] as $r) {
        if (!isset($r['populacao'])) {
            return null; // mapa gerado antes da população existir
        }
        foreach ($r['populacao'] as $k => $pessoas) {
            if ($pessoas <= 0) {
                continue;
            }
            $total += $pessoas;
            $total65 += $r['populacao_65'][$k] ?? 0;
            $m = $r['valores'][$k] ?? null;
            if ($m === null) {
                $semTempo += $pessoas;
                continue;
            }
            $pares[] = [(float) $m, (int) $pessoas];
        }
    }
    if (!$pares) {
        return null;
    }
    usort($pares, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    $resultado = [
        'pares' => $pares,
        'total' => $total,
        'total_65' => $total65,
        'sem_tempo' => $semTempo,
        'gerado_em' => $dados['gerado_em'] ?? null,
        'passo_km' => $dados['passo_km'] ?? null,
    ];
    cache_guardar($chave, $resultado);
    return $resultado;
}

// "Quantos portugueses estão pior do que eu?" — a pergunta que transforma um número
// abstrato de minutos em algo que a pessoa percebe.
//
// Devolve a percentagem da população com tempo IGUAL OU SUPERIOR ao indicado, e a
// mediana nacional para comparação.
function percentil_acesso($tipo, $minutos)
{
    $dist = distribuicao_nacional($tipo);
    if (!$dist || $minutos === null) {
        return null;
    }

    $abaixo = 0;      // pessoas com acesso estritamente melhor
    $igualOuPior = 0;
    foreach ($dist['pares'] as [$m, $pessoas]) {
        if ($m < $minutos) {
            $abaixo += $pessoas;
        } else {
            $igualOuPior += $pessoas;
        }
    }
    $comTempo = $abaixo + $igualOuPior;
    if (!$comTempo) {
        return null;
    }

    // mediana ponderada pela população
    $metade = $comTempo / 2;
    $acumulado = 0;
    $mediana = null;
    foreach ($dist['pares'] as [$m, $pessoas]) {
        $acumulado += $pessoas;
        if ($acumulado >= $metade) {
            $mediana = $m;
            break;
        }
    }

    return [
        'minutos' => round($minutos, 1),
        'mediana_nacional' => $mediana === null ? null : round($mediana, 1),
        'pessoas_pior_ou_igual' => $igualOuPior,
        'pessoas_melhor' => $abaixo,
        'pct_pior_ou_igual' => round(100 * $igualOuPior / $comTempo, 1),
        'pct_melhor' => round(100 * $abaixo / $comTempo, 1),
        'populacao_considerada' => $comTempo,
        'gerado_em' => $dist['gerado_em'],
    ];
}

// Resumo nacional pronto a mostrar: pessoas por classe de acesso, para todo o país.
function resumo_nacional($tipo)
{
    $dados = grelha_nacional($tipo);
    if (!$dados) {
        return null;
    }

    $valores = [];
    $pop = [];
    $pop65 = [];
    foreach ($dados['rasters'] as $r) {
        if (!isset($r['populacao'])) {
            return null;
        }
        foreach ($r['populacao'] as $k => $pessoas) {
            $valores[] = $r['valores'][$k] ?? null;
            $pop[] = $pessoas;
            $pop65[] = $r['populacao_65'][$k] ?? 0;
        }
    }

    return resumo_populacao($valores, $pop, $pop65, $tipo)
        + ['gerado_em' => $dados['gerado_em'] ?? null, 'passo_km' => $dados['passo_km'] ?? null];
}

// As zonas onde mais gente vive longe. Não é "o sítio mais longe do país" — esse é
// sempre uma serra com ninguém. É onde o problema afeta mais pessoas, que é o que uma
// câmara ou uma ARS precisa de saber para decidir.
//
// As quadrículas vizinhas são juntas numa mancha só, senão o topo da lista seriam dez
// quadrículas do mesmo concelho.
function piores_zonas($tipo, $quantas = 15, $ordenar = 'pessoas')
{
    $dados = grelha_nacional($tipo);
    if (!$dados) {
        return null;
    }
    [$bom, $limite] = cfg('limiares')[$tipo];

    // 1) quadrículas acima do limiar, com gente
    $celulas = [];
    foreach ($dados['rasters'] as $ri => $r) {
        if (!isset($r['populacao'])) {
            return null;
        }
        foreach ($r['populacao'] as $k => $pessoas) {
            $m = $r['valores'][$k] ?? null;
            if ($pessoas <= 0 || $m === null || $m <= $limite) {
                continue;
            }
            $j = intdiv($k, $r['largura']);
            $i = $k % $r['largura'];
            $celulas["$ri:$j:$i"] = [
                'ri' => $ri, 'j' => $j, 'i' => $i,
                'lat' => $r['sul'] + ($j + 0.5) * $r['dLat'],
                'lon' => $r['oeste'] + ($i + 0.5) * $r['dLon'],
                'minutos' => $m,
                'pessoas' => $pessoas,
                'pessoas_65' => $r['populacao_65'][$k] ?? 0,
            ];
        }
    }
    if (!$celulas) {
        return [];
    }

    // 2) junta vizinhas (8 direções) numa mancha, por travessia em largura
    $manchas = [];
    $visto = [];
    foreach ($celulas as $chave => $c) {
        if (isset($visto[$chave])) {
            continue;
        }
        $fila = [$chave];
        $visto[$chave] = true;
        $mancha = ['pessoas' => 0, 'pessoas_65' => 0, 'celulas' => 0,
                   'pior' => null, 'somaMin' => 0.0, 'lat' => 0.0, 'lon' => 0.0];

        while ($fila) {
            $atual = array_pop($fila);
            $cel = $celulas[$atual];
            $mancha['pessoas'] += $cel['pessoas'];
            $mancha['pessoas_65'] += $cel['pessoas_65'];
            $mancha['celulas']++;
            $mancha['somaMin'] += $cel['minutos'] * $cel['pessoas'];
            // centro da mancha ponderado pela população: cai onde a gente está
            $mancha['lat'] += $cel['lat'] * $cel['pessoas'];
            $mancha['lon'] += $cel['lon'] * $cel['pessoas'];
            if ($mancha['pior'] === null || $cel['minutos'] > $mancha['pior']['minutos']) {
                $mancha['pior'] = $cel;
            }

            for ($dj = -1; $dj <= 1; $dj++) {
                for ($di = -1; $di <= 1; $di++) {
                    $viz = $cel['ri'] . ':' . ($cel['j'] + $dj) . ':' . ($cel['i'] + $di);
                    if (isset($celulas[$viz]) && !isset($visto[$viz])) {
                        $visto[$viz] = true;
                        $fila[] = $viz;
                    }
                }
            }
        }

        if ($mancha['pessoas'] > 0) {
            $media = $mancha['somaMin'] / $mancha['pessoas'];
            // Peso do problema: pessoas x minutos a mais do que o limiar. É a moeda
            // que permite comparar "muita gente pouco acima" com "pouca gente muito
            // acima" — e é a que decide onde uma unidade nova rende mais.
            $excesso = max(0, $media - $limite);
            $manchas[] = [
                'pessoas' => $mancha['pessoas'],
                'pessoas_65' => $mancha['pessoas_65'],
                'celulas' => $mancha['celulas'],
                'minutos_medios' => round($media, 1),
                'minutos_pior' => round($mancha['pior']['minutos'], 1),
                'minutos_acima' => round($excesso, 1),
                'pessoas_minuto' => (int) round($mancha['pessoas'] * $excesso),
                'lat' => round($mancha['lat'] / $mancha['pessoas'], 5),
                'lon' => round($mancha['lon'] / $mancha['pessoas'], 5),
            ];
        }
    }

    $chave = $ordenar === 'criticidade' ? 'pessoas_minuto' : 'pessoas';
    usort($manchas, function ($a, $b) use ($chave) {
        return $b[$chave] <=> $a[$chave];
    });

    return array_slice($manchas, 0, $quantas);
}

// ---------- população à volta de um ponto ----------

// Quantas pessoas vivem a menos de $raioKm de um ponto. Serve para responder a
// "quanta gente partilha este problema contigo" na análise de uma morada.
function populacao_perto($lat, $lon, $raioKm = 5)
{
    $total = 0;
    $total65 = 0;
    $celulas = 0;
    // caixa em graus, para descartar depressa o que está longe antes de fazer contas
    $dLat = $raioKm / 111.0;
    $dLon = $raioKm / (111.0 * max(0.2, cos(deg2rad($lat))));

    foreach (celulas_populacao() as [$cLat, $cLon, $t, $t65]) {
        if (abs($cLat - $lat) > $dLat || abs($cLon - $lon) > $dLon) {
            continue;
        }
        if (distancia_km($lat, $lon, $cLat, $cLon) > $raioKm) {
            continue;
        }
        $total += $t;
        $total65 += $t65;
        $celulas++;
    }

    return [
        'raio_km' => $raioKm,
        'pessoas' => $total,
        'pessoas_65' => $total65,
        'pct_65' => $total ? round(100 * $total65 / $total, 1) : null,
        'celulas' => $celulas,
        'densidade_km2' => $celulas ? round($total / ($celulas * 1.0)) : null,
    ];
}
