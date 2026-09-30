'use strict';

// Separador "Moradas": as moradas guardadas no dispositivo e a comparação lado a lado.

const LETRAS = 'ABCDEFGH';

// ---------- moradas guardadas ----------
//
// Ficam no localStorage, só neste dispositivo. A ideia é não ter de escrever outra vez
// a morada de casa, do trabalho ou da casa dos pais de cada vez que se abre a página.

function carregarGuardadas() {
    estado.guardadas = lerLocal('guardadas', []).filter((m) => m && Number.isFinite(m.lat) && Number.isFinite(m.lon));
    desenharGuardadas();
}

function gravarGuardadas() {
    guardarLocal('guardadas', estado.guardadas);
    desenharGuardadas();
}

function guardarMoradaAtual() {
    if (!estado.ponto) return;
    const sugestao = estado.ponto.nome ? estado.ponto.nome.split(',')[0] : 'Morada';
    const etiqueta = (prompt('Que nome queres dar a esta morada?', sugestao) || '').trim();
    if (!etiqueta) return;

    estado.guardadas.push({
        id: 'm' + Date.now(),
        etiqueta: etiqueta.slice(0, 40),
        nome: estado.ponto.nome || `${estado.ponto.lat.toFixed(5)}, ${estado.ponto.lon.toFixed(5)}`,
        lat: estado.ponto.lat,
        lon: estado.ponto.lon,
        resumo: null,
    });
    gravarGuardadas();
    mostrarSeparador('moradas');
    atualizarResumosGuardadas();
}

function removerGuardada(id) {
    estado.guardadas = estado.guardadas.filter((m) => m.id !== id);
    gravarGuardadas();
}

// Calcula o tempo até cada tipo para todas as moradas guardadas, uma de cada vez
// (o servidor OSRM público pede 1 pedido por segundo).
async function atualizarResumosGuardadas() {
    if (estado.offline) return;
    for (const morada of estado.guardadas) {
        if (morada.resumo) continue;
        try {
            const params = new URLSearchParams({
                lat: morada.lat.toFixed(5), lon: morada.lon.toFixed(5), sem_tag: estado.semTag ? '1' : '0',
            });
            const dados = await api(`api/tempos.php?${params}`);
            morada.resumo = {};
            TIPOS.forEach((tipo) => {
                const melhor = (dados.resultados[tipo] || []).find((o) => o.minutos !== null);
                morada.resumo[tipo] = melhor ? { minutos: melhor.minutos, nome: melhor.nome } : null;
            });
            gravarGuardadas();
        } catch {
            morada.resumo = null; // tenta outra vez da próxima
            desenharGuardadas();
        }
    }
}

function desenharGuardadas() {
    const caixa = $('guardadas');
    if (!estado.guardadas.length) {
        caixa.innerHTML = '<p class="info">Ainda não guardaste nenhuma morada. Escolhe um sítio no separador «Local» e carrega em «Guardar esta morada».</p>';
        return;
    }

    let html = '<ul class="lista-moradas">';
    estado.guardadas.forEach((m) => {
        html += `<li><div class="morada-topo"><strong>${esc(m.etiqueta)}</strong>`
            + `<button type="button" class="link" data-abrir="${esc(m.id)}">Analisar</button>`
            + `<button type="button" class="link" data-remover="${esc(m.id)}" aria-label="Remover ${esc(m.etiqueta)}">✕</button></div>`;
        html += `<p class="morada-nome">${esc(m.nome)}</p>`;
        if (m.resumo) {
            html += '<ul class="morada-tempos">';
            TIPOS.forEach((tipo) => {
                const r = m.resumo[tipo];
                const classe = r ? classificar(tipo, r.minutos) : 'sem_dados';
                html += `<li class="c-${classe}" title="${esc(r ? r.nome : 'sem dados')}">`
                    + `<span class="morada-tipo">${esc(TIPO_CURTO[tipo])}</span>`
                    + `<span class="morada-min">${r ? minutosTexto(r.minutos) + ' min' : '–'}</span></li>`;
            });
            html += '</ul>';
        } else {
            html += '<p class="info">A calcular…</p>';
        }
        html += '</li>';
    });
    html += '</ul>';
    html += '<button type="button" class="link" id="btn-csv-guardadas">Exportar as minhas moradas (CSV)</button>';

    caixa.innerHTML = html;
    caixa.querySelectorAll('[data-abrir]').forEach((b) => {
        b.addEventListener('click', () => {
            const m = estado.guardadas.find((x) => x.id === b.dataset.abrir);
            if (m) escolherPonto(m.lat, m.lon, m.nome, true);
        });
    });
    caixa.querySelectorAll('[data-remover]').forEach((b) => {
        b.addEventListener('click', () => removerGuardada(b.dataset.remover));
    });
    $('btn-csv-guardadas').addEventListener('click', exportarGuardadas);
}

