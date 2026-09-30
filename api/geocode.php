<?php
// Pesquisa de moradas (?q=) e morada de um ponto (?lat=&lon=) através do Nominatim
require_once __DIR__ . '/../includes/funcoes.php';
iniciar_api(30);

$pesquisa = isset($_GET['q']);

if ($pesquisa) {
    $q = trim($_GET['q']);
    if (strlen($q) < 3) {
        erro_json('Escreve pelo menos 3 caracteres.');
    }
    $url = cfg('nominatim_url') . '/search?' . http_build_query([
        'q' => $q,
        'format' => 'jsonv2',
        'countrycodes' => 'pt',
        'limit' => 5,
    ]);
} elseif (isset($_GET['lat'], $_GET['lon'])) {
    $url = cfg('nominatim_url') . '/reverse?' . http_build_query([
        'lat' => round((float) $_GET['lat'], 4), // arredondar aumenta os acertos na cache
        'lon' => round((float) $_GET['lon'], 4),
        'format' => 'jsonv2',
        'zoom' => 17,
    ]);
} else {
    erro_json('Parâmetros em falta: q, ou lat e lon.');
}

$dados = cache_ler($url, cfg('cache_ttl_geocode'));
if ($dados === null) {
    esperar_vez('nominatim', cfg('intervalo_nominatim'));
    $json = http_json($url);

    if ($pesquisa) {
        $dados = [];
        foreach ($json as $r) {
            $dados[] = ['nome' => $r['display_name'], 'lat' => (float) $r['lat'], 'lon' => (float) $r['lon']];
        }
    } else {
        $dados = ['nome' => $json['display_name'] ?? null]; // no mar o Nominatim devolve só "error"
    }
    cache_guardar($url, $dados);
}

responder_json($dados);
