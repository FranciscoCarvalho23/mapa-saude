<?php
require_once __DIR__ . '/../config.php';

// ---------- Respostas da API ----------

function responder_json($dados, $codigo = 200)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function erro_json($mensagem, $codigo = 400)
{
    responder_json(['erro' => $mensagem], $codigo);
}

// Chamar no início de cada endpoint: avisos do PHP não estragam o JSON
// e exceções não apanhadas chegam ao browser como {"erro": "..."}
function iniciar_api($limiteSegundos = 120)
{
    ini_set('display_errors', '0');
    set_time_limit($limiteSegundos);
    set_exception_handler(function ($e) {
        error_log('[mapa-saude] ' . $e->getMessage());
        erro_json($e->getMessage(), 502);
    });
}

// Lê e valida os limites de uma zona (sul/oeste/norte/este) e o passo da grelha.
// Partilhado por api/grelha.php, api/influencia.php e api/planeamento.php.
function limites_e_passo_do_pedido()
{
    $v = [];
    foreach (['sul', 'oeste', 'norte', 'este', 'passo'] as $campo) {
        $v[$campo] = filter_var($_GET[$campo] ?? null, FILTER_VALIDATE_FLOAT);
        if ($v[$campo] === false) {
            erro_json("Parâmetro $campo inválido.");
        }
    }
    $passo = max(1.0, min(25.0, $v['passo']));

    // conta as quadrículas antes de as gerar, para recusar zonas enormes logo à partida
    $alturaKm = ($v['norte'] - $v['sul']) * 111;
    $larguraKm = ($v['este'] - $v['oeste']) * 111 * cos(deg2rad(($v['norte'] + $v['sul']) / 2));
    if ($alturaKm <= 0 || $larguraKm <= 0 || ($alturaKm * $larguraKm) / ($passo * $passo) > cfg('max_celulas_zona') * 1.5) {
        erro_json('A zona é demasiado grande para este passo. Aproxima o mapa.');
    }

    return [[$v['sul'], $v['oeste'], $v['norte'], $v['este']], $passo];
}

// ---------- Cache em ficheiros ----------

function garantir_pasta_cache()
{
    static $verificada = false;
    $dir = cfg('cache_dir');
    if ($verificada) {
        return $dir;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new Exception("Não foi possível criar a pasta de cache ($dir). Cria-a à mão e dá-lhe permissão de escrita.");
    }
    if (!is_writable($dir)) {
        throw new Exception("O PHP não tem permissão para escrever na pasta de cache ($dir). Ver README, secção Problemas comuns.");
    }
    $verificada = true;
    return $dir;
}

function ficheiro_cache($chave)
{
    return garantir_pasta_cache() . '/' . md5($chave) . '.json';
}

// Diz se uma entrada existe e ainda é válida sem ler o ficheiro (só olha para a data).
// Percorrer os 78 blocos com isto quase não custa; com cache_ler() seriam 78 leituras de JSON.
function cache_valida($chave, $ttl)
{
    $ficheiro = ficheiro_cache($chave);
    return is_file($ficheiro) && filemtime($ficheiro) + $ttl >= time();
}

// Data da última escrita de uma entrada (0 se não existir). Usada na impressão digital do índice.
function cache_data($chave)
{
    $ficheiro = ficheiro_cache($chave);
    return is_file($ficheiro) ? filemtime($ficheiro) : 0;
}

function cache_ler($chave, $ttl)
{
    $ficheiro = ficheiro_cache($chave);
    if (!is_file($ficheiro) || filemtime($ficheiro) + $ttl < time()) {
        return null;
    }
    return json_decode(file_get_contents($ficheiro), true);
}

function cache_guardar($chave, $dados)
{
    $ficheiro = ficheiro_cache($chave);
    file_put_contents($ficheiro, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($ficheiro, 0666); // o script de linha de comandos e o Apache podem ser utilizadores diferentes
}

function cache_apagar($chave)
{
    @unlink(ficheiro_cache($chave));
}

// ---------- Limite de pedidos ----------

// Garante um intervalo mínimo entre pedidos ao mesmo serviço.
// O flock faz com que vários pedidos PHP em simultâneo esperem pela sua vez.
function esperar_vez($servico, $intervalo)
{
    $fp = fopen(garantir_pasta_cache() . "/limite_$servico.txt", 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    $ultimo = (float) stream_get_contents($fp);
    $espera = $ultimo + $intervalo - microtime(true);
    if ($espera > 0) {
        usleep((int) ($espera * 1000000));
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string) microtime(true));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ---------- HTTP ----------

// Faz um pedido GET (ou POST se $post não for null) e devolve o JSON já descodificado.
// $paciencia = segundos sem receber nada antes de desistir. Os servidores Overpass
// sobrecarregados aceitam a ligação e depois não enviam nada; sem isto ficava-se à espera
// até ao fim do timeout (eram os "Operation timed out ... with 0 bytes received").
function http_json($url, $post = null, $timeout = 30, $paciencia = 45)
{
    if (!function_exists('curl_init')) {
        throw new Exception('A extensão curl do PHP não está ativa (ver php.ini).');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => $paciencia,
        CURLOPT_USERAGENT => cfg('user_agent'),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: pt-PT,pt', 'Expect:'],
        CURLOPT_ENCODING => '', // aceita respostas comprimidas
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $corpo = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $host = parse_url($url, PHP_URL_HOST);

    if ($corpo === false) {
        throw new Exception("Sem ligação a $host: " . curl_error($ch));
    }

    $json = json_decode($corpo, true);
    if ($codigo >= 400) {
        // O Overpass responde aos 429 em texto, não em JSON, e esse texto diz quando
        // é que há slot outra vez ("Slot available after: ..., in 92 seconds").
        // Sem isto perdia-se a única informação útil da resposta.
        $detalhe = $json['message'] ?? trim(preg_replace('/\s+/', ' ', strip_tags((string) $corpo)));
        $detalhe = mb_substr($detalhe, 0, 300);
        throw new Exception("$host respondeu com erro $codigo" . ($detalhe !== '' ? ": $detalhe" : ''));
    }
    if ($json === null) {
        throw new Exception("$host devolveu uma resposta que não é JSON.");
    }
    return $json;
}
