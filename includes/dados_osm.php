<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/blocos.php';

function tipos()
{
    return array_keys(cfg('tipos'));
}

// Query Overpass para um bloco (caixa [sul, oeste, norte, este]).
//
// Só filtros de tag exatos e uma caixa: é o que o Overpass consegue responder depressa.
// A versão antiga pedia o país inteiro com `area["ISO3166-1"="PT"]` e um `name~"..."` com
// expressões regulares; nenhum dos dois usa índice, o servidor tinha de percorrer todos os
// elementos e a query acabava em "Query timed out". A seleção pelo nome passou para o PHP
// (ver classificar_elemento), que já a fazia à mesma.
function query_overpass($caixa)
{
    $bbox = implode(',', array_map(function ($v) {
        return round($v, 4);
    }, $caixa));
    $timeout = (int) cfg('overpass_timeout_bloco');

    return <<<OQL
[out:json][timeout:$timeout];
(
  nwr["amenity"="hospital"]($bbox);
  nwr["amenity"="clinic"]($bbox);
  nwr["amenity"="doctors"]($bbox);
  nwr["healthcare"="hospital"]($bbox);
  nwr["healthcare"="clinic"]($bbox);
  nwr["healthcare"="centre"]($bbox);
  nwr["healthcare"="doctor"]($bbox);
  nwr["healthcare"="birthing_centre"]($bbox);
);
out center tags;
OQL;
}

// Converte um elemento do Overpass numa unidade com os tipos de cuidado que oferece.
// Devolve null se não for relevante.
function classificar_elemento($el)
{
    $t = $el['tags'] ?? [];
    $nome = trim($t['name'] ?? '');
    $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
    $lon = $el['lon'] ?? $el['center']['lon'] ?? null;

    if ($nome === '' || $lat === null || $lon === null || preg_match('/veterin/i', $nome)) {
        return null;
    }

    $hospital = ($t['amenity'] ?? '') === 'hospital' || ($t['healthcare'] ?? '') === 'hospital';
    $emergency = $t['emergency'] ?? '';
    $urgencia = $emergency === 'yes' ? 'sim' : ($emergency === 'no' ? 'nao' : 'desconhecido');

    // hospitais especializados raramente têm urgência geral; sem tag não contam
    $especializado = preg_match('/maternidade|psiqui|reabilita|oncol|IPO\b|cuidados continuados|hospital de dia/iu', $nome);

    $tipos = [];
    // urgência: tag emergency=yes, ou hospital geral sem informação (decidido depois pela opção sem_tag)
    if ($urgencia === 'sim' || ($hospital && $urgencia === 'desconhecido' && !$especializado)) {
        $tipos[] = 'urgencia';
    }
    // Centro de saúde: o OSM não tem etiqueta para "cuidados primários do SNS", por isso
    // a identificação é pelo nome. Os nomes usados em Portugal são variados — além de
    // "Centro de Saúde", há USF, UCSP, UCC, URAP, extensões e postos — e nem todos seguem
    // a mesma convenção. Daí uma lista longa em vez de dois ou três padrões.
    $padroesCentro = '/centro de sa[uú]de|unidade de sa[uú]de|extens[aã]o de sa[uú]de|posto de sa[uú]de'
        . '|casa de sa[uú]de do sns|unidade de cuidados de sa[uú]de|cuidados de sa[uú]de personalizados'
        . '|unidade de cuidados na comunidade|\bUSF\b|\bUCSP\b|\bUCC\b|\bURAP\b|\bUSP\b/iu';
    // operadores públicos: ARS, ULS e ACES são sempre cuidados primários ou hospitalares do SNS
    $operador = $t['operator'] ?? '';
    $publicoPrimarios = preg_match('/\b(ARS|ULS|ACES|ACeS)\b/u', $operador)
        && in_array($t['amenity'] ?? '', ['clinic', 'doctors'], true);

    if (preg_match($padroesCentro, $nome) || $publicoPrimarios) {
        $tipos[] = 'centro_saude';
    }
    // Maternidade: em Portugal quase não existem maternidades autónomas — o parto
    // acontece no bloco de partos de um hospital, que o OSM não etiqueta. Confiar nas
    // etiquetas dava 6 unidades, metade delas erradas (hospitais com consulta de
    // obstetrícia mas sem bloco de partos aparecem com healthcare:speciality=obstetrics).
    // Por isso a lista verdadeira vem de data/correcoes.json, construída a partir do
    // Portal do SNS (urgência obstétrica/ginecológica e bloco de partos). Aqui fica só
    // o nome, que apanha as três maternidades autónomas do país.
    if (preg_match('/\bmaternidade\b/iu', $nome)) {
        $tipos[] = 'maternidade';
    }

    if (!$tipos) {
        return null;
    }

    return [
        'id' => $el['type'] . '/' . $el['id'],
        'nome' => $nome,
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
        'tipos' => $tipos,
        'urgencia' => $urgencia,
        'operador' => $t['operator'] ?? '',
        'localidade' => $t['addr:city'] ?? '',
    ];
}

