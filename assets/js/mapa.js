'use strict';

// Mapa, camadas e desenho das unidades de saúde.

const mapa = L.map('mapa', { zoomSnap: 0.5, zoomControl: true }).fitBounds(LIMITES_PORTUGAL);

// as quadrículas ficam por baixo dos marcadores
mapa.createPane('grelha').style.zIndex = 350;
const rendererGrelha = L.canvas({ pane: 'grelha' });
// Um só canvas para unidades, ponto escolhido e percurso. Canvas é bem mais leve que SVG com
// milhares de pontos, e vários canvas/SVG sobrepostos bloqueiam os cliques uns dos outros.
const rendererMapa = L.canvas({ padding: 0.5 });
const tooltipGrelha = L.tooltip({ direction: 'top', offset: [0, -6] });

const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATRIBUICAO = '&copy; <a href="https://www.openstreetmap.org/copyright">contribuidores do OpenStreetMap</a>';
const camadaTiles = L.tileLayer(OSM_TILES, { maxZoom: 19, attribution: OSM_ATRIBUICAO, className: 'tiles' }).addTo(mapa);

// O mapa de fundo é sempre o mesmo; muda-lhe o filtro CSS conforme o tema, para
// os dados sobressaírem em vez de competirem com as cores das ruas.
function aplicarTemaAoMapa(tema) {
    const el = camadaTiles.getContainer();
    if (el) el.classList.toggle('tiles-escuro', tema === 'escuro');
}

const camadasTipo = {};
const overlays = {};
TIPOS.forEach((tipo) => {
    camadasTipo[tipo] = L.layerGroup().addTo(mapa);
    overlays[`<span class="bola" style="--cor:${ESTILO_TIPO[tipo].cor}"></span>${CONFIG.tipos[tipo]}`] = camadasTipo[tipo];
});

L.control.layers(null, overlays, { collapsed: window.innerWidth < 800 }).addTo(mapa);
L.control.scale({ imperial: false }).addTo(mapa);

mapa.on('click', (e) => escolherPonto(e.latlng.lat, e.latlng.lng));

// ---------- unidades ----------

const marcadores = new Map(); // id -> [{ marcador, tipo }]

function estiloUnidade(unidade, tipo) {
    const base = ESTILO_TIPO[tipo];
    const naoConfirmada = tipo === 'urgencia' && unidade.urgencia !== 'sim';
    return {
        radius: base.raio,
        color: base.cor,
        weight: naoConfirmada ? 2 : 1,
        fillColor: naoConfirmada ? '#FFFFFF' : base.cor,
        fillOpacity: 0.9,
    };
}

function criarPopup(unidade) {
    const div = document.createElement('div');
    div.className = 'popup';

    let html = `<strong>${esc(unidade.nome)}</strong>`;
    html += `<span>${esc(rotuloTipos(unidade.tipos))}</span>`;
    if (unidade.localidade) html += `<span>${esc(unidade.localidade)}</span>`;
    if (unidade.operador) html += `<span>${esc(unidade.operador)}</span>`;
    if (unidade.tipos.includes('urgencia') && unidade.urgencia !== 'sim') {
        html += '<span class="aviso">Urgência não confirmada no OpenStreetMap</span>';
    }
    if (estado.ponto) {
        const km = distanciaKm(estado.ponto.lat, estado.ponto.lon, unidade.lat, unidade.lon);
        html += `<span>${numero(km)} km em linha reta do ponto escolhido</span>`;
    }
    div.innerHTML = html;

    const acoes = document.createElement('div');
    acoes.className = 'popup-acoes';

    const btInfluencia = document.createElement('button');
    btInfluencia.type = 'button';
    btInfluencia.textContent = 'Ver área que serve';
    btInfluencia.addEventListener('click', () => {
        mapa.closePopup();
        verInfluencia(unidade);
    });
    acoes.appendChild(btInfluencia);

    const btDaqui = document.createElement('button');
    btDaqui.type = 'button';
    btDaqui.className = 'secundario';
    btDaqui.textContent = 'Analisar aqui';
    btDaqui.addEventListener('click', () => {
        mapa.closePopup();
        escolherPonto(unidade.lat, unidade.lon, unidade.nome);
    });
    acoes.appendChild(btDaqui);
    div.appendChild(acoes);

    if (/^(node|way|relation)\//.test(unidade.id)) {
        const link = document.createElement('a');
        link.href = `https://www.openstreetmap.org/${unidade.id}`;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Corrigir no OpenStreetMap';
        div.appendChild(link);
    }
    return div;
}

