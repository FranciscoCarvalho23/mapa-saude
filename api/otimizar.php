<?php
// Onde construir: procura os melhores locais para N unidades novas em Portugal inteiro.
//
//   ?tipo=urgencia&quantas=5[&objetivo=tempo|deserto][&confirmar=1]
//
// Usa o modelo substituto (includes/modelo.php) para avaliar milhares de configurações
// sem tocar no OSRM. O resultado fica em cache, porque o cálculo é determinístico para
// os mesmos parâmetros e a mesma grelha.
require_once __DIR__ . '/../includes/otimizacao.php';
iniciar_api(120);

$tipo = $_GET['tipo'] ?? '';
if (!in_array($tipo, tipos(), true)) {
    erro_json('Tipo de cuidado inválido.');
}
if (!ha_modelo($tipo)) {
    erro_json("Falta o modelo de tempos para «$tipo». No servidor, corre: "
        . "php scripts/treinar_modelo.php $tipo", 503);
}

$quantas = max(1, min(8, (int) ($_GET['quantas'] ?? 3)));
$objetivo = ($_GET['objetivo'] ?? 'tempo') === 'deserto' ? 'deserto' : 'tempo';
$confirmar = ($_GET['confirmar'] ?? '0') === '1';

// Um resultado calculado com calma pela linha de comandos ganha sempre ao que se calcula
// à pressa num pedido web — mais iterações, mais reinícios, e já confirmado com o OSRM.
$doDisco = __DIR__ . "/../data/otimizacao_{$tipo}_{$quantas}.json";
if ($objetivo === 'tempo' && !$confirmar && is_readable($doDisco)) {
    $guardado = json_decode(file_get_contents($doDisco), true);
    if (is_array($guardado) && !empty($guardado['locais'])) {
        responder_json($guardado + ['origem' => 'pré-calculado no servidor']);
    }
}

$grelha = grelha_nacional($tipo);
$chave = "otimizacao-$tipo-$quantas-$objetivo-" . ($grelha['gerado_em'] ?? '');
$resultado = cache_ler($chave, 30 * 24 * 3600);

if ($resultado === null) {
    $resultado = otimizar_localizacoes($tipo, $quantas, [
        'objetivo' => $objetivo,
        'iteracoes' => 5000,
        'reinicios' => 3,
        'segundos_max' => 40,   // o pedido tem de responder; o CLI é que faz a busca longa
    ]);
    $resultado['origem'] = 'calculado agora';
    cache_guardar($chave, $resultado);
}

// A confirmação com o OSRM é opcional porque é lenta e gasta pedidos: é a prova de que o
// modelo não mentiu, e só faz sentido pedi-la quando o resultado vai ser usado a sério.
if ($confirmar && empty($resultado['confirmacao'])) {
    try {
        $resultado['confirmacao'] = confirmar_com_osrm($tipo, $resultado['locais']);
        cache_guardar($chave, $resultado);
    } catch (Exception $e) {
        $resultado['confirmacao'] = ['erro' => $e->getMessage()];
    }
}

responder_json($resultado);
