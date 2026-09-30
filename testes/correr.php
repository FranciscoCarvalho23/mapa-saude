<?php
// Testes do carregamento por blocos. Correm contra o servidor falso (testes/falso.php).
//
// Uso:  php testes/correr.php
// (arranca e desliga o servidor falso e o servidor da aplicação sozinho)

// Funciona com a pasta testes/ dentro do projeto ou ao lado dele.
$RAIZ = is_file(dirname(__DIR__) . '/index.php') ? dirname(__DIR__) : dirname(__DIR__) . '/mapa-saude';
$APP = 'http://127.0.0.1:8098';
$FALSO = '127.0.0.1:8099';

$ok = 0;
$falhas = [];

function verificar($descricao, $condicao, $detalhe = '')
{
    global $ok, $falhas;
    if ($condicao) {
        $ok++;
        echo "  ok   $descricao\n";
    } else {
        $falhas[] = $descricao . ($detalhe ? " ($detalhe)" : '');
        echo "  FALHA $descricao" . ($detalhe ? " -> $detalhe" : '') . "\n";
    }
}

function get($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
    $corpo = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode($corpo, true), $corpo];
}

function estadoFalso($estado)
{
    file_put_contents(__DIR__ . '/falso_estado.json', json_encode($estado));
    usleep(150000);
}

function limparCache()
{
    global $RAIZ;
    foreach (glob("$RAIZ/cache/*") as $f) {
        if (is_file($f) && basename($f) !== '.htaccess') {
            unlink($f);
        }
    }
}

function pedidosOverpass()
{
    $log = __DIR__ . '/falso_pedidos.log';
    return is_file($log) ? array_values(array_filter(explode("\n", file_get_contents($log)))) : [];
}

// ordena uma cópia, para comparar sem mexer no original
function array_sort_copia($a)
{
    sort($a);
    return $a;
}

function limparLog()
{
    @unlink(__DIR__ . '/falso_pedidos.log');
}

// ---------- arranque ----------

file_put_contents("$RAIZ/config.local.php", "<?php\nreturn " . var_export([
    'overpass_urls' => ["http://$FALSO/api/interpreter"],
    'nominatim_url' => "http://$FALSO",
    'osrm_url' => "http://$FALSO",
    'intervalo_overpass' => 0.0,
    'intervalo_osrm' => 0.0,
    'intervalo_nominatim' => 0.0,
    'overpass_espera' => 1,
    'cache_ttl_bloco_falha' => 3,
    'overpass_descansos' => ['recusado' => 2, 'sobrecarregado' => 2, 'sem_ligacao' => 2],
], true) . ";\n");

// Os testes escrevem por cima de data/unidades.json e data/correcoes.json com dados
// falsos. Sem isto, correr os testes apagava os dados reais de quem os corre — já
// aconteceu. Guardam-se aqui e repõem-se no fim, aconteça o que acontecer.
$GUARDADOS = [];
foreach (["$RAIZ/data/unidades.json", "$RAIZ/data/correcoes.json"] as $f) {
    if (is_file($f)) {
        $GUARDADOS[$f] = file_get_contents($f);
    }
}

@mkdir("$RAIZ/cache", 0777, true);
@chmod("$RAIZ/cache", 0777);
estadoFalso([]);
limparLog();
limparCache();

$pFalso = proc_open("php -S $FALSO " . escapeshellarg(__DIR__ . '/falso.php'), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubos);
$pApp = proc_open('php -S 127.0.0.1:8098 -t ' . escapeshellarg($RAIZ), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubos2);
sleep(2);

register_shutdown_function(function () use ($pFalso, $pApp, $RAIZ, $GUARDADOS) {
    proc_terminate($pFalso);
    proc_terminate($pApp);
    @unlink("$RAIZ/config.local.php");
    foreach ($GUARDADOS as $ficheiro => $conteudo) {
        file_put_contents($ficheiro, $conteudo);
        @chmod($ficheiro, 0666);
    }
    // ficheiros que os testes criaram e que não existiam antes
    foreach (["$RAIZ/data/unidades.json", "$RAIZ/data/correcoes.json"] as $f) {
        if (!isset($GUARDADOS[$f]) && is_file($f)) {
            @unlink($f);
        }
    }
});

