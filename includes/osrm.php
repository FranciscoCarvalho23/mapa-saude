<?php
require_once __DIR__ . '/funcoes.php';

function coordenada_osrm($lat, $lon)
{
    return round($lon, 5) . ',' . round($lat, 5); // o OSRM usa a ordem lon,lat
}

// Servidores a tentar, por ordem. A lista está em 'osrm_urls'; quem tiver um
// 'osrm_url' único (config.local.php antigo, ou um OSRM próprio) manda nela.
function servidores_osrm()
{
    $unico = cfg('osrm_url');
    if (is_string($unico) && $unico !== '') {
        return [$unico];
    }
    $lista = cfg('osrm_urls');
    return is_array($lista) ? array_values(array_filter($lista)) : [];
}

// O servidor de demonstração do OSRM bloqueia quem não se identifica ("Faking another
// app's User-Agent WILL get you blocked"), e o config.php vem com um email de exemplo.
// Sem esta verificação, o sintoma é um 403 que não diz nada sobre a causa.
function user_agent_por_preencher()
{
    return strpos(cfg('user_agent'), 'altera-este-email') !== false;
}

// Tenta cada servidor até um responder. Devolve o JSON já descodificado.
function pedir_ao_osrm($caminho, $timeout = 60)
{
    $erros = [];
    foreach (servidores_osrm() as $base) {
        $url = rtrim($base, '/') . $caminho;
        try {
            esperar_vez('osrm', cfg('intervalo_osrm'));
            return [http_json($url, null, $timeout), $url];
        } catch (Exception $e) {
            $erros[] = parse_url($base, PHP_URL_HOST) . ': ' . $e->getMessage();
        }
    }

    $mensagem = 'Nenhum servidor de rotas respondeu. ' . implode(' | ', $erros);
    if (strpos($mensagem, 'erro 403') !== false) {
        $mensagem .= user_agent_por_preencher()
            ? ' — o config.php ainda tem o email de exemplo no user_agent. O servidor público do OSRM'
              . ' bloqueia pedidos que não identifiquem a aplicação: põe lá um contacto real.'
            : ' — o servidor público do OSRM bloqueia uso pesado. Para mapas de zona e nacionais,'
              . ' corre um OSRM próprio (ver README).';
    }
    throw new Exception($mensagem);
}

// Matriz de tempos de viagem entre origens e destinos ([lat, lon]) com um só pedido /table.
// Devolve durações em segundos (null = sem rota) e a distância de cada origem à estrada mais próxima.
function osrm_tabela($origens, $destinos)
{
    $total = count($origens) + count($destinos);
    if (!$origens || !$destinos || $total > cfg('osrm_max_coords')) {
        throw new Exception("Pedido OSRM inválido ($total coordenadas).");
    }

    $coords = [];
    foreach (array_merge($origens, $destinos) as [$lat, $lon]) {
        $coords[] = coordenada_osrm($lat, $lon);
    }
    $nOrigens = count($origens);
    $caminho = '/table/v1/' . cfg('osrm_perfil') . '/' . implode(';', $coords)
        . '?sources=' . implode(';', range(0, $nOrigens - 1))
        . '&destinations=' . implode(';', range($nOrigens, $total - 1))
        . '&annotations=duration,distance';

    // a chave da cache não leva o servidor: a resposta é a mesma venha de onde vier
    $resultado = cache_ler('osrm-tabela' . $caminho, cfg('cache_ttl_osrm'));
    if ($resultado !== null) {
        return $resultado;
    }

    [$json] = pedir_ao_osrm($caminho);
    if (($json['code'] ?? '') !== 'Ok') {
        throw new Exception('OSRM: ' . ($json['message'] ?? $json['code'] ?? 'erro desconhecido'));
    }

    $resultado = [
        'duracoes' => $json['durations'],
        'distancias' => $json['distances'] ?? null,
        'origem_estrada_m' => array_column($json['sources'], 'distance'),
    ];
    cache_guardar('osrm-tabela' . $caminho, $resultado);
    return $resultado;
}

