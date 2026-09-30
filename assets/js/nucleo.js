'use strict';

// Base partilhada por todos os outros ficheiros: estado, utilitários, armazenamento,
// tema e separadores. Carregado em primeiro lugar.

const CONFIG = window.APP_CONFIG;
const TIPOS = Object.keys(CONFIG.tipos);

const CORES_CLASSE = { bom: '#2B8A6E', limitado: '#D69A2D', deserto: '#B0364A', sem_dados: '#9AA6AE' };
const ESTILO_TIPO = {
    urgencia: { cor: '#1F4E9C', raio: 6 },
    centro_saude: { cor: '#5E7A8A', raio: 3.5 },
    maternidade: { cor: '#7A4CA0', raio: 4.5 },
};
const COM_ARTIGO = { urgencia: 'uma urgência', centro_saude: 'um centro de saúde', maternidade: 'uma maternidade' };
const TIPO_CURTO = { urgencia: 'Urgência', centro_saude: 'Centro', maternidade: 'Matern.' };
const TIPO_PLURAL = { urgencia: 'Urgências', centro_saude: 'Centros de saúde', maternidade: 'Maternidades' };
const LIMITES_PORTUGAL = [[36.95, -9.55], [42.16, -6.18]];

// Rótulo dos serviços de uma unidade, para mostrar a quem a está a ver.
// Quase todas as maternidades portuguesas são o bloco de partos de um hospital que
// também tem urgência — é o mesmo edifício. Escrever "Urgência, Maternidade" dava a
// ideia de dois sítios; "Urgência com bloco de partos" diz o que lá se encontra.
// Só três unidades no país (Alfredo da Costa, Bissaya Barreto, Daniel de Matos) são
// maternidades autónomas, e essas continuam a dizer apenas "Maternidade".
function rotuloTipos(tipos) {
    const comParto = tipos.includes('maternidade');
    const base = TIPOS.filter((t) => t !== 'maternidade' && tipos.includes(t))
        .map((t) => CONFIG.tipos[t]);
    if (!base.length) {
        return comParto ? CONFIG.tipos.maternidade : '';
    }
    return base.join(' e ') + (comParto ? ' com bloco de partos' : '');
}

const estado = {
    unidades: [],            // todas as unidades (data/unidades.json)
    porId: new Map(),        // id -> unidade
    infoDados: null,         // { atualizado_em, total, ... }
    ponto: null,             // { lat, lon, nome }
    resultados: null,        // última resposta de api/tempos.php
    pedidoPonto: 0,          // contadores para ignorar respostas antigas
    pedidoZona: 0,
    opcoes: [],              // unidades mostradas nos resultados (botão "Ver percurso")
    guardadas: [],           // [{ id, nome, lat, lon }]
    comparacao: [],          // [{ lat, lon, nome, dados, erro, marcador }]
    semTag: CONFIG.semTag,
    medidaZona: 'pessoas',   // 'pessoas' ou 'area', no resumo das manchas
    mapaZona: 'acesso',      // 'acesso', 'pessoas' ou 'critico', no desenho do mapa
    offline: false,
};

// ---------- utilitários ----------

const $ = (id) => document.getElementById(id);

function esc(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function numero(valor, casas = 1) {
    return Number(valor).toLocaleString('pt-PT', { maximumFractionDigits: casas });
}

// Número de pessoas legível: acima de um milhão, "1,23 milhões" diz mais do que
// "1 234 567" a quem está a ler depressa.
function pessoasTexto(n) {
    const v = Math.round(Number(n) || 0);
    if (v >= 1000000) return `${numero(v / 1000000, 2)} milhões`;
    return v.toLocaleString('pt-PT');
}

function percentagem(parte, total, casas = 1) {
    if (!total) return 0;
    return Number(((parte / total) * 100).toFixed(casas));
}

function minutosTexto(minutos) {
    return Math.max(1, Math.round(minutos));
}

// "1 h 05" para tempos longos, "23 min" para os curtos
function duracaoTexto(minutos) {
    const m = minutosTexto(minutos);
    if (m < 90) return `${m} min`;
    return `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`;
}

function distanciaTexto(metros) {
    return metros < 1000 ? `${Math.round(metros)} m` : `${numero(metros / 1000)} km`;
}

function classificar(tipo, minutos) {
    if (minutos === null || minutos === undefined) return 'sem_dados';
    const [bom, limite] = CONFIG.limiares[tipo];
    if (minutos <= bom) return 'bom';
    if (minutos <= limite) return 'limitado';
    return 'deserto';
}

function textoClasse(tipo, classe, contexto = 'ponto') {
    const [bom, limite] = CONFIG.limiares[tipo];
    return {
        bom: `Bom acesso (até ${bom} min)`,
        limitado: `Acesso limitado (${bom} a ${limite} min)`,
        deserto: `Deserto de saúde (mais de ${limite} min)`,
        sem_dados: contexto === 'grelha' ? 'Sem estrada próxima ou sem rota' : 'Nenhuma unidade alcançável por estrada',
    }[classe];
}

function distanciaKm(lat1, lon1, lat2, lon2) {
    const rad = Math.PI / 180;
    const dLat = (lat2 - lat1) * rad;
    const dLon = (lon2 - lon1) * rad;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) ** 2;
    return 2 * 6371 * Math.asin(Math.sqrt(a));
}