function desenharUnidades(unidades) {
    unidades.forEach((unidade) => {
        if (marcadores.has(unidade.id)) return;
        const lista = [];
        unidade.tipos.forEach((tipo) => {
            if (!camadasTipo[tipo]) return;
            const marcador = L.circleMarker([unidade.lat, unidade.lon], {
                renderer: rendererMapa,
                bubblingMouseEvents: false, // clicar numa unidade não escolhe o ponto de partida
                ...estiloUnidade(unidade, tipo),
            });
            marcador.bindTooltip(esc(unidade.nome)); // o Leaflet insere texto de tooltips como HTML
            marcador.bindPopup(() => criarPopup(unidade));
            marcador.addTo(camadasTipo[tipo]);
            lista.push({ marcador, tipo });
        });
        marcadores.set(unidade.id, lista);
    });
}

function focarUnidade(unidade) {
    mapa.setView([unidade.lat, unidade.lon], 14);
    const lista = marcadores.get(unidade.id);
    if (lista && lista.length) lista[0].marcador.openPopup();
}

// ---------- camadas de trabalho (ponto, percurso, quadrículas) ----------

const camadas = {
    ponto: null,
    rota: null,
    grelha: null,     // { camada, raster, rasters, tipo, origem, params }
    proposta: null,
    pessoas: null,    // círculos de população por quadrícula
    criticas: null,   // manchas críticas numeradas (ranking nacional)
    propostas: null,  // locais propostos pelo otimizador
};

function marcarPonto(lat, lon) {
    if (camadas.ponto) camadas.ponto.remove();
    camadas.ponto = L.circleMarker([lat, lon], {
        renderer: rendererMapa,
        radius: 9,
        color: '#1C2B36',
        weight: 4,
        fillColor: '#FFFFFF',
        fillOpacity: 1,
        interactive: false,
    }).addTo(mapa).bringToFront();
}

function limparRota() {
    if (camadas.rota) {
        camadas.rota.remove();
        camadas.rota = null;
    }
    $('bloco-percurso').hidden = true;
    $('percurso').innerHTML = '';
}

function limparGrelha() {
    if (camadas.grelha) {
        camadas.grelha.camada.remove();
        camadas.grelha = null;
        mapa.closeTooltip(tooltipGrelha);
    }
    if (camadas.proposta) {
        camadas.proposta.remove();
        camadas.proposta = null;
    }
    limparPessoas();
}


// ---------- pessoas e zonas críticas no mapa ----------
//
// As manchas de cor respondem a "quanto tempo daqui até um hospital". Não respondem à
// pergunta seguinte, que é a que decide investimento: "onde é que isso afeta muita
// gente". Uma mancha vermelha enorme na serra e uma mancha vermelha pequena à volta de
// uma vila de 8 mil pessoas pintam igual e não valem o mesmo.
//
// Estes círculos põem a população por cima do mapa de acesso. O tamanho é a gente que
// lá vive; a cor é a gravidade do acesso.

// Escala de gravidade para o modo crítico: do limiar para cima, quanto mais tempo pior.
const CORES_CRITICO = ['#E8A33D', '#D4663F', '#B0364A', '#7D1F31'];

function corCritica(minutos, limite) {
    const excesso = minutos - limite;
    if (excesso <= 10) return CORES_CRITICO[0];
    if (excesso <= 30) return CORES_CRITICO[1];
    if (excesso <= 60) return CORES_CRITICO[2];
    return CORES_CRITICO[3];
}

function limparPessoas() {
    if (camadas.pessoas) {
        camadas.pessoas.remove();
        camadas.pessoas = null;
    }
}

function limparCriticas() {
    if (camadas.criticas) {
        camadas.criticas.remove();
        camadas.criticas = null;
    }
}

