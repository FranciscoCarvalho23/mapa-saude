'use strict';

// Separador "Pessoas": o mesmo mapa lido em gente, não em área.
//
// Um mapa de área diz "40% do território fica longe" — e quase todo esse território é
// serra sem ninguém. Estes números respondem à pergunta que interessa a quem decide e a
// quem lá vive: quantas PESSOAS ficam longe, e quem são.

const selTipoPessoas = $('tipo-pessoas');
TIPOS.forEach((tipo) => selTipoPessoas.add(new Option(CONFIG.tipos[tipo], tipo)));

const pessoasCache = { resumo: null, piores: {} };
let ordemPiores = 'pessoas';   // 'pessoas' ou 'criticidade'

// ---------- retrato nacional ----------

async function carregarRetratoPessoas() {
    const caixa = $('resumo-pessoas');
    if (pessoasCache.resumo) {
        desenharRetratoPessoas(pessoasCache.resumo);
        return;
    }
    caixa.innerHTML = '<p class="info">A ler os mapas nacionais…</p>';
    try {
        pessoasCache.resumo = await api('api/populacao.php?accao=resumo');
        desenharRetratoPessoas(pessoasCache.resumo);
    } catch (erro) {
        caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    }
}

function desenharRetratoPessoas(dados) {
    const classes = ['bom', 'limitado', 'deserto', 'sem_dados'];
    let html = '';

    TIPOS.forEach((tipo) => {
        const r = dados.tipos[tipo];
        if (!r) {
            html += `<div class="cartao-pessoas"><h3>${esc(CONFIG.tipos[tipo])}</h3>`
                + `<p class="info">Mapa nacional por gerar. No servidor: <code>php scripts/gerar_grelha.php ${esc(tipo)}</code></p></div>`;
            return;
        }

        const limite = r.limiares.limite;
        // Quociente de equidade: se os 65+ estivessem distribuídos como toda a gente,
        // isto daria 1. Acima de 1 significa que o problema lhes cai mais em cima.
        const equidade = r.pct_acima > 0 ? r.pct_acima_65 / r.pct_acima : null;

        html += `<div class="cartao-pessoas"><h3>${esc(CONFIG.tipos[tipo])}</h3>`;
        html += `<p class="destaque">${pessoasTexto(r.classes.deserto)} pessoas vivem a mais de ${limite} min de ${COM_ARTIGO[tipo]}.</p>`;
        html += `<div class="barra-resumo">${classes.map((c) => {
            const p = percentagem(r.classes[c], r.total);
            return p > 0 ? `<span style="width:${p}%;background:${CORES_CLASSE[c]}" title="${esc(textoClasse(tipo, c, 'grelha'))}: ${pessoasTexto(r.classes[c])}"></span>` : '';
        }).join('')}</div>`;

        html += '<table class="tabela-pessoas"><thead><tr><th>Acesso</th><th>Pessoas</th><th>%</th><th>65+</th></tr></thead><tbody>';
        classes.forEach((c) => {
            if (!r.classes[c]) return;
            html += `<tr><th scope="row"><span class="quadrado" style="background:${CORES_CLASSE[c]}"></span>${esc(textoClasse(tipo, c, 'grelha'))}</th>`
                + `<td>${pessoasTexto(r.classes[c])}</td><td>${numero(percentagem(r.classes[c], r.total))}%</td>`
                + `<td>${pessoasTexto(r.classes_65[c])}</td></tr>`;
        });
        html += '</tbody></table>';

        html += `<ul class="factos"><li>Tempo médio, contando pessoas e não quadrículas: <strong>${numero(r.minutos_medios)} min</strong></li>`;
        if (equidade !== null) {
            const texto = equidade >= 1.05
                ? `<strong>${numero(equidade, 2)}× mais provável</strong> ficar em deserto de saúde se tiver 65 ou mais anos`
                : (equidade <= 0.95
                    ? `menos provável ficar em deserto tendo 65 ou mais anos (${numero(equidade, 2)}×)`
                    : 'praticamente igual entre os 65+ e a população em geral');
            html += `<li>É ${texto}.</li>`;
        }
        if (r.classes.sem_dados) {
            html += `<li>${pessoasTexto(r.classes.sem_dados)} pessoas sem tempo calculado (sem estrada próxima). Contam para o total, não para as percentagens de acesso.</li>`;
        }
        html += '</ul>';

        // Frase pronta a citar. O objetivo é que quem escrever sobre isto não tenha de
        // fazer contas nem inventar formulações — e que cite a fonte.
        const frase = `${pessoasTexto(r.classes.deserto)} pessoas em Portugal vivem a mais de ${limite} minutos de carro `
            + `de ${COM_ARTIGO[tipo]}, das quais ${pessoasTexto(r.classes_65.deserto)} têm 65 ou mais anos. `
            + `Fonte: tempos calculados com OSRM sobre a rede do OpenStreetMap; população da grelha do Census 2021 do Eurostat.`;
        html += `<div class="citar"><p class="frase">${esc(frase)}</p>`
            + `<button type="button" class="link btn-copiar" data-frase="${esc(frase)}">Copiar frase</button></div>`;
        html += '</div>';
    });

    const qualquer = TIPOS.map((t) => dados.tipos[t]).find(Boolean);
    if (qualquer && qualquer.gerado_em) {
        html += `<p class="info">Mapas nacionais calculados em ${new Date(qualquer.gerado_em).toLocaleDateString('pt-PT')}, `
            + `numa grelha de ${numero(qualquer.passo_km)} km. População: ${esc(dados.fonte.populacao)}.</p>`;
    }

    $('resumo-pessoas').innerHTML = html;

    document.querySelectorAll('#resumo-pessoas .btn-copiar').forEach((botao) => {
        botao.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(botao.dataset.frase);
                botao.textContent = 'Copiado';
                setTimeout(() => { botao.textContent = 'Copiar frase'; }, 2000);
            } catch {
                botao.textContent = 'O browser não deixou copiar';
            }
        });
    });
}