// Classifica a resposta do Overpass e devolve uma lista de unidades.
// Com $contagem, preenche também um resumo do que foi descartado e porquê — é o que
// permite responder à pergunta "isto são mesmo todas?".
function processar_overpass($json, &$contagem = null)
{
    $contagem = [
        'elementos' => 0,
        'sem_nome' => 0,
        'veterinaria' => 0,
        'sem_coordenadas' => 0,
        'nao_classificados' => 0,
        'aceites' => 0,
        'exemplos_descartados' => [],
    ];

    $unidades = [];
    foreach ($json['elements'] ?? [] as $el) {
        $contagem['elementos']++;
        $t = $el['tags'] ?? [];
        $nome = trim($t['name'] ?? '');
        $temCoords = ($el['lat'] ?? $el['center']['lat'] ?? null) !== null;

        $u = classificar_elemento($el);
        if ($u) {
            $unidades[] = $u;
            $contagem['aceites']++;
            continue;
        }
        if (!$temCoords) {
            $contagem['sem_coordenadas']++;
        } elseif ($nome === '') {
            $contagem['sem_nome']++;
        } elseif (preg_match('/veterin/i', $nome)) {
            $contagem['veterinaria']++;
        } else {
            $contagem['nao_classificados']++;
            if (count($contagem['exemplos_descartados']) < 25) {
                $contagem['exemplos_descartados'][] = $nome . '  [' . ($t['amenity'] ?? $t['healthcare'] ?? '?') . ']';
            }
        }
    }
    return $unidades;
}

// Junta listas de unidades vindas de blocos diferentes.
// Tira repetidos de duas maneiras: pelo id (um hospital na fronteira entre blocos é devolvido
// pelos dois) e pelo nome quando dois elementos ficam a menos de ~100 m (o mesmo hospital
// aparece às vezes como ponto e como área).
function fundir_unidades($listas)
{
    $porId = [];
    foreach ($listas as $lista) {
        foreach ($lista as $u) {
            $porId[$u['id']] = $u;
        }
    }

    $unidades = [];
    foreach ($porId as $u) {
        $chave = strtolower($u['nome']) . '|' . round($u['lat'], 3) . '|' . round($u['lon'], 3);
        if (isset($unidades[$chave])) {
            $existente = &$unidades[$chave];
            $existente['tipos'] = array_values(array_unique(array_merge($existente['tipos'], $u['tipos'])));
            if ($u['urgencia'] === 'sim') {
                $existente['urgencia'] = 'sim';
            }
            unset($existente);
        } else {
            $unidades[$chave] = $u;
        }
    }
    return array_values($unidades);
}

// ---------- falar com o Overpass ----------

// Um servidor que falhou fica de lado durante um tempo, para os pedidos seguintes não
// pagarem o mesmo erro. O tempo sai da própria resposta quando ela o diz.
// A chave é o URL completo, não o host: dois servidores podem partilhar o mesmo host
// (acontece em testes locais) e marcar um não pode marcar os outros.
function segundos_de_descanso($url)
{
    $marca = cache_ler("overpass_baixo_$url", 10 * 365 * 24 * 3600);
    return is_array($marca) ? max(0, ($marca['ate'] ?? 0) - time()) : 0;
}

function servidor_em_descanso($url)
{
    return segundos_de_descanso($url) > 0;
}

// Quanto tempo esperar antes de voltar a tentar este servidor, a partir da mensagem de erro.
// O Overpass diz "Slot available after: ..., in 92 seconds" quando recusa por excesso de
// pedidos; obedecer a esse número é o que evita ficar de castigo cada vez mais tempo.
function descanso_sugerido($erro)
{
    $tempos = cfg('overpass_descansos');
    if (preg_match('/in (\d+) seconds/i', $erro, $m)) {
        return min(600, (int) $m[1] + 5); // o próprio servidor disse quando
    }
    if (strpos($erro, 'erro 429') !== false) {
        return $tempos['recusado']; // excesso de pedidos, sem dizer quando
    }
    if (strpos($erro, 'erro 504') !== false || stripos($erro, 'timed out') !== false) {
        return $tempos['sobrecarregado'];
    }
    return $tempos['sem_ligacao']; // pode ser passageira
}

