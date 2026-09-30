<?php
// Análise de zona: tempo até à unidade mais próxima, em grelha, para desenhar manchas
require_once __DIR__ . '/../includes/analise.php';
iniciar_api(180);

$tipo = $_GET['tipo'] ?? '';
if (!in_array($tipo, tipos(), true)) {
    erro_json('Tipo de cuidado inválido.');
}

[[$sul, $oeste, $norte, $este], $passo] = limites_e_passo_do_pedido();

$unidades = unidades_do_tipo(obter_unidades()['unidades'], $tipo, ($_GET['sem_tag'] ?? '1') === '1');
if (!$unidades) {
    erro_json('Não há unidades deste tipo nos dados.');
}

$raster = gerar_raster($sul, $oeste, $norte, $este, $passo);
if (!validar_raster($raster)) {
    erro_json('A zona é demasiado grande para calcular. Aproxima o mapa.');
}

$calculado = raster_com_tempos($raster, $unidades);
unset($calculado['unidade_por_celula']);

responder_json(juntar_populacao($calculado, $raster, $tipo)
    + ['tipo' => $tipo, 'passo_km' => $passo]);
