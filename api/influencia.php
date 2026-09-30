<?php
// Área de influência: que território é que esta unidade serve na prática
require_once __DIR__ . '/../includes/analise.php';
iniciar_api(180);

$id = trim($_GET['id'] ?? '');
$tipo = $_GET['tipo'] ?? '';
if ($id === '' || !in_array($tipo, tipos(), true)) {
    erro_json('Indica a unidade (id) e o tipo de cuidado.');
}

[[$sul, $oeste, $norte, $este], $passo] = limites_e_passo_do_pedido();
$raster = gerar_raster($sul, $oeste, $norte, $este, $passo);
if (!validar_raster($raster)) {
    erro_json('A zona é demasiado grande. Aproxima o mapa.');
}

responder_json(influencia_unidade($id, $tipo, $raster, ($_GET['sem_tag'] ?? '1') === '1') + ['passo_km' => $passo]);
