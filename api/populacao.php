<?php
// Quem é que fica de fora: leitura do acesso em pessoas, não em área.
//
// Serve o painel "Pessoas" e responde a três perguntas diferentes:
//   ?accao=resumo    [&tipo=...]              quantas pessoas em cada classe de acesso
//   ?accao=piores    &tipo=...  [&quantas=N]  as manchas onde mais gente vive longe
//   ?accao=percentil &tipo=...  &minutos=N    em que posição do país fica este tempo
//
// Tudo isto sai dos mapas nacionais já gerados (data/grelha_<tipo>.json), por isso é
// instantâneo e não toca no OSRM.
require_once __DIR__ . '/../includes/analise.php';
iniciar_api(60);

if (!ha_populacao()) {
    erro_json('Ainda não há dados de população. No servidor, corre: '
        . 'php scripts/preparar_populacao.php <ESTAT_Census_2021_V3.csv>', 503);
}

$accao = $_GET['accao'] ?? 'resumo';
$tipo = $_GET['tipo'] ?? '';

if ($accao !== 'resumo' && !in_array($tipo, tipos(), true)) {
    erro_json('Tipo de cuidado inválido.');
}

// cabeçalho comum: a fonte tem de viajar com os números, para poderem ser citados
$fonte = [
    'populacao' => 'Eurostat, Census 2021 population grid (© União Europeia, 2026), '
        . 'filtrado para Portugal, reprojetado e agregado por este projeto',
    'unidades' => 'OpenStreetMap (ODbL 1.0), com correções manuais a partir do Portal do SNS',
    'tempos' => 'OSRM sobre a rede de estradas do OpenStreetMap, sem trânsito',
];

if ($accao === 'piores') {
    $quantas = (int) ($_GET['quantas'] ?? 15);
    $quantas = max(1, min(50, $quantas));
    // 'pessoas' = onde vive mais gente longe; 'criticidade' = onde o problema pesa mais
    // (pessoas x minutos a mais). São perguntas diferentes e dão listas diferentes.
    $ordenar = ($_GET['ordenar'] ?? 'pessoas') === 'criticidade' ? 'criticidade' : 'pessoas';
    $lista = piores_zonas($tipo, $quantas, $ordenar);
    if ($lista === null) {
        erro_json("Falta o mapa nacional de «$tipo» com população. No servidor, corre: "
            . "php scripts/gerar_grelha.php $tipo", 503);
    }
    responder_json([
        'tipo' => $tipo,
        'limiar' => cfg('limiares')[$tipo][1],
        'ordenado_por' => $ordenar,
        'zonas' => $lista,
        'fonte' => $fonte,
    ]);
}

if ($accao === 'percentil') {
    $minutos = filter_var($_GET['minutos'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($minutos === false || $minutos < 0) {
        erro_json('Indica os minutos.');
    }
    $p = percentil_acesso($tipo, $minutos);
    if ($p === null) {
        erro_json("Falta o mapa nacional de «$tipo» com população.", 503);
    }
    responder_json(['tipo' => $tipo] + $p + ['fonte' => $fonte]);
}

// resumo: um tipo, ou os três de uma vez
$lista = $tipo !== '' && in_array($tipo, tipos(), true) ? [$tipo] : tipos();
$saida = [];
$emFalta = [];
foreach ($lista as $t) {
    $r = resumo_nacional($t);
    if ($r === null) {
        $emFalta[] = $t;
        continue;
    }
    $saida[$t] = $r;
}

if (!$saida) {
    erro_json('Ainda não há mapas nacionais com população. No servidor, corre: '
        . 'php scripts/gerar_grelha.php todos 5', 503);
}

responder_json([
    'tipos' => $saida,
    'em_falta' => $emFalta,
    'fonte' => $fonte,
]);
