<?php
// Procura os melhores locais para N unidades novas, no país inteiro.
//
// Uso: php scripts/otimizar.php <tipo> [quantas] [iteracoes] [reinicios]
//   Ex.: php scripts/otimizar.php urgencia 5
//
// Escreve data/otimizacao_<tipo>_<n>.json, que a página lê de imediato.
// Precisa do mapa nacional (gerar_grelha.php) e do modelo (treinar_modelo.php).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser corrido na linha de comandos.');
}

require_once __DIR__ . '/../includes/otimizacao.php';
set_time_limit(0);

$tipo = $argv[1] ?? '';
$quantas = (int) ($argv[2] ?? 3);
$iteracoes = (int) ($argv[3] ?? 12000);
$reinicios = (int) ($argv[4] ?? 5);

if (!in_array($tipo, tipos(), true) || $quantas < 1) {
    echo "Uso: php scripts/otimizar.php <urgencia|centro_saude|maternidade> [quantas] [iteracoes] [reinicios]\n";
    exit(1);
}

echo "A otimizar $quantas unidades de $tipo em Portugal inteiro.\n";
echo "Arrefecimento simulado: $iteracoes iterações × $reinicios reinícios.\n\n";

$r = otimizar_localizacoes($tipo, $quantas, [
    'iteracoes' => $iteracoes,
    'reinicios' => $reinicios,
    'segundos_max' => 600,
]);

printf("Avaliadas %s configurações em %s s.\n",
    number_format($r['execucao']['avaliacoes']), $r['execucao']['segundos']);
printf("Ganho por reinício: %s\n", implode(' | ', array_map(function ($g) {
    return number_format($g / 60) . ' h';
}, $r['execucao']['ganho_por_reinicio'])));

echo "\n--- Locais propostos ---\n";
foreach ($r['locais'] as $l) {
    printf("  %d. %8.5f, %9.5f   serve %s pessoas (%s com 65+)   hoje a %s min\n",
        $l['ordem'], $l['lat'], $l['lon'],
        number_format($l['pessoas_servidas']), number_format($l['pessoas_servidas_65']),
        $l['minutos_hoje'] === null ? '?' : round($l['minutos_hoje']));
}

echo "\n--- O que muda ---\n";
printf("  tempo médio por pessoa:   %5.1f -> %5.1f min\n",
    $r['antes']['minutos_medios'], $r['depois']['minutos_medios']);
printf("  em deserto de saúde:      %s -> %s pessoas\n",
    number_format($r['antes']['deserto']), number_format($r['depois']['deserto']));
printf("  saem do deserto:          %s (%s com 65+)\n",
    number_format($r['ganho']['saem_do_deserto']), number_format($r['ganho']['saem_do_deserto_65']));
printf("  pessoas que melhoram:     %s\n", number_format($r['ganho']['pessoas_que_melhoram']));
printf("  horas-pessoa poupadas:    %s (%.1f%% do total)\n",
    number_format($r['ganho']['horas_pessoa_poupadas']), $r['ganho']['pct_pessoas_minuto']);

echo "\n--- Confirmação com o OSRM ---\n";
try {
    $c = confirmar_com_osrm($tipo, $r['locais']);
    $r['confirmacao'] = $c;
    if ($c['amostra']) {
        printf("  %d quadrículas testadas: erro médio %.2f min, RMSE %.2f min\n",
            $c['amostra'], $c['mae'], $c['rmse']);
        echo "  previsto vs real: ";
        echo implode(', ', array_map(function ($e) {
            return $e['previsto'] . '/' . $e['real'];
        }, array_slice($c['exemplos'], 0, 5))) . "\n";
    } else {
        echo '  ' . $c['nota'] . "\n";
    }
} catch (Exception $e) {
    echo '  não foi possível confirmar: ' . $e->getMessage() . "\n";
    echo "  (o resultado fica guardado à mesma, marcado como por confirmar)\n";
    $r['confirmacao'] = ['erro' => $e->getMessage()];
}

$ficheiro = __DIR__ . "/../data/otimizacao_{$tipo}_{$quantas}.json";
file_put_contents($ficheiro, json_encode($r, JSON_UNESCAPED_UNICODE));
@chmod($ficheiro, 0666);
echo "\nGuardado em data/" . basename($ficheiro) . "\n";
