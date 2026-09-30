<?php
// Gera o mapa nacional de quadrículas (ficheiro estático em data/grelha_<tipo>.json)
//
// Uso: php scripts/gerar_grelha.php <urgencia|centro_saude|maternidade|todos> [passo_km] [regiao]
//   passo_km: tamanho da quadrícula (por defeito 5)
//   regiao:   continente, madeira, acores ou todas (por defeito)
//
// Com o servidor OSRM público, 5 km para o país inteiro são ~50 pedidos por tipo (1 por segundo).
// Para passos mais pequenos usa um OSRM próprio (ver README).
//
// Se existir data/populacao_pt.csv (ver scripts/preparar_populacao.php), cada raster
// leva também a população residente por quadrícula, e o ficheiro final passa a dizer
// quantas pessoas estão acima de cada limiar de tempo.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser corrido na linha de comandos.');
}

require_once __DIR__ . '/../includes/analise.php';
require_once __DIR__ . '/../includes/populacao.php';
set_time_limit(0);

// [sul, oeste, norte, este]
$REGIOES = [
    'continente' => [36.95, -9.55, 42.16, -6.18],
    'madeira' => [32.35, -17.30, 33.15, -16.20],
    'acores' => [36.90, -31.35, 39.80, -24.95],
];

$argTipo = $argv[1] ?? '';
$passo = (float) ($argv[2] ?? 5);
$argRegiao = $argv[3] ?? 'todas';

$listaTipos = $argTipo === 'todos' ? tipos() : [$argTipo];
$listaRegioes = $argRegiao === 'todas' ? array_keys($REGIOES) : [$argRegiao];

if (array_diff($listaTipos, tipos()) || array_diff($listaRegioes, array_keys($REGIOES)) || $passo < 1 || $passo > 50) {
    echo "Uso: php scripts/gerar_grelha.php <urgencia|centro_saude|maternidade|todos> [passo_km] [continente|madeira|acores|todas]\n";
    exit(1);
}

$dadosOsm = obter_unidades();
echo count($dadosOsm['unidades']) . ' unidades, dados de ' . $dadosOsm['atualizado_em'] . "\n";

$comPopulacao = ha_populacao();
echo $comPopulacao
    ? "População: data/populacao_pt.csv encontrado.\n"
    : "População: sem data/populacao_pt.csv — as grelhas saem só com tempos.\n";

foreach ($listaTipos as $tipo) {
    $unidades = unidades_do_tipo($dadosOsm['unidades'], $tipo, cfg('urgencia_inclui_sem_tag'));
    echo "\n$tipo: " . count($unidades) . " unidades\n";

    // Um raster por região: juntar o continente e os Açores num só retângulo daria
    // uma grelha quase toda oceano Atlântico.
    $rasters = [];
    $valoresPorRegiao = [];
    $popPorRegiao = [];

    foreach ($listaRegioes as $regiao) {
        [$sul, $oeste, $norte, $este] = $REGIOES[$regiao];
        $raster = gerar_raster($sul, $oeste, $norte, $este, $passo);
        $emTerra = count($raster['indices_terra']);
        echo "  $regiao: {$raster['largura']}x{$raster['altura']} pontos, $emTerra em terra\n";

        // em lotes, só para ir mostrando o progresso
        $valores = array_fill(0, $raster['largura'] * $raster['altura'], null);
        $lotes = array_chunk($raster['indices_terra'], 200);
        foreach ($lotes as $n => $lote) {
            $pontos = array_map(function ($k) use ($raster) {
                return $raster['pontos'][$k];
            }, $lote);
            $tempos = minutos_para_pontos($pontos, $unidades);
            foreach ($lote as $i => $k) {
                $valores[$k] = $tempos[$i];
            }
            echo '    ' . min(($n + 1) * 200, $emTerra) . "/$emTerra\n";
        }

        $publico = raster_publico($raster, $valores) + ['regiao' => $regiao];

        if ($comPopulacao) {
            $pop = populacao_por_raster($raster);
            $publico['populacao'] = $pop['populacao'];
            $publico['populacao_65'] = $pop['populacao_65'];
            $popPorRegiao[$regiao] = $pop;
            printf("    população: %s nesta região (%s encostadas à costa, %s sem quadrícula)\n",
                number_format($pop['resumo']['dentro'] + $pop['resumo']['encostada']),
                number_format($pop['resumo']['encostada']),
                number_format($pop['resumo']['perdida']));
        }

        $valoresPorRegiao[$regiao] = $valores;
        $rasters[] = $publico;
    }

    $ficheiro = __DIR__ . "/../data/grelha_$tipo.json";
    $conteudo = [
        'tipo' => $tipo,
        'passo_km' => $passo,
        'gerado_em' => date('c'),
        'dados_osm_em' => $dadosOsm['atualizado_em'],
        'urgencia_inclui_sem_tag' => cfg('urgencia_inclui_sem_tag'),
        'rasters' => $rasters,
    ];

    // Resumo populacional somado às regiões geradas nesta corrida.
    if ($comPopulacao) {
        $limiares = cfg('limiares')[$tipo] ?? [30, 60];
        $soma = ['total' => 0, 'total_65' => 0, 'sem_tempo' => 0, 'acima_de' => [], 'acima_de_65' => []];
        foreach ($limiares as $m) {
            $soma['acima_de'][(string) $m] = 0;
            $soma['acima_de_65'][(string) $m] = 0;
        }

        foreach ($listaRegioes as $regiao) {
            $r = pessoas_por_limiar(
                $valoresPorRegiao[$regiao],
                $popPorRegiao[$regiao]['populacao'],
                $popPorRegiao[$regiao]['populacao_65'],
                $limiares
            );
            $soma['total'] += $r['total'];
            $soma['total_65'] += $r['total_65'];
            $soma['sem_tempo'] += $r['sem_tempo'];
            foreach ($limiares as $m) {
                $soma['acima_de'][(string) $m] += $r['acima_de'][(string) $m];
                $soma['acima_de_65'][(string) $m] += $r['acima_de_65'][(string) $m];
            }
        }

        $conteudo['populacao'] = $soma + [
            'limiares' => $limiares,
            'regioes' => $listaRegioes,
            'fonte' => 'Eurostat, Census 2021 population grid (© União Europeia, 2026); '
                . 'filtrado para Portugal, reprojetado e agregado por este projeto',
        ];

        echo "\n  --- pessoas e tempo até $tipo ---\n";
        printf("  População coberta:   %s (%s com 65+)\n",
            number_format($soma['total']), number_format($soma['total_65']));
        foreach ($limiares as $m) {
            $n = $soma['acima_de'][(string) $m];
            printf("  A mais de %2d min:    %s (%.1f%%), das quais %s com 65+\n",
                $m, number_format($n),
                $soma['total'] ? 100 * $n / $soma['total'] : 0,
                number_format($soma['acima_de_65'][(string) $m]));
        }
        if ($soma['sem_tempo'] > 0) {
            printf("  Sem tempo calculado: %s — declarar no relatório, não esconder\n",
                number_format($soma['sem_tempo']));
        }
    }

    file_put_contents($ficheiro, json_encode($conteudo));
    @chmod($ficheiro, 0666);
    echo "\n  guardado em data/grelha_$tipo.json ("
        . number_format(filesize($ficheiro) / 1048576, 1) . " MB)\n";
}