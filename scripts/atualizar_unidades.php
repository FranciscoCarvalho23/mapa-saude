<?php
// Gera data/unidades.json com as unidades de saúde de todo o país.
//
// A página lê esse ficheiro e mais nada: não fala com o Overpass. Como abrem e fecham poucas
// unidades por ano, basta correr isto de vez em quando (uma vez por trimestre chega bem).
//
// Tenta por esta ordem:
//   1. uma query única para o país inteiro  -> 1 pedido, é o caminho normal
//   2. bloco a bloco (~78 quadrados)        -> só se a primeira falhar
//
// Uso:
//   php scripts/atualizar_unidades.php                 atualiza e grava o ficheiro
//   php scripts/atualizar_unidades.php --blocos-so     salta a query única e vai logo por blocos
//   php scripts/atualizar_unidades.php --tudo          ignora a cache dos blocos
//   php scripts/atualizar_unidades.php --mesmo-assim   grava mesmo com zonas em falta
//   php scripts/atualizar_unidades.php --estado        mostra que servidores estão disponíveis
//   php scripts/atualizar_unidades.php --query         mostra a query do país, para o Overpass Turbo
//   php scripts/atualizar_unidades.php --query <bloco> mostra a query de um bloco
//   php scripts/atualizar_unidades.php ficheiro.json   importa um export do Overpass Turbo
//   php scripts/atualizar_unidades.php --lista-blocos  lista os blocos e o estado da cache
//   php scripts/atualizar_unidades.php --diagnostico <f.json>  explica o que foi aceite e descartado

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser corrido na linha de comandos.');
}

require_once __DIR__ . '/../includes/dados_osm.php';
set_time_limit(0);

$argumentos = array_slice($argv, 1);
$opcao = function ($nome) use ($argumentos) {
    return in_array($nome, $argumentos, true);
};

function resumir_e_gravar($unidades, $origem, $avisoFinal = '')
{
    $guardado = guardar_unidades($unidades, $origem);

    $contagem = array_fill_keys(tipos(), 0);
    $confirmadas = 0;
    foreach ($unidades as $u) {
        foreach ($u['tipos'] as $tipo) {
            $contagem[$tipo]++;
        }
        if (in_array('urgencia', $u['tipos'], true) && $u['urgencia'] === 'sim') {
            $confirmadas++;
        }
    }

    $tamanho = round(filesize(ficheiro_unidades()) / 1024);
    echo "\nGravado data/unidades.json ({$tamanho} KB, {$guardado['total']} unidades):\n";
    echo "  {$contagem['urgencia']} urgências ($confirmadas confirmadas no OSM)\n";
    echo "  {$contagem['centro_saude']} centros de saúde\n";
    echo "  {$contagem['maternidade']} maternidades\n";
    if ($avisoFinal) {
        echo "\n$avisoFinal\n";
    }
    echo "\nJá podes abrir a página. Convém fazer commit do data/unidades.json.\n";
}

function ajuda_manual()
{
    echo "\nSe o Overpass continuar a recusar, faz à mão (leva 2 minutos e nunca falha):\n";
    echo "  1. php scripts/atualizar_unidades.php --query > query.txt\n";
    echo "  2. abre https://overpass-turbo.eu, cola o conteúdo de query.txt e carrega em Executar\n";
    echo "  3. Exportar > Dados > \"dados OSM em bruto diretamente da Overpass API\" e guarda o .json\n";
    echo "  4. php scripts/atualizar_unidades.php caminho/para/esse.json\n";
}

// ---------- comandos que não descarregam nada ----------

if ($opcao('--lista-blocos')) {
    foreach (blocos_relevantes() as $chave) {
        [$sul, $oeste, $norte, $este] = bloco_caixa($chave);
        printf("%-12s %6.2f,%7.2f a %6.2f,%7.2f  %s\n", $chave, $sul, $oeste, $norte, $este, bloco_em_cache($chave) ? 'em cache' : '-');
    }
    echo "\n" . count(blocos_relevantes()) . " blocos com território português.\n";
    exit(0);
}

