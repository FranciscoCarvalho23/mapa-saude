<?php
// Tempo de viagem de um ponto até à urgência, centro de saúde e maternidade mais próximos
require_once __DIR__ . '/../includes/analise.php';
iniciar_api(60);

$lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lon = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT);

if ($lat === false || $lon === false) {
    erro_json('Coordenadas inválidas.');
}
// retângulo que cobre o continente, a Madeira e os Açores
if ($lat < 32.3 || $lat > 42.2 || $lon < -31.5 || $lon > -6.1) {
    erro_json('O ponto escolhido está fora de Portugal.');
}

$incluirSemTag = ($_GET['sem_tag'] ?? '1') === '1';

header('Cache-Control: no-store');
responder_json(analisar_ponto($lat, $lon, $incluirSemTag));
