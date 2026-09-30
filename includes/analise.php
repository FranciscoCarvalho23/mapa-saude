<?php
require_once __DIR__ . '/dados_osm.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/osrm.php';
require_once __DIR__ . '/populacao.php';

// ---------- análise de um ponto ----------

// Tempo de viagem de um ponto até às unidades mais próximas de cada tipo.
// Os dados já estão todos em memória (data/unidades.json), por isso o único trabalho
// que sobra é um pedido ao OSRM com os candidatos dos três tipos juntos.
function analisar_ponto($lat, $lon, $incluirSemTag)
{
    $unidades = obter_unidades()['unidades'];

    $candidatosPorTipo = [];
    $destinos = []; // id => unidade (sem repetidos: um hospital pode ser urgência e maternidade)

    foreach (tipos() as $tipo) {
        $lista = mais_proximas($lat, $lon, unidades_do_tipo($unidades, $tipo, $incluirSemTag), cfg('candidatos_por_tipo'));
        $candidatosPorTipo[$tipo] = array_column($lista, 'id');
        foreach ($lista as $u) {
            $destinos[$u['id']] = $u;
        }
    }
    if (!$destinos) {
        throw new Exception('Não há unidades de saúde nos dados para analisar.');
    }

    $ids = array_keys($destinos);
    $coluna = array_flip($ids);
    $tabela = osrm_tabela([[$lat, $lon]], array_map(function ($id) use ($destinos) {
        return [$destinos[$id]['lat'], $destinos[$id]['lon']];
    }, $ids));

    $resultados = [];
    foreach ($candidatosPorTipo as $tipo => $idsTipo) {
        $opcoes = [];
        foreach ($idsTipo as $id) {
            $u = $destinos[$id];
            $segundos = $tabela['duracoes'][0][$coluna[$id]];
            $metros = $tabela['distancias'][0][$coluna[$id]] ?? null;
            $opcoes[] = [
                'id' => $id,
                'nome' => $u['nome'],
                'lat' => $u['lat'],
                'lon' => $u['lon'],
                'urgencia' => $u['urgencia'],
                'localidade' => $u['localidade'] ?? '',
                'operador' => $u['operador'] ?? '',
                'minutos' => $segundos === null ? null : round($segundos / 60, 1),
                'km' => $metros === null ? null : round($metros / 1000, 1),
                'linha_reta_km' => round(distancia_km($lat, $lon, $u['lat'], $u['lon']), 1),
            ];
        }
        // ordenar por tempo; unidades sem rota vão para o fim
        usort($opcoes, function ($a, $b) {
            if ($a['minutos'] === null || $b['minutos'] === null) {
                return ($a['minutos'] === null) <=> ($b['minutos'] === null);
            }
            return $a['minutos'] <=> $b['minutos'];
        });
        $resultados[$tipo] = array_slice($opcoes, 0, 3);
    }

    $resposta = [
        'origem' => ['lat' => $lat, 'lon' => $lon, 'estrada_m' => round($tabela['origem_estrada_m'][0])],
        'resultados' => $resultados,
    ];

    // Contexto populacional: quanta gente vive à volta e em que posição fica esta
    // morada no país. Um tempo sozinho não diz se é bom ou mau — 25 minutos até uma
    // urgência parece muito a quem vive em Lisboa e é um luxo para quem vive no interior.
    if (ha_populacao()) {
        $resposta['vizinhanca'] = populacao_perto($lat, $lon, 10);
        $comparacao = [];
        foreach ($resultados as $tipo => $opcoes) {
            $minutos = $opcoes[0]['minutos'] ?? null;
            $p = $minutos === null ? null : percentil_acesso($tipo, $minutos);
            if ($p) {
                $comparacao[$tipo] = $p;
            }
        }
        if ($comparacao) {
            $resposta['comparacao_nacional'] = $comparacao;
        }
    }

    return $resposta;
}

// ---------- tempos de uma lista de pontos ----------

