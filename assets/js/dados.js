'use strict';

// Separador "Dados": retrato do país, definições e exportação.

function desenharRetrato() {
    const caixa = $('retrato');
    if (!estado.unidades.length) {
        caixa.innerHTML = '<p class="info">Sem dados carregados.</p>';
        return;
    }

    const contagem = {};
    let confirmadas = 0;
    TIPOS.forEach((t) => { contagem[t] = 0; });
    estado.unidades.forEach((u) => {
        u.tipos.forEach((t) => { if (t in contagem) contagem[t] += 1; });
        if (u.tipos.includes('urgencia') && u.urgencia === 'sim') confirmadas += 1;
    });

    let html = '<ul class="cartoes">';
    TIPOS.forEach((tipo) => {
        html += `<li><span class="cartao-num" style="color:${ESTILO_TIPO[tipo].cor}">${contagem[tipo]}</span>`
            + `<span class="cartao-rot">${esc(TIPO_PLURAL[tipo])}</span></li>`;
    });
    html += '</ul>';
    html += `<p class="info">${confirmadas} das ${contagem.urgencia} urgências têm a etiqueta <code>emergency=yes</code> no OpenStreetMap; `
        + 'as restantes são hospitais gerais contados por omissão.</p>';

    // percentagens de deserto, se os mapas nacionais já tiverem sido gerados
    html += '<div id="retrato-desertos"></div>';
    caixa.innerHTML = html;
    carregarDesertosNacionais();
}

// Lê os mapas nacionais pré-calculados, se existirem, e mostra a percentagem de
// território em deserto de saúde para cada tipo de cuidado.
async function carregarDesertosNacionais() {
    const caixa = $('retrato-desertos');
    const linhas = [];

    for (const tipo of TIPOS) {
        try {
            const resposta = await fetch(`data/grelha_${tipo}.json`, { cache: 'force-cache' });
            if (!resposta.ok) continue;
            const dados = await resposta.json();
            if (!Array.isArray(dados.rasters)) continue; // ficheiro de uma versão anterior
            const { contagem, total, pop } = contarClasses(juntarRasters(dados.rasters), tipo);
            if (!total) continue;
            linhas.push({
                tipo,
                pct: (contagem.deserto / total) * 100,
                pctPessoas: pop && pop.total ? (pop.contagem.deserto / pop.total) * 100 : null,
                pessoas: pop ? pop.contagem.deserto : null,
                geradoEm: dados.gerado_em,
            });
        } catch {
            // mapa nacional não gerado para este tipo: simplesmente não aparece
        }
    }

    if (!linhas.length) {
        caixa.innerHTML = '<p class="info">Para veres a percentagem do país em deserto de saúde, gera os mapas nacionais no servidor: '
            + '<code>php scripts/gerar_grelha.php todos 5</code></p>';
        return;
    }

    const temPessoas = linhas.some((l) => l.pctPessoas !== null);

    let html = '<h3>Território em deserto de saúde</h3><ul class="barras">';
    linhas.forEach(({ tipo, pct }) => {
        const limite = CONFIG.limiares[tipo][1];
        html += `<li><span class="barra-rot">${esc(CONFIG.tipos[tipo])}<span class="barra-sub">mais de ${limite} min</span></span>`
            + `<span class="barra-fundo"><span class="barra-valor" style="width:${Math.max(pct, 1)}%;background:${CORES_CLASSE.deserto}"></span></span>`
            + `<span class="barra-num">${numero(pct, 0)}%</span></li>`;
    });
    html += '</ul>';

    // A mesma pergunta, medida em gente. A diferença entre as duas barras é o ponto
    // inteiro deste projeto: território longe não é o mesmo que pessoas longe.
    if (temPessoas) {
        html += '<h3>População em deserto de saúde</h3><ul class="barras">';
        linhas.forEach(({ tipo, pctPessoas, pessoas }) => {
            if (pctPessoas === null) return;
            const limite = CONFIG.limiares[tipo][1];
            html += `<li><span class="barra-rot">${esc(CONFIG.tipos[tipo])}<span class="barra-sub">${pessoasTexto(pessoas)} pessoas</span></span>`
                + `<span class="barra-fundo"><span class="barra-valor" style="width:${Math.max(pctPessoas, 1)}%;background:${CORES_CLASSE.deserto}"></span></span>`
                + `<span class="barra-num">${numero(pctPessoas, 1)}%</span></li>`;
        });
        html += '</ul>';
        html += '<p class="info">Repara na diferença entre as duas leituras: a área em deserto é sempre muito maior '
            + 'do que a população em deserto, porque o território mal servido é quase todo despovoado. '
            + 'Os números que contam pessoas estão no separador «Pessoas».</p>';
    }

    html += `<p class="info">Calculado em ${new Date(linhas[0].geradoEm).toLocaleDateString('pt-PT')}.</p>`;
    caixa.innerHTML = html;
}