// Traduz uma manobra do OSRM para uma frase em português.
// O OSRM devolve as instruções em inglês (ou nada, conforme o servidor), por isso
// a frase é montada aqui a partir do tipo de manobra, do lado e do nome da rua.
function instrucao_passo($passo)
{
    $manobra = $passo['maneuver'] ?? [];
    $tipo = $manobra['type'] ?? '';
    $lado = $manobra['modifier'] ?? '';
    $rua = trim($passo['name'] ?? '');

    $lados = [
        'left' => 'à esquerda',
        'slight left' => 'ligeiramente à esquerda',
        'sharp left' => 'apertada à esquerda',
        'right' => 'à direita',
        'slight right' => 'ligeiramente à direita',
        'sharp right' => 'apertada à direita',
        'straight' => 'em frente',
        'uturn' => 'inversão de marcha',
    ];
    $direcao = $lados[$lado] ?? '';

    switch ($tipo) {
        case 'depart':
            $texto = 'Sair';
            break;
        case 'arrive':
            return 'Chegou ao destino' . ($rua !== '' ? " ($rua)" : '') . '.';
        case 'roundabout':
        case 'rotary':
            $saida = $manobra['exit'] ?? null;
            $texto = 'Na rotunda, sair' . ($saida ? " na $saida.ª saída" : '');
            break;
        case 'merge':
            $texto = 'Entrar na via ' . $direcao;
            break;
        case 'on ramp':
            $texto = 'Pegar o acesso ' . $direcao;
            break;
        case 'off ramp':
            $texto = 'Sair ' . $direcao;
            break;
        case 'fork':
            $texto = 'Na bifurcação, manter-se ' . $direcao;
            break;
        case 'end of road':
            $texto = 'No fim da rua, virar ' . $direcao;
            break;
        case 'continue':
            $texto = $lado === 'straight' || $lado === '' ? 'Continuar em frente' : 'Continuar ' . $direcao;
            break;
        case 'new name':
            $texto = 'Continuar';
            break;
        default: // turn e o resto
            $texto = 'Virar ' . ($direcao !== '' ? $direcao : 'em frente');
    }

    $texto = trim($texto);
    if ($rua !== '') {
        $texto .= ' para ' . $rua;
    }
    return $texto . '.';
}

// Percurso completo entre dois pontos, para desenhar no mapa
function osrm_rota($lat1, $lon1, $lat2, $lon2)
{
    $caminho = '/route/v1/' . cfg('osrm_perfil') . '/'
        . coordenada_osrm($lat1, $lon1) . ';' . coordenada_osrm($lat2, $lon2)
        . '?overview=full&geometries=geojson&steps=true';

    $resultado = cache_ler('osrm-rota' . $caminho, cfg('cache_ttl_osrm'));
    if ($resultado !== null) {
        return $resultado;
    }

    [$json] = pedir_ao_osrm($caminho);
    if (($json['code'] ?? '') !== 'Ok' || empty($json['routes'])) {
        throw new Exception('Não foi encontrado percurso por estrada.');
    }

    $rota = $json['routes'][0];

    // instruções passo a passo, para quem vai a conduzir ou quer imprimir o caminho
    $passos = [];
    foreach ($rota['legs'] ?? [] as $troco) {
        foreach ($troco['steps'] ?? [] as $passo) {
            $metros = $passo['distance'] ?? 0;
            if ($metros < 15 && ($passo['maneuver']['type'] ?? '') !== 'arrive') {
                continue; // manobras de poucos metros só poluem a lista
            }
            $passos[] = [
                'texto' => instrucao_passo($passo),
                'metros' => round($metros),
                'segundos' => round($passo['duration'] ?? 0),
            ];
        }
    }

    $resultado = [
        'minutos' => round($rota['duration'] / 60, 1),
        'km' => round($rota['distance'] / 1000, 1),
        'passos' => $passos,
        // GeoJSON vem em [lon, lat]; o Leaflet quer [lat, lon]
        'linha' => array_map(function ($c) {
            return [$c[1], $c[0]];
        }, $rota['geometry']['coordinates']),
    ];
    cache_guardar('osrm-rota' . $caminho, $resultado);
    return $resultado;
}