// ---------- onde vive mais gente longe ----------

async function carregarPioresZonas() {
    const tipo = selTipoPessoas.value;
    const caixa = $('resumo-piores');
    const botao = $('btn-piores');
    ocupado(botao, true);
    caixa.innerHTML = '<p class="info">A juntar as quadrículas em manchas…</p>';
    try {
        const chave = `${tipo}:${ordemPiores}`;
        if (!pessoasCache.piores[chave]) {
            pessoasCache.piores[chave] = await api(
                `api/populacao.php?accao=piores&tipo=${encodeURIComponent(tipo)}&quantas=20&ordenar=${ordemPiores}`);
        }
        desenharPioresZonas(pessoasCache.piores[chave]);
    } catch (erro) {
        caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    } finally {
        ocupado(botao, false);
    }
}

function desenharPioresZonas(dados) {
    const caixa = $('resumo-piores');
    if (!dados.zonas.length) {
        caixa.innerHTML = `<p class="info">Não há nenhuma zona habitada a mais de ${dados.limiar} min de ${COM_ARTIGO[dados.tipo]}.</p>`;
        return;
    }

    const totalListado = dados.zonas.reduce((s, z) => s + z.pessoas, 0);

    // Duas perguntas diferentes, e dão listas diferentes: "onde vive mais gente longe"
    // põe em cima uma vila grande pouco acima do limiar; "onde o problema pesa mais"
    // põe em cima uma ilha pequena a três horas de distância. Nenhuma está errada.
    let html = '<div class="alternador" role="group" aria-label="Como ordenar as zonas">'
        + [['pessoas', 'Mais gente'], ['criticidade', 'Maior gravidade']]
            .map(([v, r]) => `<button type="button" class="${ordemPiores === v ? 'ativo' : ''}" data-ordem="${v}">${r}</button>`)
            .join('') + '</div>';

    html += `<p>As ${dados.zonas.length} manchas no topo somam `
        + `<strong>${pessoasTexto(totalListado)} pessoas</strong> acima de ${dados.limiar} min. `
        + 'Quadrículas vizinhas foram juntas numa mancha só, e o ponto indicado é o centro dessa '
        + 'mancha ponderado pela população — cai onde a gente está, não no meio geométrico.</p>';

    html += '<div class="tabela-rolavel"><table class="tabela-pessoas tabela-piores"><thead><tr>'
        + '<th>#</th><th>Pessoas</th><th>65+</th><th>Média</th><th>A mais</th>'
        + '<th><abbr title="Pessoas multiplicadas pelos minutos acima do limiar: o peso do problema">Peso</abbr></th>'
        + '<th></th></tr></thead><tbody>';
    dados.zonas.forEach((z, i) => {
        html += `<tr><td>${i + 1}</td><td><strong>${pessoasTexto(z.pessoas)}</strong></td>`
            + `<td>${pessoasTexto(z.pessoas_65)}</td><td>${minutosTexto(z.minutos_medios)} min</td>`
            + `<td>+${minutosTexto(z.minutos_acima || 0)} min</td>`
            + `<td>${pessoasTexto(z.pessoas_minuto || 0)}</td>`
            + `<td><button type="button" class="link btn-ir-zona" data-lat="${z.lat}" data-lon="${z.lon}">ver</button></td></tr>`;
    });
    html += '</tbody></table></div>';
    html += `<p class="info">Ordenado por <strong>${dados.ordenado_por === 'criticidade' ? 'gravidade' : 'população'}</strong>. `
        + 'O «peso» é pessoas × minutos acima do limiar: é o que permite comparar muita gente pouco acima '
        + 'com pouca gente muito acima, e é o critério que faz sentido para decidir onde construir.</p>';
    html += '<div class="botoes">'
        + '<button type="button" id="btn-marcar-criticas" class="secundario">Marcar as zonas no mapa</button>'
        + '<button type="button" id="btn-mapa-critico">Mapa nacional de criticidade</button>'
        + '</div>';
    html += '<div class="botoes"><button type="button" id="btn-csv-piores" class="link">Exportar esta lista (CSV)</button>'
        + '<button type="button" id="btn-limpar-criticas" class="link">Limpar o mapa</button></div>';

    caixa.innerHTML = html;

    caixa.querySelectorAll('.alternador button[data-ordem]').forEach((botao) => {
        botao.addEventListener('click', () => {
            ordemPiores = botao.dataset.ordem;
            carregarPioresZonas();
        });
    });

    caixa.querySelectorAll('.btn-ir-zona').forEach((botao) => {
        botao.addEventListener('click', () => {
            const lat = Number(botao.dataset.lat);
            const lon = Number(botao.dataset.lon);
            mapa.setView([lat, lon], 11);
            escolherPonto(lat, lon, 'Zona mal servida');
        });
    });

    $('btn-marcar-criticas').addEventListener('click', () => {
        desenharCriticas(dados.zonas, dados.tipo);
        mapa.fitBounds(LIMITES_PORTUGAL);
    });
    $('btn-mapa-critico').addEventListener('click', () => verMapaCritico(dados));
    $('btn-limpar-criticas').addEventListener('click', () => {
        limparCriticas();
        limparGrelha();
        $('resumo-critico').innerHTML = '';
    });

    $('btn-csv-piores').addEventListener('click', () => {
        const linhas = [['ordem', 'pessoas', 'pessoas_65_mais', 'minutos_medios', 'minutos_pior',
            'minutos_acima_do_limiar', 'pessoas_minuto_acima', 'lat', 'lon']];
        dados.zonas.forEach((z, i) => {
            const excesso = Math.max(0, z.minutos_medios - dados.limiar);
            linhas.push([i + 1, z.pessoas, z.pessoas_65, z.minutos_medios, z.minutos_pior,
                numero(excesso), Math.round(z.pessoas * excesso), z.lat, z.lon]);
        });
        descarregarCsv(`zonas-mal-servidas-${dados.tipo}.csv`, linhas);
    });
}