function mostrarInfoDados() {
    const info = estado.infoDados;
    const caixa = $('info-dados');
    if (!info) {
        caixa.textContent = 'Sem informação sobre os dados.';
        return;
    }
    const data = new Date(info.atualizado_em);
    const meses = (Date.now() - data.getTime()) / (1000 * 60 * 60 * 24 * 30);

    let html = `<p>${info.total} unidades, extraídas do OpenStreetMap a ${data.toLocaleDateString('pt-PT')}.</p>`;
    if (meses > 6) {
        html += `<p class="aviso">Os dados têm mais de ${Math.floor(meses)} meses. `
            + 'No servidor, corre <code>php scripts/atualizar_unidades.php</code> para os renovar.</p>';
    }
    html += `<p>${esc(info.licenca || '')}</p>`;
    caixa.innerHTML = html;
}

// ---------- definições ----------

const chkSemTag = $('chk-sem-tag');
chkSemTag.checked = estado.semTag;
chkSemTag.addEventListener('change', () => {
    estado.semTag = chkSemTag.checked;
    guardarLocal('semTag', estado.semTag);
    recalcular();
});

// ---------- exportar ----------

$('btn-csv-unidades').addEventListener('click', () => {
    const linhas = [['id', 'nome', 'tipos', 'urgencia_confirmada', 'operador', 'localidade', 'lat', 'lon']];
    estado.unidades.forEach((u) => {
        linhas.push([
            u.id, u.nome, rotuloTipos(u.tipos),
            u.urgencia === 'sim' ? 'sim' : (u.urgencia === 'nao' ? 'nao' : 'desconhecido'),
            u.operador || '', u.localidade || '', u.lat, u.lon,
        ]);
    });
    descarregarCsv('unidades-saude-portugal.csv', linhas);
});

$('btn-csv-grelha').addEventListener('click', () => {
    if (!camadas.grelha) {
        alert('Analisa primeiro uma zona no separador «Zonas».');
        return;
    }
    const { tipo, raster } = camadas.grelha;
    const temPop = Array.isArray(raster.populacao);
    const cabecalho = ['lat', 'lon', 'minutos', 'classificacao', 'tipo_cuidado'];
    if (temPop) cabecalho.push('populacao', 'populacao_65_mais');
    const linhas = [cabecalho];
    raster.valores.forEach((minutos, k) => {
        if (!raster.terra[k]) return; // o mar não vai para o ficheiro
        const j = Math.floor(k / raster.largura);
        const i = k % raster.largura;
        const linha = [
            (raster.sul + (j + 0.5) * raster.dLat).toFixed(5),
            (raster.oeste + (i + 0.5) * raster.dLon).toFixed(5),
            minutos === null ? '' : numero(minutos),
            textoClasse(tipo, classificar(tipo, minutos), 'grelha'),
            CONFIG.tipos[tipo],
        ];
        if (temPop) {
            linha.push(raster.populacao[k] || 0, (raster.populacao_65 && raster.populacao_65[k]) || 0);
        }
        linhas.push(linha);
    });
    descarregarCsv('pontos-acesso-saude.csv', linhas);
});