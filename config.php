<?php
// Configuração do projeto.
// Para alterar valores sem mexer neste ficheiro (ex.: usar um servidor OSRM próprio),
// cria um config.local.php que devolva um array só com as chaves a mudar.

$CONFIG = [
    // As APIs públicas do OSM pedem um User-Agent que identifique a aplicação
    'user_agent' => 'MapaAcessoSaude/1.0 (projeto academico; contacto: altera-este-email@exemplo.com)',

    // APIs externas (todas usam dados do OpenStreetMap)
    // tentados por ordem; lista de servidores públicos: wiki.openstreetmap.org/wiki/Overpass_API
    'overpass_urls' => [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ],
    'overpass_espera' => 45,  // segundos mínimos entre rondas de tentativas
    // Quanto tempo um servidor fica de lado depois de falhar. Quando a resposta diz
    // "Slot available after: ..., in N seconds", é esse número que manda.
    'overpass_descansos' => [
        'recusado' => 120,       // erro 429 sem indicação de tempo
        'sobrecarregado' => 180, // erro 504 ou query sem tempo para terminar
        'sem_ligacao' => 60,     // não foi possível ligar
    ],
    'overpass_timeout_bloco' => 60,  // [timeout:...] da query de cada bloco
    'overpass_timeout_pais' => 300,  // [timeout:...] da query única do país inteiro

    // Caixas [sul, oeste, norte, este] usadas na query única do país
    'regioes' => [
        'continente' => [36.90, -9.60, 42.20, -6.10],
        'madeira' => [32.30, -17.35, 33.20, -16.20],
        'acores' => [36.90, -31.40, 39.80, -24.90],
    ],
    'nominatim_url' => 'https://nominatim.openstreetmap.org',
    // Servidores de rotas, tentados por ordem. O primeiro é o de demonstração do OSRM:
    // exige um User-Agent que identifique a aplicação (ver acima) e proíbe uso pesado —
    // um mapa nacional é uso pesado e acaba em 403. Para isso, corre um OSRM próprio
    // (ver README) e põe 'http://localhost:5000' no topo desta lista.
    'osrm_urls' => [
        'https://router.project-osrm.org',
        'https://routing.openstreetmap.de/routed-car',
    ],
    'osrm_perfil' => 'driving',
    'osrm_max_coords' => 100, // máximo de coordenadas por pedido /table

    // Intervalo mínimo entre pedidos (segundos) - regra dos servidores públicos
    'intervalo_nominatim' => 1.0,
    'intervalo_osrm' => 1.0,
    'intervalo_overpass' => 2.0, // os servidores públicos limitam por IP; com 1 s apanhava-se 429

    // Carregamento por blocos: o mapa pede as unidades por zonas, das mais próximas
    // para as mais longínquas, em vez de pedir o país inteiro de uma vez.
    'bloco_graus' => 0.5,          // lado de cada bloco (~55 km); menor = mais rápido a aparecer, mais pedidos
    'blocos_por_pedido' => 30,     // máximo de blocos que a API aceita num pedido

    // Cache em ficheiros
    'cache_dir' => __DIR__ . '/cache',
    'cache_ttl_bloco' => 7 * 24 * 3600,
    'cache_ttl_bloco_falha' => 300, // depois de uma falha, não repete o pedido desse bloco durante este tempo
    'cache_ttl_geocode' => 30 * 24 * 3600,
    'cache_ttl_osrm' => 7 * 24 * 3600,

    // Análise
    'candidatos_por_tipo' => 5,    // unidades mais próximas em linha reta enviadas ao OSRM
    'max_comparacao' => 4,         // moradas que podem ser comparadas ao mesmo tempo
    'candidatos_planeamento' => 3, // locais testados ao procurar onde falta uma unidade
    'max_snap_metros' => 3000,     // quadrícula a mais do que isto de uma estrada fica "sem dados"
    'max_celulas_zona' => 150,     // limite de quadrículas por pedido na análise de zona
    'urgencia_inclui_sem_tag' => true, // contar hospitais sem a tag emergency como urgência

    'tipos' => [
        'urgencia' => 'Urgência',
        'centro_saude' => 'Centro de saúde',
        'maternidade' => 'Maternidade',
    ],

    // [bom acesso até X min, acesso limitado até Y min]; acima de Y = deserto de saúde
    'limiares' => [
        'urgencia' => [30, 60],
        'centro_saude' => [15, 30],
        'maternidade' => [45, 60],
    ],
];

if (file_exists(__DIR__ . '/config.local.php')) {
    $CONFIG = array_replace($CONFIG, require __DIR__ . '/config.local.php');
}

function cfg($chave)
{
    global $CONFIG;
    return $CONFIG[$chave] ?? null;
}