// Desenha um círculo por quadrícula com gente.
//   modo 'pessoas'  — toda a população, cor pela classe de acesso
//   modo 'critico'  — só o que está acima do limiar, cor pela gravidade
//
// Devolve o que foi desenhado, para o painel poder escrever a legenda.
function desenharPessoas(rasters, tipo, modo = 'pessoas') {
    limparPessoas();
    const lista = Array.isArray(rasters) ? rasters : [rasters];
    const [, limite] = CONFIG.limiares[tipo];

    // recolhe as células com gente (e, no modo crítico, só as que estão acima do limiar)
    const celulas = [];
    let maxPessoas = 0;
    lista.forEach((r) => {
        if (!Array.isArray(r.populacao)) return;
        r.populacao.forEach((gente, k) => {
            if (!gente) return;
            const minutos = r.valores[k];
            if (modo === 'critico' && (minutos === null || minutos === undefined || minutos <= limite)) return;
            const j = Math.floor(k / r.largura);
            const i = k % r.largura;
            celulas.push({
                lat: r.sul + (j + 0.5) * r.dLat,
                lon: r.oeste + (i + 0.5) * r.dLon,
                gente,
                gente65: (r.populacao_65 && r.populacao_65[k]) || 0,
                minutos,
            });
            if (gente > maxPessoas) maxPessoas = gente;
        });
    });

    if (!celulas.length) return { celulas: 0, pessoas: 0, maxPessoas: 0 };

    // Raio pela raiz quadrada: a ÁREA do círculo fica proporcional à população, que é
    // como o olho lê um círculo. Com o raio proporcional, uma vila parecia uma cidade.
    const raioMax = modo === 'critico' ? 26 : 18;
    const raio = (gente) => Math.max(3, Math.sqrt(gente / maxPessoas) * raioMax);

    const camada = L.layerGroup();
    let totalPessoas = 0;
    let total65 = 0;

    celulas.sort((a, b) => b.gente - a.gente); // os maiores primeiro, para ficarem por baixo
    celulas.forEach((c) => {
        totalPessoas += c.gente;
        total65 += c.gente65;
        const cor = modo === 'critico'
            ? corCritica(c.minutos, limite)
            : CORES_CLASSE[classificar(tipo, c.minutos)];

        const circulo = L.circleMarker([c.lat, c.lon], {
            renderer: rendererMapa,
            radius: raio(c.gente),
            color: '#FFFFFF',
            weight: 1,
            opacity: 0.8,
            fillColor: cor,
            fillOpacity: modo === 'critico' ? 0.75 : 0.6,
        });
        const tempo = (c.minutos === null || c.minutos === undefined)
            ? 'sem rota por estrada'
            : `${minutosTexto(c.minutos)} min até ${COM_ARTIGO[tipo]}`;
        circulo.bindTooltip(
            `<strong>${pessoasTexto(c.gente)} pessoas</strong><br>${tempo}`
            + (c.gente65 ? `<br>${pessoasTexto(c.gente65)} com 65+` : ''),
            { direction: 'top' }
        );
        camada.addLayer(circulo);
    });

    camada.addTo(mapa);
    camadas.pessoas = camada;
    if (camadas.ponto) camadas.ponto.bringToFront();

    return { celulas: celulas.length, pessoas: totalPessoas, pessoas65: total65, maxPessoas, modo };
}

// Marca no mapa as manchas críticas do ranking nacional, numeradas pela ordem da lista.
function desenharCriticas(zonas, tipo) {
    limparCriticas();
    if (!zonas || !zonas.length) return;

    const camada = L.layerGroup();
    zonas.forEach((z, i) => {
        L.marker([z.lat, z.lon], {
            icon: L.divIcon({
                className: 'marca-critica',
                html: String(i + 1),
                iconSize: [26, 26],
                iconAnchor: [13, 13],
            }),
            title: `${pessoasTexto(z.pessoas)} pessoas a ${minutosTexto(z.minutos_medios)} min`,
        }).bindPopup(
            `<strong>#${i + 1} — ${pessoasTexto(z.pessoas)} pessoas</strong><br>`
            + `${minutosTexto(z.minutos_medios)} min em média até ${COM_ARTIGO[tipo]}<br>`
            + `pior ponto: ${minutosTexto(z.minutos_pior)} min<br>`
            + `${pessoasTexto(z.pessoas_65)} com 65 ou mais anos`
        ).addTo(camada);
    });
    camada.addTo(mapa);
    camadas.criticas = camada;
}