function marcar_servidor_em_baixo($url, $erro)
{
    cache_guardar("overpass_baixo_$url", ['erro' => $erro, 'ate' => time() + descanso_sugerido($erro)]);
}

// Servidores por ordem de preferência: primeiro os que não estão de castigo, depois os
// outros (os de castigo mais curto à frente).
//
// A versão anterior descartava os servidores em castigo e, quando estavam todos, caía
// sempre no primeiro da lista. Bastava o primeiro estar bloqueado para os outros dois
// deixarem de ser tentados — foi o que aconteceu a meio de um download do país inteiro.
function servidores_por_preferencia()
{
    $urls = cfg('overpass_urls');
    usort($urls, function ($a, $b) {
        return segundos_de_descanso($a) <=> segundos_de_descanso($b);
    });
    return $urls;
}

// Envia uma query a cada servidor, por ordem de preferência, até um responder.
//
// Um servidor em castigo é saltado, nunca esperado: esperar aqui multiplicava-se por cada
// um dos ~78 blocos. Quem espera é o ciclo das rondas, uma vez só.
function pedir_ao_overpass($query, $timeout)
{
    $erros = [];
    $tentados = 0;

    foreach (servidores_por_preferencia() as $url) {
        $host = parse_url($url, PHP_URL_HOST);
        if (servidor_em_descanso($url)) {
            $erros[] = "$host: em espera mais " . segundos_de_descanso($url) . ' s';
            continue;
        }
        $tentados++;
        try {
            esperar_vez('overpass', cfg('intervalo_overpass'));
            $json = http_json($url, http_build_query(['data' => $query]), $timeout + 20, 60);
            // quando a query excede o tempo, o Overpass responde 200 com uma "remark" e sem elementos
            if (isset($json['remark']) && stripos($json['remark'], 'timed out') !== false) {
                $erros[] = "$host: " . $json['remark'];
                marcar_servidor_em_baixo($url, $json['remark']);
                continue;
            }
            if (!isset($json['elements'])) {
                $erros[] = "$host: resposta sem a lista \"elements\"";
                continue;
            }
            cache_apagar("overpass_baixo_$url");
            return $json;
        } catch (Exception $e) {
            $erros[] = $e->getMessage();
            marcar_servidor_em_baixo($url, $e->getMessage());
        }
    }

    if ($tentados === 0) {
        throw new Exception('Todos os servidores Overpass estão em espera (' . implode('; ', $erros) . ').');
    }
    throw new Exception('Nenhum servidor Overpass respondeu (' . implode('; ', array_unique($erros)) . ').');
}

// Uma query só, para o país inteiro. Sem `area` e sem expressões regulares, o Overpass
// consegue responder a isto: é um pedido em vez de 78, o que evita por completo o limite
// de pedidos por IP. É o que se tenta primeiro; os blocos ficam como alternativa.
function query_overpass_pais($timeout = null)
{
    $timeout = (int) ($timeout ?? cfg('overpass_timeout_pais'));
    $tags = [
        ['amenity', 'hospital'], ['amenity', 'clinic'], ['amenity', 'doctors'],
        ['healthcare', 'hospital'], ['healthcare', 'clinic'], ['healthcare', 'centre'],
        ['healthcare', 'doctor'], ['healthcare', 'birthing_centre'],
    ];

    $linhas = [];
    foreach (cfg('regioes') as $caixa) {
        $bbox = implode(',', $caixa);
        foreach ($tags as [$chave, $valor]) {
            $linhas[] = "  nwr[\"$chave\"=\"$valor\"]($bbox);";
        }
    }

    return "[out:json][timeout:$timeout];\n(\n" . implode("\n", $linhas) . "\n);\nout center tags;";
}

function descarregar_pais()
{
    $json = pedir_ao_overpass(query_overpass_pais(), (int) cfg('overpass_timeout_pais'));
    $unidades = fundir_unidades([processar_overpass($json)]);
    if (!$unidades) {
        throw new Exception('O Overpass respondeu mas sem nenhuma unidade de saúde. Não parece certo; tenta outra vez.');
    }
    return $unidades;
}

// ---------- descarregar um bloco ----------

