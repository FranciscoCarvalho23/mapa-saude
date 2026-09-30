<?php
// Todas as unidades de saúde, a partir de data/unidades.json.
//
// Uma resposta, um ficheiro. Abrem e fecham poucas unidades por ano, por isso os dados são
// gerados de vez em quando por scripts/atualizar_unidades.php em vez de virem do Overpass
// a cada visita. É isto que faz a página arrancar de imediato.
require_once __DIR__ . '/../includes/dados_osm.php';
iniciar_api(30);

$dados = obter_unidades();

// o ficheiro só muda quando alguém corre o script: pode ficar em cache no browser
$etag = '"' . md5($dados['atualizado_em'] . $dados['total']) . '"';
header("ETag: $etag");
header('Cache-Control: public, max-age=3600');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

responder_json($dados);