// Compara textos ignorando acentos e maiúsculas, para a pesquisa por nome
function normalizar(texto) {
    return String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

async function api(url) {
    const resposta = await fetch(url);
    let dados;
    try {
        dados = await resposta.json();
    } catch {
        throw new Error('O servidor não devolveu JSON. Confirma que a página está a ser servida pelo PHP.');
    }
    if (!resposta.ok || dados.erro) {
        throw new Error(dados.erro || `Erro HTTP ${resposta.status}`);
    }
    return dados;
}

function ocupado(botao, sim) {
    botao.disabled = sim;
    botao.setAttribute('aria-busy', sim ? 'true' : 'false');
}

// ---------- armazenamento local ----------
//
// Fica só no dispositivo de quem usa: nada é enviado para o servidor.
// Em navegação privada o localStorage pode rebentar, por isso vai tudo com try/catch.

const CHAVE = 'mapa-saude:';

function guardarLocal(chave, valor) {
    try {
        localStorage.setItem(CHAVE + chave, JSON.stringify(valor));
    } catch {
        // sem espaço ou sem permissão: a aplicação continua a funcionar, só não se lembra
    }
}

function lerLocal(chave, alternativa) {
    try {
        const bruto = localStorage.getItem(CHAVE + chave);
        return bruto === null ? alternativa : JSON.parse(bruto);
    } catch {
        return alternativa;
    }
}

// ---------- descarregar ficheiros (CSV) ----------

function celulaCsv(valor) {
    const texto = String(valor ?? '');
    return /[";\n]/.test(texto) ? `"${texto.replace(/"/g, '""')}"` : texto;
}

// Ponto e vírgula e BOM: é o que o Excel em português abre sem perguntar nada.
function descarregarCsv(nomeFicheiro, linhas) {
    const texto = '﻿' + linhas.map((l) => l.map(celulaCsv).join(';')).join('\r\n');
    const url = URL.createObjectURL(new Blob([texto], { type: 'text/csv;charset=utf-8' }));
    const ligacao = document.createElement('a');
    ligacao.href = url;
    ligacao.download = nomeFicheiro;
    document.body.appendChild(ligacao);
    ligacao.click();
    ligacao.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// ---------- tema ----------

function aplicarTema(tema) {
    document.documentElement.dataset.tema = tema;
    guardarLocal('tema', tema);
    // o localStorage do tema é lido no <head> sem o prefixo JSON, por isso guarda-se em cru também
    try {
        localStorage.setItem('mapa-saude:tema', tema);
    } catch {}
    if (typeof aplicarTemaAoMapa === 'function') aplicarTemaAoMapa(tema);
}

function temaAtual() {
    if (document.documentElement.dataset.tema) return document.documentElement.dataset.tema;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
}

$('btn-tema').addEventListener('click', () => {
    aplicarTema(temaAtual() === 'escuro' ? 'claro' : 'escuro');
});

// ---------- separadores ----------

const separadores = [...document.querySelectorAll('[role="tab"]')];

function mostrarSeparador(nome) {
    separadores.forEach((sep) => {
        const ativo = sep.dataset.painel === nome;
        sep.setAttribute('aria-selected', ativo ? 'true' : 'false');
        $(`painel-${sep.dataset.painel}`).hidden = !ativo;
    });
    guardarLocal('separador', nome);
}

separadores.forEach((sep) => {
    sep.addEventListener('click', () => mostrarSeparador(sep.dataset.painel));
    // setas esquerda/direita percorrem os separadores, como manda o padrão de tablist
    sep.addEventListener('keydown', (e) => {
        const passo = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!passo) return;
        e.preventDefault();
        const i = separadores.indexOf(sep);
        const seguinte = separadores[(i + passo + separadores.length) % separadores.length];
        seguinte.focus();
        mostrarSeparador(seguinte.dataset.painel);
    });
});

// ---------- estado da ligação ----------

function atualizarEstadoLigacao() {
    estado.offline = !navigator.onLine;
    $('estado-ligacao').hidden = !estado.offline;
    document.body.classList.toggle('offline', estado.offline);
}

window.addEventListener('online', atualizarEstadoLigacao);
window.addEventListener('offline', atualizarEstadoLigacao);