echo "\n== 1. Blocos (usados só pelo script de atualização) ==\n";
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --lista-blocos 2>&1');
preg_match('/(\d+) blocos com território/', $saida, $m);
verificar('conta ~78 blocos com território português', isset($m[1]) && $m[1] > 60 && $m[1] < 100, $m[1] ?? $saida);
verificar('inclui o bloco do Porto', strpos($saida, '-18_82') !== false);
verificar('inclui a Madeira', strpos($saida, '-34_65') !== false);

$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --query -18_82 2>&1');
verificar('--query mostra a query com caixa', strpos($saida, '41,-9,41.5,-8.5') !== false, substr($saida, 0, 150));
verificar('--query sem area nem regex de nome', strpos($saida, 'area[') === false && strpos($saida, 'name"~') === false);

echo "\n== 2. Gerar data/unidades.json ==\n";
@unlink("$RAIZ/data/unidades.json");
limparCache();
limparLog();
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php 2>&1');
verificar('o script grava o ficheiro', strpos($saida, 'Gravado data/unidades.json') !== false, substr($saida, -180));
verificar('usa uma query só para o pais', count(pedidosOverpass()) === 1, implode(',', pedidosOverpass()));
verificar('a query do pais cobre as 3 regioes', substr_count(shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --query'), '36.9,-9.6') > 0
    && strpos(shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --query'), '-17.35') !== false);

// se a query única falhar, cai para os blocos
@unlink("$RAIZ/data/unidades.json");
limparCache();
limparLog();
estadoFalso(['falhar_pais' => true]);
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php 2>&1');
verificar('sem a query unica, cai para os blocos', strpos($saida, 'Vou tentar bloco a bloco') !== false, substr($saida, 0, 200));
verificar('e grava na mesma', strpos($saida, 'Gravado data/unidades.json') !== false, substr($saida, -160));
verificar('fez os ~78 pedidos de blocos', count(pedidosOverpass()) > 60, count(pedidosOverpass()) . ' pedidos');
estadoFalso([]);
$ficheiro = json_decode(file_get_contents("$RAIZ/data/unidades.json"), true);
verificar('ficheiro com unidades', !empty($ficheiro['unidades']), count($ficheiro['unidades'] ?? []) . ' unidades');
verificar('ficheiro com data e licenca', !empty($ficheiro['atualizado_em']) && strpos($ficheiro['licenca'], 'ODbL') !== false);
verificar('unidade com os campos esperados', !array_diff(['id', 'nome', 'lat', 'lon', 'tipos', 'urgencia'], array_keys($ficheiro['unidades'][0])));

echo "\n== 2b. Rotação de servidores ==\n";
// o 1.º servidor está morto: os outros têm de ser usado na mesma (era o bug do log de 19/09)
file_put_contents("$RAIZ/config.local.php", "<?php\nreturn " . var_export([
    'overpass_urls' => ['http://127.0.0.1:8097/api/interpreter', "http://$FALSO/api/interpreter"],
    'nominatim_url' => "http://$FALSO",
    'osrm_url' => "http://$FALSO",
    'intervalo_overpass' => 0.0,
    'intervalo_osrm' => 0.0,
    'intervalo_nominatim' => 0.0,
    'overpass_espera' => 1,
    'overpass_descansos' => ['recusado' => 2, 'sobrecarregado' => 2, 'sem_ligacao' => 2],
], true) . ";\n");
@unlink("$RAIZ/data/unidades.json");
limparCache();
limparLog();
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php 2>&1');
verificar('recupera pelo 2.o servidor', strpos($saida, 'Gravado data/unidades.json') !== false, substr($saida, -160));
verificar('o servidor morto nao impede os outros', count(pedidosOverpass()) >= 1, count(pedidosOverpass()) . ' pedidos');

// todos mortos: falha depressa, sem percorrer os 78 blocos
file_put_contents("$RAIZ/config.local.php", "<?php\nreturn " . var_export([
    'overpass_urls' => ['http://127.0.0.1:8097/api/interpreter', 'http://127.0.0.1:8096/api/interpreter'],
    'nominatim_url' => "http://$FALSO",
    'osrm_url' => "http://$FALSO",
    'intervalo_overpass' => 0.0,
    'overpass_espera' => 1,
    'overpass_descansos' => ['recusado' => 2, 'sobrecarregado' => 2, 'sem_ligacao' => 2],
], true) . ";\n");
limparCache();
$t0 = microtime(true);
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --blocos-so 2>&1');
$demora = microtime(true) - $t0;
verificar('com tudo em baixo para a ronda cedo', strpos($saida, 'Todos os servidores estão em espera') !== false, substr($saida, 0, 200));
verificar('nao percorre os 78 blocos as cegas', $demora < 45, sprintf('%.1f s', $demora));
verificar('explica o caminho manual', strpos($saida, 'overpass-turbo.eu') !== false);
verificar('nao estraga o ficheiro existente', strpos($saida, 'não foi alterado') !== false);

// repor a configuração normal
file_put_contents("$RAIZ/config.local.php", "<?php\nreturn " . var_export([
    'overpass_urls' => ["http://$FALSO/api/interpreter"],
    'nominatim_url' => "http://$FALSO",
    'osrm_url' => "http://$FALSO",
    'intervalo_overpass' => 0.0,
    'intervalo_osrm' => 0.0,
    'intervalo_nominatim' => 0.0,
    'overpass_espera' => 1,
    'cache_ttl_bloco_falha' => 3,
    'overpass_descansos' => ['recusado' => 2, 'sobrecarregado' => 2, 'sem_ligacao' => 2],
], true) . ";\n");

echo "\n== 2c. Importar um export do Overpass Turbo ==\n";
$exportacao = sys_get_temp_dir() . '/export-turbo-teste.json';
$ch = curl_init("http://$FALSO/api/interpreter");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['data' => shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --query')])]);
file_put_contents($exportacao, curl_exec($ch));
curl_close($ch);
limparCache();
@unlink("$RAIZ/data/unidades.json");
limparLog();
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php ' . escapeshellarg($exportacao) . ' 2>&1');
verificar('importa o ficheiro', strpos($saida, 'Gravado data/unidades.json') !== false, substr($saida, -160));
verificar('sem tocar na rede', count(pedidosOverpass()) === 0, implode(',', pedidosOverpass()));
$importado = json_decode(file_get_contents("$RAIZ/data/unidades.json"), true);
verificar('marca a origem como importado', strpos($importado['origem'], 'Overpass Turbo') !== false, $importado['origem']);
verificar('importou unidades a serio', count($importado['unidades']) > 100, count($importado['unidades']) . ' unidades');
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && echo \'{"foo":1}\' > /tmp/mau-teste.json && php scripts/atualizar_unidades.php /tmp/mau-teste.json 2>&1');
verificar('ficheiro errado explica o que fazer', strpos($saida, 'dados OSM em bruto') !== false, substr($saida, 0, 120));

echo "\n== 3. Nada toca no Overpass depois disso ==\n";
limparLog();
[$c, $r] = get("$APP/api/unidades.php");
verificar('unidades responde 200', $c === 200 && !empty($r['unidades']), "codigo $c");
verificar('nao vai ao Overpass', count(pedidosOverpass()) === 0, implode(',', pedidosOverpass()));
$t0 = microtime(true);
[$c, $r] = get("$APP/api/tempos.php?lat=41.15&lon=-8.61&sem_tag=1");
$demora = microtime(true) - $t0;
verificar('analise responde em menos de 3 s', $c === 200 && $demora < 3.0, sprintf('%.2f s, codigo %d', $demora, $c));
verificar('analise sem Overpass', count(pedidosOverpass()) === 0);
verificar('tem os tres tipos', isset($r['resultados']['urgencia'], $r['resultados']['centro_saude'], $r['resultados']['maternidade']));
verificar('ate 3 opcoes por tipo', count($r['resultados']['urgencia']) <= 3);
verificar('ordenado por tempo', $r['resultados']['urgencia'][0]['minutos'] <= ($r['resultados']['urgencia'][1]['minutos'] ?? INF));
verificar('ja nao aceita excluir (simulacao removida)', !isset($r['excluidas']));

echo "\n== 4. Cache do browser (ETag) ==\n";
$ch = curl_init("$APP/api/unidades.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$resposta = curl_exec($ch);
curl_close($ch);
preg_match('/ETag:\s*("[^"]+")/i', $resposta, $m);
verificar('responde com ETag', !empty($m[1]), substr($resposta, 0, 200));
$ch = curl_init("$APP/api/unidades.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['If-None-Match: ' . $m[1]]]);
curl_exec($ch);
verificar('devolve 304 se nada mudou', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 304, (string) curl_getinfo($ch, CURLINFO_HTTP_CODE));
curl_close($ch);

echo "\n== 5. Percurso passo a passo ==\n";
[$c, $r] = get("$APP/api/rota.php?lat1=41.15&lon1=-8.61&lat2=41.20&lon2=-8.55");
verificar('rota responde com linha', $c === 200 && !empty($r['linha']));
verificar('rota traz passos', count($r['passos'] ?? []) >= 3, count($r['passos'] ?? []) . ' passos');
$textos = array_column($r['passos'], 'texto');
verificar('instrucoes em portugues', (bool) preg_grep('/Virar à esquerda/u', $textos), implode(' | ', $textos));
verificar('traduz rotundas com a saida', (bool) preg_grep('/2\.ª saída/u', $textos), implode(' | ', $textos));
verificar('descarta manobras de poucos metros', !array_filter($r['passos'], function ($p) {
    return $p['metros'] > 0 && $p['metros'] < 15;
}));
verificar('ultimo passo e a chegada', strpos(end($textos), 'Chegou ao destino') === 0, end($textos));

echo "\n== 6. Área de influência ==\n";
// relê o ficheiro (as secções anteriores voltaram a gerá-lo) e monta a zona à volta de uma
// urgência real dos dados, em vez de uma caixa fixa que pode ficar vazia
$ficheiro = json_decode(file_get_contents("$RAIZ/data/unidades.json"), true);
$alvo = null;
foreach ($ficheiro['unidades'] as $u) {
    if (in_array('urgencia', $u['tipos'], true) && $u['lat'] > 37 && $u['lat'] < 42 && $u['lon'] > -9.5 && $u['lon'] < -6.5) {
        $alvo = $u;
        break;
    }
}
verificar('ha urgencias nos dados para testar', $alvo !== null, count($ficheiro['unidades']) . ' unidades no total');
$idUrgencia = $alvo['id'];
$zona = sprintf(
    'sul=%.4f&oeste=%.4f&norte=%.4f&este=%.4f&passo=6',
    $alvo['lat'] - 0.2, $alvo['lon'] - 0.25, $alvo['lat'] + 0.2, $alvo['lon'] + 0.25
);
[$c, $r] = get("$APP/api/influencia.php?tipo=urgencia&id=" . urlencode($idUrgencia) . "&$zona");
verificar('influencia responde 200', $c === 200, "codigo $c " . substr(json_encode($r), 0, 150));
verificar('devolve a unidade pedida', ($r['unidade']['id'] ?? '') === $idUrgencia);
verificar('serve parte da zona', $r['resumo']['celulas_servidas'] > 0 && $r['resumo']['celulas_servidas'] < $r['resumo']['celulas_total'],
    "{$r['resumo']['celulas_servidas']}/{$r['resumo']['celulas_total']}");
verificar('calcula a area em km2', $r['resumo']['area_km2'] > 0, (string) $r['resumo']['area_km2']);
verificar('devolve um raster com mascara dela', isset($r['raster']['dela']) && count($r['raster']['dela']) === $r['raster']['largura'] * $r['raster']['altura']);
verificar('so as marcadas contam para o resumo',
    count(array_filter($r['raster']['dela'])) === $r['resumo']['celulas_servidas']);
[$c, $r] = get("$APP/api/influencia.php?tipo=urgencia&id=node/000&$zona");
verificar('id desconhecido da erro claro', $c >= 400 && strpos($r['erro'], 'não existe') !== false, json_encode($r));

echo "\n== 7. Onde falta uma unidade ==\n";
[$c, $r] = get("$APP/api/planeamento.php?tipo=urgencia&$zona");
verificar('planeamento responde 200', $c === 200, "codigo $c " . substr(json_encode($r), 0, 150));
verificar('sugere um local dentro da zona',
    abs($r['local']['lat'] - $alvo['lat']) <= 0.21 && abs($r['local']['lon'] - $alvo['lon']) <= 0.26,
    json_encode($r['local']));
verificar('o tempo medio melhora', $r['depois']['minutos_medios'] < $r['antes']['minutos_medios'],
    "{$r['antes']['minutos_medios']} -> {$r['depois']['minutos_medios']}");
verificar('ganho positivo e coerente',
    abs($r['ganho_minutos'] - ($r['antes']['minutos_medios'] - $r['depois']['minutos_medios'])) < 0.11,
    (string) $r['ganho_minutos']);
verificar('testa varios candidatos', $r['candidatos_testados'] >= 2, (string) $r['candidatos_testados']);
verificar('devolve o raster ja com a unidade proposta',
    isset($r['raster']['valores']) && count($r['raster']['valores']) === $r['raster']['largura'] * $r['raster']['altura']);
[$c, $r] = get("$APP/api/planeamento.php?tipo=urgencia&sul=36.9&oeste=-9.6&norte=42.2&este=-6.1&passo=2");
verificar('recusa zonas enormes', $c >= 400 && strpos($r['erro'], 'demasiado grande') !== false, json_encode($r));

echo "\n== 8. Grelha de zona (raster para as manchas) ==\n";
[$c, $r] = get("$APP/api/grelha.php?tipo=urgencia&$zona");
verificar('grelha responde 200', $c === 200, "codigo $c");
$n = $r['largura'] * $r['altura'];
verificar('raster com origem e passo', isset($r['sul'], $r['oeste'], $r['dLat'], $r['dLon']));
verificar('valores e terra do tamanho do raster', count($r['valores']) === $n && count($r['terra']) === $n, "$n");
verificar('marca terra e mar', in_array(1, $r['terra'], true));
verificar('so as celulas em terra tem tempo', !array_filter($r['valores'], function ($v, $k) use ($r) {
    return $v !== null && !$r['terra'][$k];
}, ARRAY_FILTER_USE_BOTH));
verificar('algumas celulas com tempo', (bool) array_filter($r['valores'], function ($v) {
    return $v !== null;
}));
[$c, $r] = get("$APP/api/grelha.php?tipo=inventado&$zona");
verificar('tipo invalido da erro', $c >= 400, "codigo $c");

echo "\n== 9. Geocode ==\n";
[$c, $r] = get("$APP/api/geocode.php?q=Porto");
verificar('geocode responde', $c === 200 && !empty($r), substr(json_encode($r), 0, 120));

echo "\n== 9b. Servidores de rotas alternativos ==\n";
// O servidor público do OSRM devolve 403 a quem não se identifica ou faz uso pesado.
// Com uma lista, um servidor em baixo não pode deitar a funcionalidade abaixo.
$configOriginal = file_get_contents("$RAIZ/config.local.php");
$comFallback = preg_replace(
    "/'osrm_url' => '[^']*',/",
    "'osrm_urls' => ['http://127.0.0.1:8097', 'http://127.0.0.1:8099'],",
    $configOriginal
);
file_put_contents("$RAIZ/config.local.php", $comFallback);
limparCache();
[$c, $r] = get("$APP/api/tempos.php?lat=41.15&lon=-8.61");
verificar('salta o servidor em baixo e usa o seguinte', $c === 200 && !empty($r['resultados']), "codigo $c");

// com todos em baixo, a mensagem tem de explicar o 403 em vez de o repetir
file_put_contents("$RAIZ/config.local.php", preg_replace(
    "/'osrm_urls' => \[[^\]]*\],/",
    "'osrm_urls' => ['http://127.0.0.1:8097'],",
    $comFallback
));
limparCache();
[$c, $r] = get("$APP/api/tempos.php?lat=41.15&lon=-8.61");
verificar('sem servidores, explica em vez de rebentar', $c >= 400 && stripos($r['erro'] ?? '', 'servidor') !== false,
    substr($r['erro'] ?? "codigo $c", 0, 100));
file_put_contents("$RAIZ/config.local.php", $configOriginal);
limparCache();

echo "\n== 10. Recuperação e erros ==\n";
// sem ficheiro mas com blocos em cache: reconstroi sozinho.
// primeiro garante que há blocos em cache (as secções anteriores limparam-na)
limparCache();
shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --blocos-so 2>&1');
@unlink("$RAIZ/data/unidades.json");
limparLog();
[$c, $r] = get("$APP/api/unidades.php");
verificar('reconstroi o ficheiro a partir da cache de blocos', $c === 200 && !empty($r['unidades']), "codigo $c");
verificar('sem ir ao Overpass', count(pedidosOverpass()) === 0);
verificar('marca a origem', ($r['origem'] ?? '') === 'cache de blocos', $r['origem'] ?? '');
verificar('o ficheiro voltou a existir', is_file("$RAIZ/data/unidades.json"));

// sem ficheiro e sem cache: mensagem que diz o que fazer
@unlink("$RAIZ/data/unidades.json");
limparCache();
[$c, $r] = get("$APP/api/unidades.php");
verificar('sem dados nenhuns explica o que correr', $c >= 400 && strpos($r['erro'], 'atualizar_unidades.php') !== false, json_encode($r));

echo "\n== 10b. Diagnóstico da classificação ==\n";
$saida = shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php --diagnostico ' . escapeshellarg($exportacao) . ' 2>&1');
verificar('diagnostico conta os elementos', preg_match('/Elementos no ficheiro do Overpass: \d+/', $saida) === 1, substr($saida, 0, 120));
verificar('diagnostico separa aceites e descartados',
    strpos($saida, 'aceites como unidade') !== false && strpos($saida, 'não reconhecem') !== false);

echo "\n== 11. Correções manuais ==\n";
shell_exec('cd ' . escapeshellarg($RAIZ) . ' && php scripts/atualizar_unidades.php 2>&1');
$antes = json_decode(file_get_contents("$RAIZ/data/unidades.json"), true);
$primeiro = $antes['unidades'][0]['id'];
file_put_contents("$RAIZ/data/correcoes.json", json_encode(['remover' => [$primeiro]]));
[$c, $r] = get("$APP/api/unidades.php");
verificar('correcoes.json remove a unidade', !in_array($primeiro, array_column($r['unidades'], 'id'), true));
file_put_contents("$RAIZ/data/correcoes.json", '{}');

echo "\n== 12. População ==\n";
// A população vive em data/populacao_pt.csv e nos mapas nacionais já gerados, por isso
// estes testes não dependem do OSRM falso. Se esses ficheiros não existirem, a secção
// avisa e passa à frente em vez de falhar: nem toda a gente correu os scripts.
$temPop = is_file("$RAIZ/data/populacao_pt.csv");
$temNacional = is_file("$RAIZ/data/grelha_urgencia.json");

if (!$temPop) {
    echo "  (sem data/populacao_pt.csv — secção ignorada)\n";
} else {
    [$c, $r] = get("$APP/api/populacao.php?accao=resumo");
    verificar('resumo nacional responde', $c === 200 && !empty($r['tipos']), "codigo $c");
    if ($c === 200 && !empty($r['tipos'])) {
        $u = $r['tipos']['urgencia'] ?? null;
        verificar('a população total é a de Portugal',
            $u && $u['total'] > 9500000 && $u['total'] < 11000000, $u['total'] ?? 'sem urgencia');
        verificar('os 65+ são um subconjunto do total', $u && $u['total_65'] > 0 && $u['total_65'] < $u['total'],
            ($u['total_65'] ?? '?') . ' de ' . ($u['total'] ?? '?'));
        $soma = array_sum($u['classes'] ?? []);
        verificar('as classes somam o total', $u && abs($soma - $u['total']) <= 1, "$soma vs " . ($u['total'] ?? '?'));
        verificar('traz a fonte dos dados', !empty($r['fonte']['populacao']));
    }

    if ($temNacional) {
        [$c, $r] = get("$APP/api/populacao.php?accao=piores&tipo=urgencia&quantas=5");
        verificar('lista as piores zonas', $c === 200 && isset($r['zonas']), "codigo $c");
        if (!empty($r['zonas'])) {
            $pessoas = array_column($r['zonas'], 'pessoas');
            verificar('as zonas vêm da que tem mais gente para a que tem menos',
                $pessoas === array_values(array_reverse(array_sort_copia($pessoas))), implode(',', $pessoas));
            verificar('cada zona tem coordenadas', !empty($r['zonas'][0]['lat']) && !empty($r['zonas'][0]['lon']));
            verificar('todas estão acima do limiar',
                count(array_filter($r['zonas'], fn($z) => $z['minutos_medios'] > $r['limiar'])) === count($r['zonas']));
        }

        [$c, $r] = get("$APP/api/populacao.php?accao=piores&tipo=urgencia&quantas=5&ordenar=criticidade");
        verificar('ordena por gravidade quando se pede', $c === 200 && ($r['ordenado_por'] ?? '') === 'criticidade',
            $r['ordenado_por'] ?? "codigo $c");
        if (!empty($r['zonas'])) {
            $pesos = array_column($r['zonas'], 'pessoas_minuto');
            verificar('o peso do problema vem por ordem decrescente',
                $pesos === array_values(array_reverse(array_sort_copia($pesos))), implode(',', $pesos));
            verificar('o peso é pessoas vezes minutos a mais',
                abs($r['zonas'][0]['pessoas_minuto']
                    - $r['zonas'][0]['pessoas'] * $r['zonas'][0]['minutos_acima']) <= $r['zonas'][0]['pessoas'],
                $r['zonas'][0]['pessoas_minuto'] . ' vs ' . $r['zonas'][0]['pessoas'] . '*' . $r['zonas'][0]['minutos_acima']);
        }

        [$c, $r] = get("$APP/api/populacao.php?accao=percentil&tipo=urgencia&minutos=0");
        verificar('percentil de 0 min: quase toda a gente está pior', $c === 200 && $r['pct_pior_ou_igual'] > 99,
            $r['pct_pior_ou_igual'] ?? "codigo $c");
        [$c, $r2] = get("$APP/api/populacao.php?accao=percentil&tipo=urgencia&minutos=300");
        verificar('percentil de 300 min: quase ninguém está pior', $c === 200 && $r2['pct_pior_ou_igual'] < 1,
            $r2['pct_pior_ou_igual'] ?? "codigo $c");
        verificar('a mediana nacional é um número plausível',
            isset($r['mediana_nacional']) && $r['mediana_nacional'] > 0 && $r['mediana_nacional'] < 120,
            $r['mediana_nacional'] ?? 'sem mediana');
    } else {
        echo "  (sem mapas nacionais — piores zonas e percentil ignorados)\n";
    }

    // a análise de zona passa a trazer população por quadrícula
    [$c, $r] = get("$APP/api/grelha.php?tipo=urgencia&sul=41.0&oeste=-8.8&norte=41.4&este=-8.3&passo=8");
    verificar('a grelha traz população', $c === 200 && !empty($r['tem_populacao']) && isset($r['populacao']), "codigo $c");
    if (!empty($r['populacao'])) {
        verificar('a população tem o tamanho do raster',
            count($r['populacao']) === $r['largura'] * $r['altura'],
            count($r['populacao']) . ' vs ' . ($r['largura'] * $r['altura']));
        verificar('vive gente na zona do Porto', array_sum($r['populacao']) > 100000, array_sum($r['populacao']));
        verificar('o resumo por pessoas bate certo com os arrays',
            isset($r['resumo_populacao']['total'])
            && abs($r['resumo_populacao']['total'] - array_sum($r['populacao'])) <= 1,
            ($r['resumo_populacao']['total'] ?? '?') . ' vs ' . array_sum($r['populacao']));
    }
}

echo "\n== 13. Modelo de tempos e otimização ==\n";
// O modelo e o otimizador vivem dos mapas nacionais e do data/populacao_pt.csv, não do
// unidades.json de teste — por isso estes testes correm sobre os dados reais do projeto.
if (!is_file("$RAIZ/data/modelo_tempo_urgencia.json")) {
    echo "  (sem data/modelo_tempo_urgencia.json — secção ignorada; corre php scripts/treinar_modelo.php urgencia)\n";
} else {
    $modelo = json_decode(file_get_contents("$RAIZ/data/modelo_tempo_urgencia.json"), true);
    verificar('o modelo tem coeficientes por quadrícula', !empty($modelo['celulas']));
    verificar('o modelo foi avaliado fora do treino',
        isset($modelo['avaliacao']['r2']) && $modelo['pontos_teste'] > 100,
        ($modelo['pontos_teste'] ?? 0) . ' pontos de teste');
    verificar('o modelo explica a maior parte da variação',
        $modelo['avaliacao']['r2'] > 0.7, $modelo['avaliacao']['r2']);
    verificar('bate a reta global', $modelo['avaliacao']['mae'] < $modelo['base_global']['mae'],
        $modelo['avaliacao']['mae'] . ' vs ' . $modelo['base_global']['mae']);

    [$c, $r] = get("$APP/api/otimizar.php?tipo=urgencia&quantas=2");
    verificar('o otimizador responde', $c === 200 && !empty($r['locais']), "codigo $c");
    if (!empty($r['locais'])) {
        verificar('devolve o número de locais pedido', count($r['locais']) === 2, count($r['locais']));
        verificar('os locais são distintos',
            $r['locais'][0]['lat'] !== $r['locais'][1]['lat'] || $r['locais'][0]['lon'] !== $r['locais'][1]['lon']);
        verificar('os locais caem dentro de Portugal',
            count(array_filter($r['locais'], fn($l) => $l['lat'] > 32 && $l['lat'] < 42.5
                && $l['lon'] > -31.5 && $l['lon'] < -6)) === 2);
        verificar('construir nunca piora o deserto', $r['depois']['deserto'] <= $r['antes']['deserto'],
            $r['antes']['deserto'] . ' -> ' . $r['depois']['deserto']);
        verificar('construir nunca piora o tempo médio',
            $r['depois']['minutos_medios'] <= $r['antes']['minutos_medios'] + 0.01,
            $r['antes']['minutos_medios'] . ' -> ' . $r['depois']['minutos_medios']);
        verificar('as horas poupadas não são negativas', $r['ganho']['horas_pessoa_poupadas'] >= 0,
            $r['ganho']['horas_pessoa_poupadas']);
        verificar('avaliou muito mais do que três configurações', $r['execucao']['avaliacoes'] > 1000,
            $r['execucao']['avaliacoes']);
        verificar('mostra a margem de erro do modelo', isset($r['modelo']['mae']));

        // segunda chamada: tem de vir da cache e ser igual
        $t0 = microtime(true);
        [$c2, $r2] = get("$APP/api/otimizar.php?tipo=urgencia&quantas=2");
        $rapido = microtime(true) - $t0 < 3;
        verificar('a segunda chamada vem da cache', $c2 === 200 && $rapido,
            sprintf('%.1f s', microtime(true) - $t0));
        verificar('e dá o mesmo resultado',
            json_encode($r2['locais']) === json_encode($r['locais']));
    }

    // os dois objetivos são perguntas diferentes e podem dar respostas diferentes
    [$c, $rd] = get("$APP/api/otimizar.php?tipo=urgencia&quantas=2&objetivo=deserto");
    verificar('aceita o objetivo "deserto"', $c === 200 && ($rd['execucao']['objetivo'] ?? '') === 'deserto',
        $rd['execucao']['objetivo'] ?? "codigo $c");
    if (!empty($rd['locais']) && !empty($r['locais'])) {
        verificar('o objetivo do deserto tira pelo menos tanta gente do deserto',
            $rd['ganho']['saem_do_deserto'] >= $r['ganho']['saem_do_deserto'] * 0.9,
            $rd['ganho']['saem_do_deserto'] . ' vs ' . $r['ganho']['saem_do_deserto']);
    }

    [$c, $r] = get("$APP/api/otimizar.php?tipo=nao_existe&quantas=2");
    verificar('recusa tipo inválido', $c >= 400);

    // um modelo treinado noutra grelha descreve um país que já não existe
    $guardado = file_get_contents("$RAIZ/data/modelo_tempo_urgencia.json");
    $m = json_decode($guardado, true);
    $m['grelha_gerada_em'] = '1999-01-01T00:00:00+00:00';
    file_put_contents("$RAIZ/data/modelo_tempo_urgencia.json", json_encode($m));
    limparCache();
    [$c, $r] = get("$APP/api/otimizar.php?tipo=urgencia&quantas=1");
    verificar('avisa quando o modelo está desatualizado',
        $c === 200 && !empty($r['modelo_desatualizado']), "codigo $c");
    file_put_contents("$RAIZ/data/modelo_tempo_urgencia.json", $guardado);
    limparCache();
}

// ---------- resumo ----------
echo "\n" . str_repeat('=', 50) . "\n";
echo "$ok testes passaram\n";
if ($falhas) {
    echo count($falhas) . " FALHARAM:\n";
    foreach ($falhas as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "Tudo ok.\n";
