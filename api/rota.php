<?php
// Percurso entre dois pontos, com instruções passo a passo
require_once __DIR__ . '/../includes/osrm.php';
iniciar_api(60);

$valores = [];
foreach (['lat1', 'lon1', 'lat2', 'lon2'] as $campo) {
    $valores[$campo] = filter_var($_GET[$campo] ?? null, FILTER_VALIDATE_FLOAT);
    if ($valores[$campo] === false) {
        erro_json("Parâmetro $campo inválido.");
    }
}

responder_json(osrm_rota($valores['lat1'], $valores['lon1'], $valores['lat2'], $valores['lon2']));