function exportarGuardadas() {
    const linhas = [['etiqueta', 'morada', 'lat', 'lon', ...TIPOS.map((t) => `${CONFIG.tipos[t]} (min)`)]];
    estado.guardadas.forEach((m) => {
        linhas.push([
            m.etiqueta, m.nome, m.lat.toFixed(5), m.lon.toFixed(5),
            ...TIPOS.map((t) => (m.resumo && m.resumo[t] ? numero(m.resumo[t].minutos) : '')),
        ]);
    });
    descarregarCsv('as-minhas-moradas.csv', linhas);
}

$('btn-guardar').addEventListener('click', guardarMoradaAtual);

// ---------- comparar moradas ----------
//
// Junta várias moradas e mostra, lado a lado, o tempo até cada tipo de cuidado.
// Serve para quem está a escolher onde viver, onde pôr um familiar idoso ou que escola escolher:
// é a pergunta "e se for para ali?" respondida de uma vez.
// A comparação fica no endereço da página, por isso o link pode ser enviado a alguém.

function podeComparar() {
    return estado.comparacao.length < CONFIG.maxComparacao;
}

async function juntarComparacao(lat, lon, nome) {
    if (!podeComparar()) return;
    const item = {
        lat, lon,
        nome: nome || `${lat.toFixed(4)}, ${lon.toFixed(4)}`,
        dados: null, erro: null, marcador: null,
    };
    estado.comparacao.push(item);

    const letra = LETRAS[estado.comparacao.length - 1];
    item.marcador = L.marker([lat, lon], {
        icon: L.divIcon({ className: 'marca-comparacao', html: esc(letra), iconSize: [26, 26], iconAnchor: [13, 13] }),
        title: item.nome,
        interactive: false,
    }).addTo(mapa);

    desenharComparacao();
    await calcularComparacao(item);
}

async function calcularComparacao(item) {
    const params = new URLSearchParams({
        lat: item.lat.toFixed(5), lon: item.lon.toFixed(5), sem_tag: estado.semTag ? '1' : '0',
    });
    try {
        item.dados = await api(`api/tempos.php?${params}`);
        item.erro = null;
    } catch (erro) {
        item.dados = null;
        item.erro = erro.message;
    }
    desenharComparacao();
    guardarComparacaoNoEndereco();
}

function removerComparacao(indice) {
    const item = estado.comparacao[indice];
    if (item && item.marcador) item.marcador.remove();
    estado.comparacao.splice(indice, 1);
    // as letras mudam quando se remove um do meio
    estado.comparacao.forEach((it, i) => {
        const el = it.marcador && it.marcador.getElement();
        if (el) el.textContent = LETRAS[i];
    });
    desenharComparacao();
    guardarComparacaoNoEndereco();
}

function melhorPorTipo() {
    const melhor = {};
    TIPOS.forEach((tipo) => {
        const valores = estado.comparacao
            .map((it) => (it.dados ? (it.dados.resultados[tipo] || []).find((o) => o.minutos !== null) : null))
            .filter(Boolean)
            .map((o) => o.minutos);
        melhor[tipo] = valores.length ? Math.min(...valores) : null;
    });
    return melhor;
}