// ---------- mapa nacional de criticidade ----------
//
// Junta as duas metades numa só imagem: as manchas de acesso por baixo, e por cima um
// círculo por quadrícula acima do limiar, do tamanho da gente que lá vive. É o mapa que
// responde a "onde é que vale a pena construir", que nenhuma das duas metades responde
// sozinha.

async function verMapaCritico(dadosRanking) {
    const tipo = selTipoPessoas.value;
    const caixa = $('resumo-critico');
    caixa.innerHTML = '<p class="info">A carregar o mapa nacional…</p>';
    try {
        const resposta = await fetch(`data/grelha_${tipo}.json`, { cache: 'no-cache' });
        if (!resposta.ok) {
            throw new Error(`Mapa nacional de «${CONFIG.tipos[tipo]}» por gerar. No servidor: php scripts/gerar_grelha.php ${tipo}`);
        }
        const dados = await resposta.json();
        if (!Array.isArray(dados.rasters) || !dados.rasters.some((r) => Array.isArray(r.populacao))) {
            throw new Error('O mapa nacional foi gerado sem população. No servidor, volta a correr: '
                + `php scripts/gerar_grelha.php ${tipo} 5`);
        }

        mostrarSeparador('pessoas');
        desenharManchasMulti(dados.rasters, tipo, { origem: 'nacional' });
        estado.mapaZona = 'critico';
        const info = desenharPessoas(dados.rasters, tipo, 'critico');
        if (dadosRanking) desenharCriticas(dadosRanking.zonas, tipo);
        mapa.fitBounds(LIMITES_PORTUGAL);

        const limite = CONFIG.limiares[tipo][1];
        let html = `<p class="destaque">${pessoasTexto(info.pessoas)} pessoas em ${info.celulas} quadrículas acima de ${limite} min.</p>`;
        html += `<p class="info">${legendaCriticidade(limite)}</p>`;
        html += '<ul class="legenda">'
            + CORES_CRITICO.map((cor, i) => {
                const faixa = ['até 10 min a mais', '10 a 30 min a mais', '30 a 60 min a mais', 'mais de 1 h a mais'][i];
                return `<li><span class="quadrado" style="background:${cor}"></span>${faixa}</li>`;
            }).join('') + '</ul>';
        caixa.innerHTML = html;
    } catch (erro) {
        caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    }
}

