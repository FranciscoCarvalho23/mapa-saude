'use strict';

// Guarda a aplicação e os dados no dispositivo, para a página abrir sem ligação.
// Num apagão, num sítio sem rede ou com a rede congestionada, continua a dar para ver
// qual é a urgência mais próxima e a que distância fica.

const VERSAO = 'mapa-saude-v4';
const ESSENCIAIS = [
    'index.php',
    'assets/icone.svg',
    'assets/css/style.css',
    'assets/js/nucleo.js',
    'assets/js/contornos.js',
    'assets/js/mapa.js',
    'assets/js/local.js',
    'assets/js/moradas.js',
    'assets/js/zonas.js',
    'assets/js/pessoas.js',
    'assets/js/dados.js',
    'assets/js/app.js',
    'api/unidades.php',
    // o retrato nacional sai de ficheiros já gerados, por isso funciona sem rede
    'api/populacao.php?accao=resumo',
    'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js',
    'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        // addAll falha inteiro se um recurso falhar; um a um é mais tolerante
        caches.open(VERSAO).then((cache) => Promise.allSettled(
            ESSENCIAIS.map((url) => cache.add(new Request(url, { cache: 'reload' })))
        )).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches.keys()
            .then((nomes) => Promise.all(nomes.filter((n) => n !== VERSAO).map((n) => caches.delete(n))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (evento) => {
    const pedido = evento.request;
    if (pedido.method !== 'GET') return;

    const url = new URL(pedido.url);
    const mesmaOrigem = url.origin === self.location.origin;

    // Tiles do mapa: só se usa o que já lá estiver, nunca se enche a cache com eles.
    if (url.hostname.endsWith('tile.openstreetmap.org')) {
        evento.respondWith(caches.match(pedido).then((r) => r || fetch(pedido)));
        return;
    }

    // Endpoints que dependem do OSRM/Nominatim: sem rede não há resposta possível,
    // e uma resposta velha seria enganadora. Deixa falhar, a página trata disso.
    if (mesmaOrigem && /\/api\/(tempos|rota|geocode|grelha|influencia|planeamento)\.php/.test(url.pathname)) {
        return;
    }

    // A otimização normal sai de ficheiros locais e pode ser guardada; a confirmação
    // com o OSRM depende da rede, e uma resposta velha aí seria enganadora.
    if (mesmaOrigem && url.pathname.endsWith('/api/otimizar.php') && url.searchParams.get('confirmar') === '1') {
        return;
    }

    // Dados e aplicação: rede primeiro (para apanhar atualizações), cache como rede de segurança.
    evento.respondWith(
        fetch(pedido)
            .then((resposta) => {
                if (resposta && resposta.ok && mesmaOrigem) {
                    const copia = resposta.clone();
                    caches.open(VERSAO).then((cache) => cache.put(pedido, copia)).catch(() => {});
                }
                return resposta;
            })
            .catch(() => caches.match(pedido).then((guardada) => {
                if (guardada) return guardada;
                // Só a navegação pode cair na página principal. Devolver o HTML a um pedido
                // de script ou de folha de estilo partia a aplicação com um erro de sintaxe
                // ("Unexpected token '<'") em vez de a deixar falhar de forma limpa.
                if (pedido.mode === 'navigate') return caches.match('index.php');
                return Response.error();
            }))
    );
});