// Para cada ponto: tempo (minutos) até à unidade mais próxima e qual foi.
// Os pontos são agrupados em lotes que cabem num pedido /table.
//
// Devolve [['minutos' => float|null, 'id' => string|null], ...], na ordem dos pontos.
function tempos_para_pontos($pontos, $unidades, $candidatosPorPonto = 3)
{
    $resultado = array_fill(0, count($pontos), ['minutos' => null, 'id' => null]);
    if (!$pontos || !$unidades) {
        return $resultado;
    }

    $porId = array_column($unidades, null, 'id');
    $maxCoords = cfg('osrm_max_coords');

    // 1) lotes: cada ponto leva as suas N unidades mais próximas em linha reta.
    //    Pontos vizinhos partilham candidatos, por isso cabem muitos por pedido.
    $lotes = [];
    $origens = [];
    $destinos = [];
    foreach ($pontos as $i => [$lat, $lon]) {
        $candidatos = array_fill_keys(array_column(mais_proximas($lat, $lon, $unidades, $candidatosPorPonto), 'id'), true);
        $juntos = $destinos + $candidatos;
        if ($origens && count($origens) + 1 + count($juntos) > $maxCoords) {
            $lotes[] = [$origens, array_keys($destinos)];
            $origens = [];
            $juntos = $candidatos;
        }
        $origens[] = $i;
        $destinos = $juntos;
    }
    $lotes[] = [$origens, array_keys($destinos)];

    // 2) um pedido por lote; cada ponto fica com o melhor destino do lote
    foreach ($lotes as [$indices, $ids]) {
        $tabela = osrm_tabela(
            array_map(function ($i) use ($pontos) {
                return $pontos[$i];
            }, $indices),
            array_map(function ($id) use ($porId) {
                return [$porId[$id]['lat'], $porId[$id]['lon']];
            }, $ids)
        );

        foreach ($indices as $k => $i) {
            if ($tabela['origem_estrada_m'][$k] > cfg('max_snap_metros')) {
                continue; // demasiado longe de uma estrada para o tempo ser fiável
            }
            $melhor = null;
            $melhorId = null;
            foreach ($tabela['duracoes'][$k] as $c => $segundos) {
                if ($segundos !== null && ($melhor === null || $segundos < $melhor)) {
                    $melhor = $segundos;
                    $melhorId = $ids[$c];
                }
            }
            if ($melhor !== null) {
                $resultado[$i] = ['minutos' => round($melhor / 60, 1), 'id' => $melhorId];
            }
        }
    }
    return $resultado;
}

// Só os minutos, para quem não precisa de saber qual foi a unidade
function minutos_para_pontos($pontos, $unidades)
{
    return array_column(tempos_para_pontos($pontos, $unidades), 'minutos');
}

// ---------- grelhas em raster (para as manchas) ----------

// Recusa rasters grandes de mais: o custo real são os pontos em terra (vão ao OSRM),
// mas um retângulo enorme e quase todo mar também não vale a pena enviar ao browser.
function validar_raster($raster)
{
    $maximo = cfg('max_celulas_zona');
    return count($raster['indices_terra']) <= $maximo * 1.2
        && $raster['largura'] * $raster['altura'] <= $maximo * 8;
}

// Só a parte do raster que o browser precisa (sem os pontos nem os índices, que são
// reconstruíveis a partir da origem e do passo).
function raster_publico($raster, $valores, $extra = [])
{
    return [
        'sul' => $raster['sul'],
        'oeste' => $raster['oeste'],
        'dLat' => $raster['dLat'],
        'dLon' => $raster['dLon'],
        'largura' => $raster['largura'],
        'altura' => $raster['altura'],
        'terra' => $raster['terra'],
        'valores' => $valores,
    ] + $extra;
}

// Calcula o tempo até à unidade mais próxima em cada ponto de terra do raster.
// Devolve o raster pronto a enviar, mais as contagens por classe.
function raster_com_tempos($raster, $unidades, $tipo = null)
{
    $pontosTerra = array_map(function ($k) use ($raster) {
        return $raster['pontos'][$k];
    }, $raster['indices_terra']);

    $resultado = tempos_para_pontos($pontosTerra, $unidades);

    $valores = array_fill(0, $raster['largura'] * $raster['altura'], null);
    $ids = array_fill(0, $raster['largura'] * $raster['altura'], null);
    foreach ($raster['indices_terra'] as $n => $k) {
        $valores[$k] = $resultado[$n]['minutos'];
        $ids[$k] = $resultado[$n]['id'];
    }

    return raster_publico($raster, $valores) + ['unidade_por_celula' => $ids];
}