function desenharComparacao() {
    const caixa = $('comparacao');
    const botao = $('btn-comparar-juntar');
    if (botao) botao.disabled = !podeComparar() || !estado.ponto;

    if (!estado.comparacao.length) {
        caixa.innerHTML = '<p class="info">Escolhe uma morada no separador «Local» e carrega em «Juntar à comparação». '
            + 'Junta a segunda da mesma maneira para as comparares.</p>';
        return;
    }

    const melhor = melhorPorTipo();
    let html = '<div class="tabela-scroll"><table class="tabela-comparacao">';
    html += '<thead><tr><th scope="col">Morada</th>';
    TIPOS.forEach((tipo) => {
        html += `<th scope="col"><abbr title="${esc(CONFIG.tipos[tipo])}">${esc(TIPO_CURTO[tipo])}</abbr></th>`;
    });
    html += '<th><span class="oculto">Remover</span></th></tr></thead><tbody>';

    estado.comparacao.forEach((item, i) => {
        html += `<tr><th scope="row"><span class="letra">${LETRAS[i]}</span>${esc(item.nome)}</th>`;
        TIPOS.forEach((tipo) => {
            if (item.erro) return void (html += '<td class="c-sem_dados">erro</td>');
            if (!item.dados) return void (html += '<td class="c-sem_dados">…</td>');
            const opcao = (item.dados.resultados[tipo] || []).find((o) => o.minutos !== null);
            if (!opcao) return void (html += '<td class="c-sem_dados">–</td>');
            const classe = classificar(tipo, opcao.minutos);
            const vence = melhor[tipo] !== null && opcao.minutos === melhor[tipo] && estado.comparacao.length > 1;
            html += `<td class="c-${classe}${vence ? ' vence' : ''}" title="${esc(opcao.nome)}">`
                + `<strong>${minutosTexto(opcao.minutos)}</strong> min`
                + `<span class="destino-curto">${esc(opcao.nome)}</span></td>`;
        });
        html += `<td><button type="button" class="link" data-remover="${i}" aria-label="Remover ${esc(item.nome)}">✕</button></td></tr>`;
    });
    html += '</tbody></table></div>';

    html += '<div class="botoes">'
        + '<button type="button" class="link" id="btn-comparar-link">Copiar link</button>'
        + '<button type="button" class="link" id="btn-comparar-csv">Exportar CSV</button>'
        + '<button type="button" class="link" id="btn-comparar-limpar">Limpar</button></div>'
        + '<p class="info" id="comparar-aviso"></p>';

    caixa.innerHTML = html;
    caixa.querySelectorAll('[data-remover]').forEach((b) => {
        b.addEventListener('click', () => removerComparacao(Number(b.dataset.remover)));
    });
    $('btn-comparar-limpar').addEventListener('click', () => {
        while (estado.comparacao.length) removerComparacao(0);
    });
    $('btn-comparar-link').addEventListener('click', copiarLinkComparacao);
    $('btn-comparar-csv').addEventListener('click', exportarComparacao);
}

function exportarComparacao() {
    const linhas = [['morada', 'lat', 'lon', ...TIPOS.flatMap((t) => [`${CONFIG.tipos[t]} (min)`, `${CONFIG.tipos[t]} - unidade`])]];
    estado.comparacao.forEach((item) => {
        const celulas = [item.nome, item.lat.toFixed(5), item.lon.toFixed(5)];
        TIPOS.forEach((tipo) => {
            const o = item.dados ? (item.dados.resultados[tipo] || []).find((x) => x.minutos !== null) : null;
            celulas.push(o ? numero(o.minutos) : '', o ? o.nome : '');
        });
        linhas.push(celulas);
    });
    descarregarCsv('comparacao-moradas.csv', linhas);
}

// ---------- link partilhável ----------

function guardarComparacaoNoEndereco() {
    if (!estado.comparacao.length) {
        history.replaceState(null, '', location.pathname + location.search);
        return;
    }
    const texto = estado.comparacao
        .map((it) => [it.lat.toFixed(5), it.lon.toFixed(5), encodeURIComponent(it.nome.slice(0, 60))].join(','))
        .join('|');
    history.replaceState(null, '', `${location.pathname}${location.search}#c=${texto}`);
}

async function lerComparacaoDoEndereco() {
    const encontrado = location.hash.match(/^#c=(.+)$/);
    if (!encontrado) return;
    const partes = decodeURIComponent(encontrado[1]).split('|').slice(0, CONFIG.maxComparacao);
    for (const parte of partes) {
        const [lat, lon, ...resto] = parte.split(',');
        const y = Number(lat);
        const x = Number(lon);
        if (!Number.isFinite(y) || !Number.isFinite(x)) continue;
        await juntarComparacao(y, x, decodeURIComponent(resto.join(',')));
    }
    if (estado.comparacao.length) {
        mostrarSeparador('moradas');
        mapa.fitBounds(estado.comparacao.map((it) => [it.lat, it.lon]), { padding: [60, 60], maxZoom: 12 });
    }
}

async function copiarLinkComparacao() {
    const aviso = $('comparar-aviso');
    try {
        await navigator.clipboard.writeText(location.href);
        aviso.textContent = 'Link copiado.';
    } catch {
        aviso.textContent = location.href; // sem permissão para a área de transferência: mostra o link
    }
}

$('btn-comparar-juntar').addEventListener('click', () => {
    if (estado.ponto) {
        juntarComparacao(estado.ponto.lat, estado.ponto.lon, estado.ponto.nome);
        mostrarSeparador('moradas');
    }
});