if ($opcao('--estado')) {
    echo "Servidores Overpass:\n";
    foreach (cfg('overpass_urls') as $url) {
        $host = parse_url($url, PHP_URL_HOST);
        $espera = segundos_de_descanso($url);
        printf("  %-28s %s\n", $host, $espera > 0 ? "em espera mais $espera s" : 'disponível');
    }
    $ficheiro = ficheiro_unidades();
    echo "\ndata/unidades.json: " . (is_file($ficheiro)
        ? count(json_decode(file_get_contents($ficheiro), true)['unidades'] ?? []) . ' unidades, de ' . date('Y-m-d H:i', filemtime($ficheiro))
        : 'ainda não existe') . "\n";
    $emCache = count(array_filter(blocos_relevantes(), 'bloco_em_cache'));
    echo 'Blocos em cache: ' . $emCache . ' de ' . count(blocos_relevantes()) . "\n";
    exit(0);
}

if ($opcao('--query')) {
    $posicao = array_search('--query', $argumentos, true);
    $bloco = $argumentos[$posicao + 1] ?? null;
    if ($bloco !== null && bloco_caixa($bloco) !== null) {
        echo query_overpass(bloco_caixa($bloco)) . "\n";
    } else {
        echo query_overpass_pais() . "\n";
    }
    exit(0);
}

if ($opcao('--diagnostico')) {
    $posicao = array_search('--diagnostico', $argumentos, true);
    $ficheiro = $argumentos[$posicao + 1] ?? null;
    if ($ficheiro === null || !is_file($ficheiro)) {
        echo "Uso: php scripts/atualizar_unidades.php --diagnostico <export-do-overpass.json>\n";
        exit(1);
    }
    $json = json_decode(file_get_contents($ficheiro), true);
    processar_overpass($json, $c);

    echo "Elementos no ficheiro do Overpass: {$c['elementos']}\n\n";
    echo "  aceites como unidade de saúde: {$c['aceites']}\n";
    echo "  descartados por não terem nome: {$c['sem_nome']}\n";
    echo "  descartados por serem veterinária: {$c['veterinaria']}\n";
    echo "  descartados sem coordenadas: {$c['sem_coordenadas']}\n";
    echo "  com nome, mas que as regras não reconhecem: {$c['nao_classificados']}\n";

    if ($c['exemplos_descartados']) {
        echo "\nExemplos dos não reconhecidos (clínicas privadas, dentistas, etc.):\n";
        foreach ($c['exemplos_descartados'] as $nome) {
            echo "  - $nome\n";
        }
        echo "\nSe vires aqui unidades do SNS que deviam contar, o padrão do nome pode ser\n";
        echo "acrescentado em includes/dados_osm.php (classificar_elemento), ou a unidade pode\n";
        echo "ser adicionada à mão em data/correcoes.json.\n";
    }
    exit(0);
}

// ---------- importar um export do Overpass Turbo ----------

$ficheiroImportar = null;
foreach ($argumentos as $a) {
    if (substr($a, 0, 2) !== '--') {
        $ficheiroImportar = $a;
        break;
    }
}

if ($ficheiroImportar !== null) {
    if (!is_file($ficheiroImportar)) {
        echo "Ficheiro não encontrado: $ficheiroImportar\n";
        exit(1);
    }
    $json = json_decode(file_get_contents($ficheiroImportar), true);
    if (!isset($json['elements'])) {
        echo "O ficheiro não tem dados do Overpass em JSON (falta a lista \"elements\").\n";
        echo "No Overpass Turbo, exporta em \"dados OSM em bruto diretamente da Overpass API\".\n";
        exit(1);
    }
    $unidades = fundir_unidades([processar_overpass($json)]);
    if (!$unidades) {
        echo "O ficheiro tem " . count($json['elements']) . " elementos, mas nenhum é uma unidade de saúde reconhecida.\n";
        echo "Confirma que usaste a query de php scripts/atualizar_unidades.php --query\n";
        exit(1);
    }
    echo 'Importadas ' . count($unidades) . " unidades de $ficheiroImportar.\n";
    resumir_e_gravar($unidades, 'importado do Overpass Turbo');
    exit(0);
}