// Junta a população a um raster já calculado. Se o data/populacao_pt.csv não existir,
// devolve o raster tal como estava e marca que não há população — o projeto continua a
// funcionar só com geografia, como antes.
function juntar_populacao($publico, $raster, $tipo = null)
{
    if (!ha_populacao()) {
        return $publico + ['tem_populacao' => false];
    }
    $pop = populacao_por_raster($raster);
    $publico['populacao'] = $pop['populacao'];
    $publico['populacao_65'] = $pop['populacao_65'];
    $publico['tem_populacao'] = true;
    if ($tipo !== null) {
        $publico['resumo_populacao'] = resumo_populacao(
            $publico['valores'], $pop['populacao'], $pop['populacao_65'], $tipo
        );
    }
    return $publico;
}

// ---------- área de influência de uma unidade ----------

function influencia_unidade($id, $tipo, $raster, $incluirSemTag)
{
    $unidades = unidades_do_tipo(obter_unidades()['unidades'], $tipo, $incluirSemTag);
    $alvo = null;
    foreach ($unidades as $u) {
        if ($u['id'] === $id) {
            $alvo = $u;
            break;
        }
    }
    if (!$alvo) {
        throw new Exception('Essa unidade não existe ou não é do tipo escolhido.');
    }

    $calculado = raster_com_tempos($raster, $unidades);
    $ids = $calculado['unidade_por_celula'];
    unset($calculado['unidade_por_celula']);

    // máscara com 1 nas células de que esta unidade é a mais próxima
    $dela = array_map(function ($u) use ($id) {
        return $u === $id ? 1 : 0;
    }, $ids);

    $minhas = 0;
    $soma = 0;
    $pior = null;
    foreach ($dela as $k => $sim) {
        if (!$sim) {
            continue;
        }
        $minhas++;
        $soma += $calculado['valores'][$k];
        if ($pior === null || $calculado['valores'][$k] > $pior['minutos']) {
            $pior = [
                'lat' => $raster['pontos'][$k][0],
                'lon' => $raster['pontos'][$k][1],
                'minutos' => $calculado['valores'][$k],
            ];
        }
    }

    $passoKm = $raster['dLat'] * 111.0;

    // Quanta gente é que esta unidade serve na prática. "Serve 1.200 km²" não diz nada
    // a ninguém; "é o hospital mais próximo de 84 mil pessoas" diz tudo.
    $pessoas = null;
    if (ha_populacao()) {
        $pop = populacao_por_raster($raster);
        $servidas = 0;
        $servidas65 = 0;
        $somaPeso = 0.0;
        $pesoValido = 0;
        $forales = 0; // pessoas na zona que esta unidade não serve
        foreach ($pop['populacao'] as $k => $gente) {
            if ($gente <= 0) {
                continue;
            }
            if (empty($dela[$k])) {
                $forales += $gente;
                continue;
            }
            $servidas += $gente;
            $servidas65 += $pop['populacao_65'][$k];
            if (isset($calculado['valores'][$k]) && $calculado['valores'][$k] !== null) {
                $somaPeso += $gente * $calculado['valores'][$k];
                $pesoValido += $gente;
            }
        }
        $pessoas = [
            'servidas' => $servidas,
            'servidas_65' => $servidas65,
            'pct_65' => $servidas ? round(100 * $servidas65 / $servidas, 1) : null,
            'outras_na_zona' => $forales,
            'minutos_medios_por_pessoa' => $pesoValido ? round($somaPeso / $pesoValido, 1) : null,
        ];
    }

    return [
        'unidade' => ['id' => $alvo['id'], 'nome' => $alvo['nome'], 'lat' => $alvo['lat'], 'lon' => $alvo['lon']],
        'tipo' => $tipo,
        'raster' => juntar_populacao($calculado + ['dela' => $dela], $raster, $tipo),
        'resumo' => [
            'celulas_servidas' => $minhas,
            'celulas_total' => count($raster['indices_terra']),
            'area_km2' => round($minhas * $passoKm * $passoKm),
            'minutos_medios' => $minhas ? round($soma / $minhas, 1) : null,
            'ponto_mais_distante' => $pior,
            'pessoas' => $pessoas,
        ],
    ];
}

// ---------- onde falta uma unidade ----------