// Vários rasters (o mapa nacional traz um por região) desenhados como um só conjunto.
function desenharManchasMulti(rasters, tipo, opcoes = {}) {
    desenharManchas(rasters[0], tipo, opcoes);
    rasters.slice(1).forEach((r) => juntarManchas(r, tipo, opcoes));
}

// Desenha as manchas de acesso a partir de um raster de tempos.
//
// Em vez de um quadrado por célula, desenha-se o contorno de cada classe: a área onde se
// chega em menos de X minutos é uma mancha só, com a fronteira interpolada entre células e
// depois suavizada. As quadrículas eram um artefacto do cálculo; isto mostra a mesma
// informação sem fingir que o acesso muda em degraus retos de 5 km.
function desenharManchas(raster, tipo, opcoes = {}) {
    limparGrelha();
    const camada = L.layerGroup();
    const [bom, limite] = CONFIG.limiares[tipo];

    const pintar = (aneis, cor, opacidade) => {
        if (!aneis.length) return;
        L.polygon(aneis, {
            renderer: rendererGrelha,
            interactive: false,
            stroke: false,
            fillColor: cor,
            fillOpacity: opacidade,
            fillRule: 'evenodd', // anéis encaixados viram buracos
        }).addTo(camada);
    };

    if (opcoes.destacar) {
        // área de influência: o território fica esbatido e só a parte servida ganha cor
        pintar(manchasDoTerritorio(raster), CORES_CLASSE.sem_dados, 0.15);
        const mascara = { ...raster, valores: raster.dela.map((d) => (d ? 0 : null)) };
        pintar(manchasDoLimiar(mascara, 0.5), ESTILO_TIPO[tipo].cor, 0.45);
    } else {
        // do pior para o melhor: cada mancha tapa a anterior
        pintar(manchasDoTerritorio(raster), CORES_CLASSE.deserto, 0.5);

        // território sem tempo calculado (longe de estrada, ou sem rota)
        const semDados = {
            ...raster,
            valores: raster.valores.map((v, k) => (raster.terra[k] && v === null ? 0 : null)),
        };
        pintar(manchasDoLimiar(semDados, 0.5), CORES_CLASSE.sem_dados, 0.5);

        pintar(manchasDoLimiar(raster, limite), CORES_CLASSE.limitado, 0.55);
        pintar(manchasDoLimiar(raster, bom), CORES_CLASSE.bom, 0.6);
    }

    camada.addTo(mapa);
    camadas.grelha = { camada, raster, rasters: [raster], tipo, origem: opcoes.origem || null, params: opcoes.params || null };
    if (camadas.ponto) camadas.ponto.bringToFront();
}

// Acrescenta as manchas de outro raster à camada que já está no mapa
function juntarManchas(raster, tipo, opcoes = {}) {
    if (!camadas.grelha) return;
    const guardada = camadas.grelha;
    const camadaAtual = guardada.camada;
    camadas.grelha = null; // desenharManchas limpa a camada; guarda-se e repõe-se
    desenharManchas(raster, tipo, opcoes);
    camadas.grelha.camada.eachLayer((l) => camadaAtual.addLayer(l));
    camadas.grelha.camada.remove();
    camadaAtual.addTo(mapa);
    camadas.grelha = {
        ...guardada,
        camada: camadaAtual,
        rasters: [...guardada.rasters, raster],
    };
}

// Um só tooltip: lê o valor do raster debaixo do rato. As manchas não são interativas
// (seriam milhares de pontos a apanhar eventos), por isso o valor vem da grelha.
mapa.on('mousemove', (e) => {
    if (!camadas.grelha) return;
    let valor;
    for (const r of camadas.grelha.rasters) {
        const v = valorNoPonto(r, e.latlng.lat, e.latlng.lng);
        if (v !== undefined) {
            valor = v;
            break;
        }
    }
    if (valor === undefined) {
        mapa.closeTooltip(tooltipGrelha);
        return;
    }
    tooltipGrelha.setContent(valor === null ? 'Sem estrada próxima' : duracaoTexto(valor));
    mapa.openTooltip(tooltipGrelha, e.latlng);
});
mapa.on('mouseout', () => mapa.closeTooltip(tooltipGrelha));