function legendaCriticidade(limite) {
    return `Cada círculo é uma quadrícula acima de ${limite} min. O tamanho é a população `
        + '(a área do círculo, não o raio — é assim que o olho lê um círculo), a cor é quanto '
        + 'ultrapassa o limiar. Os números marcam as manchas do ranking. '
        + 'Um círculo grande e escuro é uma zona onde muita gente está muito longe: '
        + 'é aí que uma unidade nova muda mais vidas por euro investido.';
}


// ---------- onde construir ----------
//
// Otimização sobre o país inteiro: escolhe N locais para unidades novas. O tempo de
// viagem vem de um modelo treinado nos tempos reais do OSRM, o que permite avaliar
// dezenas de milhares de configurações em segundos. O resultado é sempre apresentado
// com o erro do modelo à vista, e pode ser confirmado contra o OSRM a sério.

function limparPropostas() {
    if (camadas.propostas) {
        camadas.propostas.remove();
        camadas.propostas = null;
    }
}

function desenharPropostas(locais) {
    limparPropostas();
    const camada = L.layerGroup();
    locais.forEach((l) => {
        L.marker([l.lat, l.lon], {
            icon: L.divIcon({ className: 'marca-proposta', html: String(l.ordem), iconSize: [30, 30], iconAnchor: [15, 15] }),
            title: `Local proposto ${l.ordem}`,
        }).bindPopup(
            `<strong>Local proposto ${l.ordem}</strong><br>`
            + `serve ${pessoasTexto(l.pessoas_servidas)} pessoas<br>`
            + `${pessoasTexto(l.pessoas_servidas_65)} com 65 ou mais anos<br>`
            + (l.minutos_hoje !== null ? `hoje este ponto está a ${minutosTexto(l.minutos_hoje)} min` : 'hoje sem rota conhecida')
        ).addTo(camada);
    });
    camada.addTo(mapa);
    camadas.propostas = camada;
}