// Procura o melhor sítio para uma unidade nova dentro da zona indicada.
//
// Método: calcula os tempos atuais, toma como candidatos as quadrículas pior servidas
// (bem espaçadas entre si, para não testar três pontos da mesma aldeia) e, para cada
// candidato, recalcula os tempos como se existisse lá uma unidade. Fica o candidato que
// mais reduz o tempo médio. É uma heurística, não um ótimo, mas mostra ordens de grandeza.
function sugerir_local($tipo, $raster, $incluirSemTag)
{
    $unidades = unidades_do_tipo(obter_unidades()['unidades'], $tipo, $incluirSemTag);
    if (!$unidades) {
        throw new Exception('Não há unidades deste tipo nos dados.');
    }
    $indices = $raster['indices_terra'];
    if (count($indices) < 4) {
        throw new Exception('A zona quase não tem território português. Move o mapa.');
    }

    $antes = raster_com_tempos($raster, $unidades);
    $validos = array_filter($antes['valores'], function ($m) {
        return $m !== null;
    });
    if (!$validos) {
        throw new Exception('Nenhum ponto desta zona tem rota por estrada.');
    }
    [$limiarBom, $limiarDeserto] = cfg('limiares')[$tipo];
    $mediaAntes = array_sum($validos) / count($validos);
    $desertoAntes = count(array_filter($validos, function ($m) use ($limiarDeserto) {
        return $m > $limiarDeserto;
    }));

    // População por quadrícula. Com ela o método muda de natureza: deixa de procurar o
    // sítio mais longe e passa a procurar o sítio onde mais gente está mal servida.
    $temPop = ha_populacao();
    $pop = $temPop ? populacao_por_raster($raster) : null;
    $pessoas = $pop['populacao'] ?? [];
    $pessoas65 = $pop['populacao_65'] ?? [];

    $mediaPessoasAntes = null;
    $pessoasDesertoAntes = 0;
    $pessoasDesertoAntes65 = 0;
    $pessoasTotal = 0;
    if ($temPop) {
        $soma = 0.0;
        $peso = 0;
        foreach ($pessoas as $k => $gente) {
            if ($gente <= 0) {
                continue;
            }
            $pessoasTotal += $gente;
            $m = $antes['valores'][$k] ?? null;
            if ($m === null) {
                continue;
            }
            $soma += $gente * $m;
            $peso += $gente;
            if ($m > $limiarDeserto) {
                $pessoasDesertoAntes += $gente;
                $pessoasDesertoAntes65 += $pessoas65[$k];
            }
        }
        $mediaPessoasAntes = $peso ? $soma / $peso : null;
    }

    // Candidatos.
    //
    // Sem população: as quadrículas com pior tempo — o método antigo, que põe a unidade
    // no sítio mais remoto da zona ainda que lá não viva ninguém.
    //
    // Com população: as quadrículas com maior "peso do problema", que é gente a multiplicar
    // pelos minutos a mais do que o limiar. Uma aldeia de 800 pessoas a 70 minutos pesa
    // mais do que um monte de 4 pessoas a 95, e é onde uma unidade nova faz diferença.
    $ordem = [];
    foreach ($validos as $k => $m) {
        if ($temPop) {
            $gente = $pessoas[$k] ?? 0;
            $excesso = max(0, $m - $limiarBom);
            $ordem[$k] = $gente * $excesso;
        } else {
            $ordem[$k] = $m;
        }
    }
    $ordem = array_filter($ordem, function ($v) {
        return $v > 0;
    });
    if (!$ordem) {
        // toda a zona está dentro do limiar, ou não vive lá ninguém: cai no método antigo
        $ordem = $validos;
    }
    arsort($ordem);

    $passoKm = $raster['dLat'] * 111.0;
    $candidatos = [];
    foreach (array_keys($ordem) as $k) {
        [$lat, $lon] = $raster['pontos'][$k];
        $longe = true;
        foreach ($candidatos as $j) {
            if (distancia_km($lat, $lon, $raster['pontos'][$j][0], $raster['pontos'][$j][1]) < $passoKm * 2) {
                $longe = false;
                break;
            }
        }
        if ($longe) {
            $candidatos[] = $k;
        }
        if (count($candidatos) >= cfg('candidatos_planeamento')) {
            break;
        }
    }

    $melhor = null;
    foreach ($candidatos as $k) {
        [$lat, $lon] = $raster['pontos'][$k];
        $virtual = [
            'id' => 'proposta/' . $k,
            'nome' => 'Unidade proposta',
            'lat' => $lat,
            'lon' => $lon,
            'tipos' => [$tipo],
            'urgencia' => 'sim',
            'operador' => '',
            'localidade' => '',
        ];
        $depois = raster_com_tempos($raster, array_merge($unidades, [$virtual]));
        $validosDepois = array_filter($depois['valores'], function ($m) {
            return $m !== null;
        });
        if (!$validosDepois) {
            continue;
        }
        $media = array_sum($validosDepois) / count($validosDepois);

        // Critério de escolha: com população, ganham-se minutos A PESSOAS, e é isso que
        // se maximiza. Sem população, mantém-se a média por quadrícula.
        $minutosPoupados = 0.0;
        $mediaPessoas = null;
        $pessoasDeserto = 0;
        $pessoasDeserto65 = 0;
        $pessoasQueMelhoram = 0;
        if ($temPop) {
            $soma = 0.0;
            $peso = 0;
            foreach ($pessoas as $kk => $gente) {
                if ($gente <= 0) {
                    continue;
                }
                $md = $depois['valores'][$kk] ?? null;
                $ma = $antes['valores'][$kk] ?? null;
                if ($md === null) {
                    continue;
                }
                $soma += $gente * $md;
                $peso += $gente;
                if ($md > $limiarDeserto) {
                    $pessoasDeserto += $gente;
                    $pessoasDeserto65 += $pessoas65[$kk];
                }
                if ($ma !== null && $md < $ma - 0.05) {
                    $minutosPoupados += $gente * ($ma - $md);
                    $pessoasQueMelhoram += $gente;
                }
            }
            $mediaPessoas = $peso ? $soma / $peso : null;
        }

        $pontuacao = $temPop ? -$minutosPoupados : $media;
        if ($melhor === null || $pontuacao < $melhor['pontuacao']) {
            unset($depois['unidade_por_celula']);
            $melhor = [
                'lat' => $lat,
                'lon' => $lon,
                'pontuacao' => $pontuacao,
                'media' => $media,
                'media_pessoas' => $mediaPessoas,
                'minutos_poupados' => $minutosPoupados,
                'pessoas_que_melhoram' => $pessoasQueMelhoram,
                'pessoas_deserto' => $pessoasDeserto,
                'pessoas_deserto_65' => $pessoasDeserto65,
                'deserto' => count(array_filter($validosDepois, function ($m) use ($limiarDeserto) {
                    return $m > $limiarDeserto;
                })),
                'raster' => $depois,
            ];
        }
    }

    if ($melhor === null) {
        throw new Exception('Não foi possível avaliar locais nesta zona.');
    }

    $total = count($validos);
    $resposta = [
        'tipo' => $tipo,
        'local' => ['lat' => round($melhor['lat'], 5), 'lon' => round($melhor['lon'], 5)],
        'antes' => [
            'minutos_medios' => round($mediaAntes, 1),
            'deserto_pct' => round(($desertoAntes / $total) * 100),
        ],
        'depois' => [
            'minutos_medios' => round($melhor['media'], 1),
            'deserto_pct' => round(($melhor['deserto'] / $total) * 100),
        ],
        'ganho_minutos' => round($mediaAntes - $melhor['media'], 1),
        'candidatos_testados' => count($candidatos),
        'limiar_deserto' => $limiarDeserto,
        'tem_populacao' => $temPop,
        'raster' => juntar_populacao($melhor['raster'], $raster, $tipo),
    ];

    if ($temPop) {
        $saemDoDeserto = $pessoasDesertoAntes - $melhor['pessoas_deserto'];
        $resposta['populacao'] = [
            'total_na_zona' => $pessoasTotal,
            'minutos_medios_antes' => $mediaPessoasAntes === null ? null : round($mediaPessoasAntes, 1),
            'minutos_medios_depois' => $melhor['media_pessoas'] === null ? null : round($melhor['media_pessoas'], 1),
            'ganho_minutos_por_pessoa' => ($mediaPessoasAntes === null || $melhor['media_pessoas'] === null)
                ? null : round($mediaPessoasAntes - $melhor['media_pessoas'], 1),
            'pessoas_que_melhoram' => $melhor['pessoas_que_melhoram'],
            // soma de (pessoas x minutos poupados): a moeda em que se compara um local
            // com outro, e a que permite dizer "X horas poupadas por cada ida"
            'minutos_pessoa_poupados' => round($melhor['minutos_poupados']),
            'horas_pessoa_poupadas' => round($melhor['minutos_poupados'] / 60),
            'deserto_antes' => $pessoasDesertoAntes,
            'deserto_depois' => $melhor['pessoas_deserto'],
            'saem_do_deserto' => max(0, $saemDoDeserto),
            'deserto_antes_65' => $pessoasDesertoAntes65,
            'deserto_depois_65' => $melhor['pessoas_deserto_65'],
            'saem_do_deserto_65' => max(0, $pessoasDesertoAntes65 - $melhor['pessoas_deserto_65']),
        ];
    }

    return $resposta;
}