// ---------- 1.ª tentativa: uma query para o país inteiro ----------

if (!$opcao('--blocos-so')) {
    echo "A pedir o país inteiro numa query só (pode demorar alguns minutos)...\n";
    try {
        $unidades = descarregar_pais();
        resumir_e_gravar($unidades, 'overpass (query única)');
        exit(0);
    } catch (Exception $e) {
        echo '  não deu: ' . $e->getMessage() . "\n";
        echo "  Vou tentar bloco a bloco.\n\n";
    }
}

// ---------- 2.ª tentativa: bloco a bloco ----------

$todos = blocos_relevantes();
$porFazer = $opcao('--tudo') ? $todos : array_values(array_filter($todos, function ($c) {
    return !bloco_em_cache($c);
}));

echo count($todos) . ' blocos no total, ' . count($porFazer) . " por carregar.\n\n";

$falhados = $porFazer;
for ($ronda = 1; $ronda <= 3 && $falhados; $ronda++) {
    if ($ronda > 1) {
        // espera pelo menos o que os próprios servidores pediram
        $pedido = 0;
        foreach (cfg('overpass_urls') as $url) {
            $pedido = max($pedido, segundos_de_descanso($url));
        }
        $espera = max(cfg('overpass_espera') * ($ronda - 1), $pedido + 5);
        echo "\nRonda $ronda para " . count($falhados) . " blocos. A esperar $espera s...\n";
        sleep($espera);
    }

    $restantes = [];
    $seguidas = 0; // falhas seguidas: se forem muitas, o IP está bloqueado e insistir só piora
    foreach ($falhados as $n => $chave) {
        printf('[%d/%d] %-12s ', $n + 1, count($falhados), $chave);
        cache_apagar("bloco_falha_$chave");
        try {
            $unidades = descarregar_bloco($chave);
            cache_guardar("bloco_$chave", ['atualizado_em' => date('c'), 'unidades' => $unidades]);
            echo count($unidades) . " unidades\n";
            $seguidas = 0;
        } catch (Exception $e) {
            echo 'falhou: ' . $e->getMessage() . "\n";
            $restantes[] = $chave;

            // todos em espera: não vale a pena percorrer os blocos seguintes para ver o mesmo
            $todosEmEspera = strpos($e->getMessage(), 'estão em espera') !== false;
            if ($todosEmEspera || ++$seguidas >= 8) {
                echo $todosEmEspera
                    ? "\nTodos os servidores estão em espera. Paro a ronda aqui.\n"
                    : "\n8 falhas seguidas: os servidores estão a recusar os pedidos deste IP.\n"
                        . "Paro a ronda aqui em vez de insistir (insistir prolonga o bloqueio).\n";
                $restantes = array_merge($restantes, array_slice($falhados, $n + 1));
                break;
            }
        }
    }
    $falhados = array_values(array_unique($restantes));
}

$dados = obter_unidades_pais(false);

if (!$dados['unidades']) {
    echo "\nNão foi possível descarregar nada. O ficheiro não foi alterado.\n";
    ajuda_manual();
    exit(1);
}

// Com blocos em falta, o ficheiro ficaria com buracos: só se grava se estiver tudo,
// ou se quem está a correr o script disser que sabe o que está a fazer.
if ($falhados && !$opcao('--mesmo-assim')) {
    echo "\n" . count($falhados) . ' de ' . count($todos) . " blocos sem dados.\n";
    echo "O ficheiro data/unidades.json NÃO foi alterado, para não ficar com zonas em branco.\n";
    echo 'Já estão em cache ' . count($dados['blocos']) . " blocos; voltar a correr só pede os que faltam.\n";
    ajuda_manual();
    echo "\nOu grava assim mesmo, com essas zonas em falta:\n";
    echo "  php scripts/atualizar_unidades.php --mesmo-assim\n";
    exit(1);
}

resumir_e_gravar(
    $dados['unidades'],
    'overpass (blocos)',
    $falhados ? 'Aviso: gravado com ' . count($falhados) . ' zonas em falta (--mesmo-assim).' : ''
);
