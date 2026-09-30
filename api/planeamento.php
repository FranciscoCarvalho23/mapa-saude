<?php
// Onde falta uma unidade: sugere o melhor local para uma unidade nova na zona visível
require_once __DIR__ . '/../includes/analise.php';
iniciar_api(300);

$tipo = $_GET['tipo'] ?? '';
if (!in_array($tipo, tipos(), true)) {
    erro_json('Tipo de cuidado inválido.');
}

[[$sul, $oeste, $norte, $este], $passo] = limites_e_passo_do_pedido();
$raster = gerar_raster($sul, $oeste, $norte, $este, $passo);
if (!validar_raster($raster)) {
    erro_json('A zona é demasiado grande. Aproxima o mapa.');
}

responder_json(sugerir_local($tipo, $raster, ($_GET['sem_tag'] ?? '1') === '1') + ['passo_km' => $passo]);