async function otimizarLocais(confirmar = false) {
    const tipo = selTipoPessoas.value;
    const quantas = Math.max(1, Math.min(8, Number($('otim-quantas').value) || 3));
    const objetivo = document.querySelector('input[name="otim-objetivo"]:checked').value;
    const caixa = $('resumo-otimizacao');
    const botao = $('btn-otimizar');

    ocupado(botao, true);
    caixa.innerHTML = confirmar
        ? '<p class="info">A confirmar os locais com o OSRM. Isto demora e faz pedidos reais.</p>'
        : '<p class="info">A procurar. O arrefecimento simulado avalia milhares de configurações — '
          + 'pode demorar até meio minuto da primeira vez.</p>';

    try {
        const query = new URLSearchParams({ tipo, quantas, objetivo });
        if (confirmar) query.set('confirmar', '1');
        const dados = await api(`api/otimizar.php?${query}`);
        mostrarOtimizacao(dados);
    } catch (erro) {
        caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    } finally {
        ocupado(botao, false);
    }
}

function mostrarOtimizacao(d) {
    desenharPropostas(d.locais);
    mapa.fitBounds(LIMITES_PORTUGAL);

    const g = d.ganho;
    let html = `<p class="destaque">${d.quantas} ${d.quantas === 1 ? 'unidade nova tira' : 'unidades novas tiram'} `
        + `${pessoasTexto(g.saem_do_deserto)} pessoas do deserto de saúde.</p>`;

    html += '<div class="tabela-rolavel"><table class="tabela-pessoas"><thead><tr>'
        + '<th>#</th><th>Local</th><th>Serve</th><th>65+</th><th>Hoje</th><th></th></tr></thead><tbody>';
    d.locais.forEach((l) => {
        html += `<tr><td>${l.ordem}</td>`
            + `<td>${l.lat.toFixed(3)}, ${l.lon.toFixed(3)}</td>`
            + `<td><strong>${pessoasTexto(l.pessoas_servidas)}</strong></td>`
            + `<td>${pessoasTexto(l.pessoas_servidas_65)}</td>`
            + `<td>${l.minutos_hoje === null ? '–' : `${minutosTexto(l.minutos_hoje)} min`}</td>`
            + `<td><button type="button" class="link btn-ir-proposta" data-lat="${l.lat}" data-lon="${l.lon}">ver</button></td></tr>`;
    });
    html += '</tbody></table></div>';

    html += '<h3 class="sub-titulo">O que muda no país</h3>';
    html += '<table class="tabela-antes-depois"><thead><tr><th></th><th>Agora</th><th>Depois</th></tr></thead><tbody>';
    html += `<tr><th scope="row">Tempo médio por pessoa</th><td>${numero(d.antes.minutos_medios)} min</td>`
        + `<td class="melhor">${numero(d.depois.minutos_medios)} min</td></tr>`;
    html += `<tr><th scope="row">Em deserto de saúde</th><td>${pessoasTexto(d.antes.deserto)}</td>`
        + `<td class="${d.depois.deserto < d.antes.deserto ? 'melhor' : ''}">${pessoasTexto(d.depois.deserto)}</td></tr>`;
    html += `<tr><th scope="row">Dos quais com 65+</th><td>${pessoasTexto(d.antes.deserto_65)}</td>`
        + `<td class="${d.depois.deserto_65 < d.antes.deserto_65 ? 'melhor' : ''}">${pessoasTexto(d.depois.deserto_65)}</td></tr>`;
    html += '</tbody></table>';

    html += '<ul class="factos">';
    html += `<li><strong>${pessoasTexto(g.pessoas_que_melhoram)} pessoas</strong> ficam mais perto do que estão hoje.</li>`;
    html += `<li><strong>${pessoasTexto(g.horas_pessoa_poupadas)} horas</strong> poupadas no total (${numero(g.pct_pessoas_minuto)}% do tempo que o país gasta hoje a chegar a cuidados).</li>`;
    if (g.sem_rota_passam_a_ter > 0) {
        html += `<li>${pessoasTexto(g.sem_rota_passam_a_ter)} pessoas que hoje não têm rota por estrada até ${COM_ARTIGO[d.tipo]} passam a ter.</li>`;
    }
    html += '</ul>';

    // O modelo é uma aproximação e isso vai escrito ao lado do resultado, não numa nota
    // de rodapé: quem citar estes números tem de saber a margem com que está a trabalhar.
    html += '<h3 class="sub-titulo">Até que ponto confiar</h3>';
    if (d.modelo_desatualizado) {
        html += '<p class="aviso">O modelo foi treinado com um mapa nacional anterior ao atual. '
            + 'No servidor, volta a correr <code>php scripts/treinar_modelo.php ' + esc(d.tipo) + '</code> '
            + 'antes de usar estes locais.</p>';
    }
    if (d.modelo) {
        html += `<p class="info">Os tempos foram estimados por um modelo de regressão local treinado nos tempos reais do OSRM: `
            + `erro médio de <strong>${numero(d.modelo.mae)} min</strong>, R² de ${numero(d.modelo.r2, 3)}, `
            + `medido em ${d.modelo.n} quadrículas que ficaram fora do treino.</p>`;
    }
    if (d.confirmacao && d.confirmacao.amostra) {
        html += `<p class="info">Confirmado com o OSRM em ${d.confirmacao.amostra} quadrículas das que mais beneficiam: `
            + `erro médio de <strong>${numero(d.confirmacao.mae)} min</strong>.</p>`;
    } else if (d.confirmacao && d.confirmacao.erro) {
        html += `<p class="aviso">A confirmação com o OSRM falhou: ${esc(d.confirmacao.erro)}</p>`;
    } else {
        html += '<div class="botoes"><button type="button" id="btn-confirmar-osrm" class="secundario">Confirmar com o OSRM</button></div>';
    }

    const ex = d.execucao;
    html += `<p class="info">Arrefecimento simulado: ${numero(ex.avaliacoes, 0)} configurações avaliadas em ${ex.segundos} s, `
        + `${ex.reinicios} reinícios sobre ${numero(ex.quadriculas, 0)} quadrículas de ${numero(ex.passo_km)} km. `
        + `Objetivo: ${ex.objetivo === 'deserto' ? 'tirar pessoas do deserto' : 'minimizar minutos no total'}. `
        + `${d.origem === 'pré-calculado no servidor' ? 'Resultado pré-calculado no servidor.' : ''}</p>`;
    html += '<div class="botoes"><button type="button" class="link" id="btn-limpar-propostas">Remover propostas do mapa</button></div>';

    $('resumo-otimizacao').innerHTML = html;

    $('resumo-otimizacao').querySelectorAll('.btn-ir-proposta').forEach((botao) => {
        botao.addEventListener('click', () => {
            mapa.setView([Number(botao.dataset.lat), Number(botao.dataset.lon)], 11);
        });
    });
    const btConfirmar = $('btn-confirmar-osrm');
    if (btConfirmar) btConfirmar.addEventListener('click', () => otimizarLocais(true));
    $('btn-limpar-propostas').addEventListener('click', () => {
        limparPropostas();
        $('resumo-otimizacao').innerHTML = '';
    });
}

$('btn-otimizar').addEventListener('click', () => otimizarLocais(false));

$('btn-retrato-pessoas').addEventListener('click', carregarRetratoPessoas);
$('btn-piores').addEventListener('click', carregarPioresZonas);
