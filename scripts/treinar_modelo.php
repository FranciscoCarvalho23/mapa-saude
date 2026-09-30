<?php
// Treina o modelo substituto de tempo de viagem a partir de um mapa nacional já gerado.
//
// Uso: php scripts/treinar_modelo.php <urgencia|centro_saude|maternidade|todos>
//
// Escreve data/modelo_tempo_<tipo>.json. É o modelo que permite ao otimizador avaliar
// dezenas de milhares de configurações sem tocar no OSRM.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser corrido na linha de comandos.');
}

require_once __DIR__ . '/../includes/modelo.php';
set_time_limit(0);

$argTipo = $argv[1] ?? '';
$lista = $argTipo === 'todos' ? tipos() : [$argTipo];
if (array_diff($lista, tipos())) {
    echo "Uso: php scripts/treinar_modelo.php <urgencia|centro_saude|maternidade|todos>\n";
    exit(1);
}

foreach ($lista as $tipo) {
    echo "\n=== $tipo ===\n";
    try {
        $modelo = treinar_modelo($tipo);
    } catch (Exception $e) {
        echo '  ' . $e->getMessage() . "\n";
        continue;
    }

    echo '  ' . $modelo['metodo'] . "\n";
    printf("  referência global: minutos ≈ %.2f + %.3f·d  (%.0f km/h efetivos)\n",
        $modelo['global'][0], $modelo['global'][1], $modelo['base_global']['km_por_hora']);
    echo "  {$modelo['pontos']} quadrículas com tempo do OSRM, {$modelo['unidades_no_treino']} unidades\n";
    echo "  treino: {$modelo['pontos_treino']} | teste: {$modelo['pontos_teste']} (nunca vistos)\n";

    $t = $modelo['avaliacao'];
    $b = $modelo['base_global'];
    echo "\n  --- no conjunto de teste ---\n";
    printf("                        modelo local    reta global\n");
    printf("  erro médio absoluto:  %6.2f min      %6.2f min\n", $t['mae'], $b['mae']);
    printf("  RMSE:                 %6.2f min      %6.2f min\n", $t['rmse'], $b['rmse']);
    printf("  R²:                   %6.4f          %6.4f\n", $t['r2'], $b['r2']);
    printf("  erro até 5 min:       %5.1f%%\n", $t['pct_erro_ate_5min']);

    $melhoria = $b['mae'] > 0 ? 100 * (1 - $t['mae'] / $b['mae']) : 0;
    printf("\n  A regressão local erra menos %.0f%% do que uma reta única para o país.\n", $melhoria);
    printf("  Tempo médio real das quadrículas de teste: %.1f min.\n", $t['minutos_medios']);

    $f = guardar_modelo($modelo);
    echo '  guardado em data/' . basename($f) . "\n";
}

echo "\nSegue-se: php scripts/otimizar.php <tipo> <quantas unidades>\n";