function descarregar_bloco($chave)
{
    $caixa = bloco_caixa($chave);
    if ($caixa === null) {
        throw new Exception("Bloco inválido: $chave");
    }
    return processar_overpass(pedir_ao_overpass(query_overpass($caixa), (int) cfg('overpass_timeout_bloco')));
}

// Unidades de um bloco, com cache. Devolve null se o bloco não estiver em cache e
// $descarregar for false, ou se o download falhar.
//
// Guarda também as falhas durante uns minutos (cache_ttl_bloco_falha): sem isso, recarregar
// a página repetia os mesmos pedidos a um servidor que já estava a recusar.
function obter_bloco($chave, $descarregar = true, &$erro = null)
{
    static $memoria = [];
    if (array_key_exists($chave, $memoria)) {
        return $memoria[$chave];
    }

    $guardado = cache_ler("bloco_$chave", cfg('cache_ttl_bloco'));
    if (is_array($guardado) && isset($guardado['unidades'])) {
        $memoria[$chave] = $guardado['unidades'];
        return $memoria[$chave];
    }

    if (!$descarregar) {
        return null;
    }

    $falha = cache_ler("bloco_falha_$chave", cfg('cache_ttl_bloco_falha'));
    if ($falha !== null) {
        $erro = $falha['erro'] ?? 'O Overpass recusou o pedido há pouco.';
        return null;
    }

    // lock por bloco: se dois pedidos pedirem o mesmo bloco ao mesmo tempo,
    // só um vai ao Overpass e o outro aproveita o resultado
    $pasta = garantir_pasta_cache();
    $lock = fopen("$pasta/bloco_" . md5($chave) . '.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }

    try {
        $guardado = cache_ler("bloco_$chave", cfg('cache_ttl_bloco'));
        if (is_array($guardado) && isset($guardado['unidades'])) {
            $memoria[$chave] = $guardado['unidades'];
            return $memoria[$chave];
        }
        try {
            $unidades = descarregar_bloco($chave);
        } catch (Exception $e) {
            cache_guardar("bloco_falha_$chave", ['erro' => $e->getMessage()]);
            $erro = $e->getMessage();
            // se houver uma versão antiga em cache, vale mais isso do que nada
            $antigo = cache_ler("bloco_$chave", 10 * 365 * 24 * 3600);
            if (is_array($antigo) && isset($antigo['unidades'])) {
                $memoria[$chave] = $antigo['unidades'];
                return $memoria[$chave];
            }
            return null;
        }
        cache_guardar("bloco_$chave", ['atualizado_em' => date('c'), 'unidades' => $unidades]);
        cache_apagar("bloco_falha_$chave");
        $memoria[$chave] = $unidades;
        return $unidades;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function bloco_em_cache($chave)
{
    return cache_valida("bloco_$chave", cfg('cache_ttl_bloco'));
}

// ---------- juntar blocos ----------

// Correções manuais em data/correcoes.json (aplicadas sempre, não ficam em cache)
function aplicar_correcoes($unidades)
{
    static $correcoes = null;
    if ($correcoes === null) {
        $ficheiro = __DIR__ . '/../data/correcoes.json';
        $correcoes = is_file($ficheiro) ? (json_decode(file_get_contents($ficheiro), true) ?: []) : [];
    }

    $remover = array_flip($correcoes['remover'] ?? []);
    $adicionar = $correcoes['adicionar_tipo'] ?? [];

    $lista = [];
    foreach ($unidades as $u) {
        if (isset($remover[$u['id']])) {
            continue;
        }
        if (isset($adicionar[$u['id']])) {
            $u['tipos'] = array_values(array_unique(array_merge($u['tipos'], $adicionar[$u['id']])));
            if (in_array('urgencia', $adicionar[$u['id']], true)) {
                $u['urgencia'] = 'sim';
            }
        }
        $lista[] = $u;
    }

    // As unidades acrescentadas à mão são correções a dados reais, não uma fonte de
    // dados. Sem nada para corrigir, não se acrescentam: se o download falhou todo, quem
    // chama tem de ver uma lista vazia e perceber que precisa de correr o script de
    // atualização, em vez de receber meia dúzia de unidades manuais e julgar que está bem.
    if ($lista) {
        foreach ($correcoes['unidades_extra'] ?? [] as $u) {
            $u += ['operador' => '', 'localidade' => '', 'urgencia' => 'sim'];
            $lista[] = $u;
        }
    }

    return $lista;
}

// Unidades de uma lista de blocos. Devolve também que blocos ficaram por carregar,
// para quem chama poder avisar ou tentar mais tarde.
function obter_unidades_blocos($chaves, $descarregar = true)
{
    $listas = [];
    $carregados = [];
    $falhados = [];
    foreach ($chaves as $chave) {
        if (!bloco_e_relevante($chave)) {
            continue; // bloco fora de Portugal: não vale a pena pedir
        }
        $erro = null;
        $unidades = obter_bloco($chave, $descarregar, $erro);
        if ($unidades === null) {
            $falhados[$chave] = $erro ?? 'ainda não carregado';
            continue;
        }
        $listas[] = $unidades;
        $carregados[] = $chave;
    }

    return [
        'unidades' => aplicar_correcoes(fundir_unidades($listas)),
        'blocos' => $carregados,
        'falhados' => $falhados,
    ];
}

// Todas as unidades do país. Usada pelos scripts, onde é preciso a lista completa.
function obter_unidades_pais($descarregar = true)
{
    return obter_unidades_blocos(blocos_relevantes(), $descarregar);
}

// ---------- ficheiro de dados ----------
//
// As unidades vivem em data/unidades.json, gerado por scripts/atualizar_unidades.php.
// Abrem e fecham poucas unidades por ano, por isso não vale a pena ir ao Overpass em cada
// visita: o ficheiro é lido uma vez por pedido e a página arranca sem esperar por ninguém.
// O download por blocos continua a existir, mas só dentro do script de atualização.

function ficheiro_unidades()
{
    return __DIR__ . '/../data/unidades.json';
}

// Guarda a lista no ficheiro de dados. Escreve primeiro num ficheiro temporário e só depois
// renomeia, para nunca haver um instante em que o ficheiro está a meio.
function guardar_unidades($unidades, $origem = 'overpass')
{
    $ficheiro = ficheiro_unidades();
    $conteudo = [
        'atualizado_em' => date('c'),
        'origem' => $origem,
        'licenca' => 'ODbL 1.0 - dados de © contribuidores do OpenStreetMap (openstreetmap.org/copyright)',
        'total' => count($unidades),
        'unidades' => $unidades,
    ];
    $temporario = $ficheiro . '.tmp';
    if (file_put_contents($temporario, json_encode($conteudo, JSON_UNESCAPED_UNICODE)) === false) {
        throw new Exception("Não foi possível escrever em $ficheiro. Verifica as permissões da pasta data/.");
    }
    @chmod($temporario, 0666);
    if (!rename($temporario, $ficheiro)) {
        @unlink($temporario);
        throw new Exception("Não foi possível substituir $ficheiro.");
    }
    return $conteudo;
}

// Lê data/unidades.json. Se não existir mas houver blocos em cache de uma versão anterior,
// gera o ficheiro a partir deles em vez de obrigar a descarregar tudo outra vez.
function obter_unidades()
{
    static $dados = null;
    if ($dados !== null) {
        return $dados;
    }

    $ficheiro = ficheiro_unidades();
    if (is_file($ficheiro)) {
        $lido = json_decode(file_get_contents($ficheiro), true);
        if (is_array($lido) && isset($lido['unidades'])) {
            $lido['unidades'] = aplicar_correcoes($lido['unidades']);
            $lido['total'] = count($lido['unidades']);
            return $dados = $lido;
        }
    }

    // recuperação: aproveitar blocos já descarregados por uma versão anterior
    $daCache = obter_unidades_blocos(blocos_relevantes(), false);
    if ($daCache['unidades']) {
        $dados = guardar_unidades($daCache['unidades'], 'cache de blocos');
        return $dados;
    }

    throw new Exception('Ainda não há dados das unidades de saúde. No servidor, corre: php scripts/atualizar_unidades.php');
}

function ha_unidades()
{
    return is_file(ficheiro_unidades()) || (bool) obter_unidades_blocos(blocos_relevantes(), false)['unidades'];
}

// Filtra uma lista de unidades por tipo. $unidades vem do índice ou de obter_unidades_blocos().
function unidades_do_tipo($unidades, $tipo, $incluirSemTag = true)
{
    $lista = [];
    foreach ($unidades as $u) {
        if (!in_array($tipo, $u['tipos'], true)) {
            continue;
        }
        if ($tipo === 'urgencia' && !$incluirSemTag && $u['urgencia'] !== 'sim') {
            continue;
        }
        $lista[] = $u;
    }
    return $lista;
}