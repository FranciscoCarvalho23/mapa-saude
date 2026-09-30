'use strict';

// Separador "Zonas": desertos de saúde, onde falta uma unidade e área de influência.

const selTipo = $('tipo-grelha');
TIPOS.forEach((tipo) => selTipo.add(new Option(CONFIG.tipos[tipo], tipo)));

// passo (km) para que a zona visível dê no máximo ~maxCelulas quadrículas
function passoParaZona(limites) {
    const sul = limites.getSouth();
    const norte = limites.getNorth();
    const alturaKm = (norte - sul) * 111;
    const larguraKm = (limites.getEast() - limites.getWest()) * 111 * Math.cos(((sul + norte) / 2) * Math.PI / 180);
    const passo = Math.sqrt((alturaKm * larguraKm) / (CONFIG.maxCelulas * 0.9));
    return Math.max(1, Math.ceil(passo * 2) / 2); // arredonda para cima, de 0,5 em 0,5 km
}

function parametrosDaZona() {
    const limites = mapa.getBounds();
    const passo = passoParaZona(limites);
    return {
        passo,
        demasiadoGrande: passo > 20,
        query: {
            sul: limites.getSouth().toFixed(4),
            oeste: limites.getWest().toFixed(4),
            norte: limites.getNorth().toFixed(4),
            este: limites.getEast().toFixed(4),
            passo,
        },
    };
}

// ---------- desertos de saúde ----------

$('btn-zona').addEventListener('click', () => {
    const zona = parametrosDaZona();
    if (zona.demasiadoGrande) {
        $('resumo-grelha').innerHTML = '<p class="erro">A zona visível é demasiado grande. Aproxima o mapa a um distrito ou concelho, ou usa o mapa nacional.</p>';
        return;
    }
    pedirGrelha({ tipo: selTipo.value, ...zona.query });
});

async function pedirGrelha(params) {
    const pedido = ++estado.pedidoZona;
    const botao = $('btn-zona');
    ocupado(botao, true);
    $('resumo-grelha').innerHTML = '<p class="info">A calcular as manchas de acesso. Pode demorar alguns segundos.</p>';

    try {
        const query = new URLSearchParams({ ...params, sem_tag: estado.semTag ? '1' : '0' });
        const dados = await api(`api/grelha.php?${query}`);
        if (pedido !== estado.pedidoZona) return;
        desenharManchas(dados, params.tipo, { origem: 'zona', params });
        mostrarResumoGrelha(dados, params.tipo);
    } catch (erro) {
        if (pedido === estado.pedidoZona) {
            $('resumo-grelha').innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
        }
    } finally {
        ocupado(botao, false);
    }
}

$('btn-nacional').addEventListener('click', async () => {
    const tipo = selTipo.value;
    estado.pedidoZona += 1; // cancela uma análise de zona que ainda esteja a correr
    try {
        const resposta = await fetch(`data/grelha_${tipo}.json`, { cache: 'no-cache' });
        if (!resposta.ok) {
            throw new Error(`Ainda não foi gerado o mapa nacional para «${CONFIG.tipos[tipo]}». No servidor, corre: php scripts/gerar_grelha.php ${tipo}`);
        }
        const dados = await resposta.json();
        if (!Array.isArray(dados.rasters)) {
            // ficheiro gerado por uma versão anterior, que guardava quadrículas soltas
            throw new Error(`O mapa nacional de «${CONFIG.tipos[tipo]}» está no formato antigo. No servidor, volta a correr: php scripts/gerar_grelha.php ${tipo}`);
        }
        desenharManchasMulti(dados.rasters, tipo, { origem: 'nacional' });
        mostrarResumoGrelha(juntarRasters(dados.rasters), tipo, dados.gerado_em);
        mapa.fitBounds(LIMITES_PORTUGAL);
    } catch (erro) {
        $('resumo-grelha').innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    }
});

// O mapa nacional vem em vários rasters (continente, Madeira, Açores). Para as
// percentagens basta juntar os valores, as marcas de terra e a população num só.
function juntarRasters(rasters) {
    const junto = {
        valores: rasters.flatMap((r) => r.valores),
        terra: rasters.flatMap((r) => r.terra),
        dLat: rasters[0].dLat,
    };
    if (rasters.every((r) => Array.isArray(r.populacao))) {
        junto.populacao = rasters.flatMap((r) => r.populacao);
        junto.populacao_65 = rasters.flatMap((r) => r.populacao_65 || r.populacao.map(() => 0));
    }
    return junto;
}

// Só as células em terra contam para as percentagens: o mar não é "sem dados",
// simplesmente não faz parte do território analisado.
//
// Devolve as contagens por área (quadrículas) e, quando o raster traz população,
// as mesmas contagens em pessoas. São duas leituras diferentes do mesmo mapa e
// costumam contar histórias opostas: o que é enorme em área é pequeno em gente.
function contarClasses(raster, tipo) {
    const contagem = { bom: 0, limitado: 0, deserto: 0, sem_dados: 0 };
    let total = 0;
    raster.valores.forEach((minutos, k) => {
        if (!raster.terra[k]) return;
        total += 1;
        contagem[classificar(tipo, minutos)] += 1;
    });

    let pop = null;
    if (Array.isArray(raster.populacao)) {
        const cont = { bom: 0, limitado: 0, deserto: 0, sem_dados: 0 };
        const cont65 = { bom: 0, limitado: 0, deserto: 0, sem_dados: 0 };
        let pessoas = 0;
        let pessoas65 = 0;
        let somaMin = 0;
        let peso = 0;
        raster.populacao.forEach((gente, k) => {
            if (!gente) return;
            const g65 = (raster.populacao_65 && raster.populacao_65[k]) || 0;
            const classe = classificar(tipo, raster.valores[k]);
            pessoas += gente;
            pessoas65 += g65;
            cont[classe] += gente;
            cont65[classe] += g65;
            if (raster.valores[k] !== null && raster.valores[k] !== undefined) {
                somaMin += gente * raster.valores[k];
                peso += gente;
            }
        });
        pop = {
            contagem: cont,
            contagem65: cont65,
            total: pessoas,
            total65: pessoas65,
            minutosMedios: peso ? somaMin / peso : null,
        };
    }

    return { contagem, total, pop };
}

function mostrarResumoGrelha(raster, tipo, geradoEm = null) {
    const caixa = $('resumo-grelha');
    const { contagem, total, pop } = contarClasses(raster, tipo);
    const passoKm = raster.dLat * 111;
    if (!total) {
        caixa.innerHTML = '<p class="info">Não há território português nesta zona.</p>';
        return;
    }

    const classes = ['bom', 'limitado', 'deserto', 'sem_dados'];
    const limite = CONFIG.limiares[tipo][1];
    // Por omissão mostra pessoas: é a leitura honesta. A área fica a um clique, porque
    // continua a interessar a quem pensa em cobertura de território.
    const porPessoas = pop && pop.total > 0 && estado.medidaZona !== 'area';
    const base = porPessoas ? pop.contagem : contagem;
    const soma = porPessoas ? pop.total : total;
    const pct = (c) => (base[c] / soma) * 100;

    let html = '';
    if (pop && pop.total > 0) {
        html += '<div class="alternador" role="group" aria-label="Medir em pessoas ou em área">'
            + `<button type="button" class="${porPessoas ? 'ativo' : ''}" data-medida="pessoas">Pessoas</button>`
            + `<button type="button" class="${porPessoas ? '' : 'ativo'}" data-medida="area">Área</button></div>`;
    }

    if (porPessoas) {
        html += `<p class="destaque">${pessoasTexto(pop.contagem.deserto)} pessoas desta zona `
            + `(${numero(pct('deserto'), 0)}%) ficam a mais de ${limite} min de ${COM_ARTIGO[tipo]}.</p>`;
    } else {
        html += `<p class="destaque">${numero(pct('deserto'), 0)}% da área analisada fica a mais de ${limite} min de ${COM_ARTIGO[tipo]}.</p>`;
    }

    html += `<div class="barra-resumo">${classes.map((c) => `<span style="width:${pct(c)}%;background:${CORES_CLASSE[c]}" title="${textoClasse(tipo, c, 'grelha')}"></span>`).join('')}</div>`;
    html += `<ul class="legenda">${classes.map((c) => {
        const valor = porPessoas ? `${pessoasTexto(base[c])} pessoas` : `${numero(pct(c), 0)}%`;
        return `<li><span class="quadrado" style="background:${CORES_CLASSE[c]}"></span>${textoClasse(tipo, c, 'grelha')}: ${valor}`
            + (porPessoas ? ` <span class="sub">(${numero(pct(c), 0)}%)</span>` : '') + '</li>';
    }).join('')}</ul>`;

    if (pop && pop.total > 0) {
        html += `<ul class="factos"><li>Vivem aqui <strong>${pessoasTexto(pop.total)} pessoas</strong>, `
            + `<strong>${pessoasTexto(pop.total65)}</strong> com 65 ou mais anos.</li>`;
        if (pop.minutosMedios !== null) {
            html += `<li>Tempo médio por pessoa: <strong>${numero(pop.minutosMedios)} min</strong>`
                + ' <span class="sub">(média ponderada pela população, não por quadrícula)</span></li>';
        }
        if (pop.contagem65.deserto) {
            const p65 = (pop.contagem65.deserto / pop.total65) * 100;
            const pTodos = (pop.contagem.deserto / pop.total) * 100;
            html += `<li>Entre os 65+: <strong>${numero(p65, 1)}%</strong> em deserto de saúde`
                + (pTodos > 0 ? `, contra ${numero(pTodos, 1)}% no total` : '') + '.</li>';
        }
        html += '</ul>';
    }

    // O que se vê no mapa. É aqui que o projeto responde à pergunta de investimento:
    // não "onde é longe", mas "onde é longe E vive lá muita gente".
    if (pop && pop.total > 0) {
        html += '<h3 class="sub-titulo">No mapa</h3>';
        html += '<div class="alternador alternador-mapa" role="group" aria-label="O que mostrar no mapa">'
            + [['acesso', 'Acesso'], ['pessoas', 'Pessoas'], ['critico', 'Zonas críticas']]
                .map(([v, r]) => `<button type="button" class="${estado.mapaZona === v ? 'ativo' : ''}" data-mapa="${v}">${r}</button>`)
                .join('') + '</div>';
        html += `<p class="info" id="legenda-mapa-pessoas">${legendaModoMapa(tipo)}</p>`;
    }

    let nota = `Calculado numa grelha de ${numero(passoKm)} km (${total} pontos em terra) e desenhado `
        + 'como manchas contínuas.';
    nota += pop && pop.total > 0
        ? ' População residente dos Censos 2021 (grelha de 1 km do Eurostat), somada a cada quadrícula.'
        : ' Percentagens de área, não de população.';
    if (geradoEm) nota += ` Mapa pré-calculado em ${new Date(geradoEm).toLocaleDateString('pt-PT')}.`;
    html += `<p class="info">${nota}</p>`;
    html += '<div class="botoes"><button type="button" class="link" id="btn-limpar-grelha">Remover manchas</button></div>';

    caixa.innerHTML = html;

    caixa.querySelectorAll('.alternador button[data-medida]').forEach((botao) => {
        botao.addEventListener('click', () => {
            estado.medidaZona = botao.dataset.medida;
            mostrarResumoGrelha(raster, tipo, geradoEm);
        });
    });
    caixa.querySelectorAll('.alternador button[data-mapa]').forEach((botao) => {
        botao.addEventListener('click', () => {
            estado.mapaZona = botao.dataset.mapa;
            aplicarModoMapa(tipo);
            mostrarResumoGrelha(raster, tipo, geradoEm);
        });
    });
    aplicarModoMapa(tipo);
    $('btn-limpar-grelha').addEventListener('click', () => {
        estado.pedidoZona += 1;
        limparGrelha();
        caixa.innerHTML = '';
    });
}


// Aplica ao mapa o modo escolhido, usando os rasters que já lá estão desenhados.
// Não faz pedidos: a população já veio com a análise.
function aplicarModoMapa(tipo) {
    if (!camadas.grelha) return;
    if (estado.mapaZona === 'acesso') {
        limparPessoas();
        return;
    }
    const rasters = camadas.grelha.rasters || [camadas.grelha.raster];
    if (!rasters.some((r) => Array.isArray(r.populacao))) {
        limparPessoas();
        return;
    }
    desenharPessoas(rasters, tipo, estado.mapaZona === 'critico' ? 'critico' : 'pessoas');
}

function legendaModoMapa(tipo) {
    const limite = CONFIG.limiares[tipo][1];
    if (estado.mapaZona === 'pessoas') {
        return 'Cada círculo é uma quadrícula: o tamanho é a população que lá vive '
            + '(a área do círculo, não o raio), a cor é a classe de acesso. '
            + 'Serve para ver onde é que as manchas de cor apanham gente e onde apanham serra.';
    }
    if (estado.mapaZona === 'critico') {
        return `Só as quadrículas acima de ${limite} min. Tamanho = pessoas afetadas, `
            + 'cor = quanto ultrapassa o limiar (laranja até 10 min a mais, vermelho escuro acima de uma hora). '
            + 'Os círculos grandes e escuros são onde uma unidade nova muda mais vidas.';
    }
    return 'As manchas mostram o tempo até à unidade mais próxima, sem contar quanta gente lá vive.';
}

// ---------- onde falta uma unidade ----------
//
// Ferramenta de decisão: mostra onde uma unidade nova daria mais jeito na zona visível
// e quanto melhoraria o acesso de toda a gente ali. Não substitui um estudo, mas dá
// ordens de grandeza que hoje ninguém consegue ver num mapa.

$('btn-planear').addEventListener('click', async () => {
    const zona = parametrosDaZona();
    const caixa = $('resumo-planeamento');
    if (zona.demasiadoGrande) {
        caixa.innerHTML = '<p class="erro">A zona visível é demasiado grande. Aproxima o mapa a um concelho ou distrito.</p>';
        return;
    }

    const pedido = ++estado.pedidoZona;
    const botao = $('btn-planear');
    ocupado(botao, true);
    caixa.innerHTML = '<p class="info">A testar locais na zona visível. Isto demora mais do que a análise normal.</p>';

    try {
        const query = new URLSearchParams({
            tipo: selTipo.value, ...zona.query, sem_tag: estado.semTag ? '1' : '0',
        });
        const dados = await api(`api/planeamento.php?${query}`);
        if (pedido !== estado.pedidoZona) return;
        mostrarPlaneamento(dados);
    } catch (erro) {
        if (pedido === estado.pedidoZona) caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    } finally {
        ocupado(botao, false);
    }
});

function mostrarPlaneamento(dados) {
    desenharManchas(dados.raster, dados.tipo, { origem: 'planeamento' });

    camadas.proposta = L.marker([dados.local.lat, dados.local.lon], {
        icon: L.divIcon({ className: 'marca-proposta', html: '+', iconSize: [30, 30], iconAnchor: [15, 15] }),
        title: 'Local proposto',
    }).addTo(mapa).bindTooltip('Local proposto para uma nova unidade');
    mapa.setView([dados.local.lat, dados.local.lon], Math.max(mapa.getZoom(), 10));

    const ganhoPct = dados.antes.minutos_medios > 0
        ? Math.round((dados.ganho_minutos / dados.antes.minutos_medios) * 100) : 0;

    let html = `<p class="destaque">${esc(CONFIG.tipos[dados.tipo])} em ${dados.local.lat.toFixed(4)}, ${dados.local.lon.toFixed(4)}</p>`;
    html += '<table class="tabela-antes-depois"><thead><tr><th></th><th>Agora</th><th>Com a nova</th></tr></thead><tbody>';
    html += `<tr><th scope="row">Tempo médio</th><td>${numero(dados.antes.minutos_medios)} min</td>`
        + `<td class="melhor">${numero(dados.depois.minutos_medios)} min</td></tr>`;
    html += `<tr><th scope="row">Área em deserto</th><td>${dados.antes.deserto_pct}%</td>`
        + `<td class="${dados.depois.deserto_pct < dados.antes.deserto_pct ? 'melhor' : ''}">${dados.depois.deserto_pct}%</td></tr>`;
    html += '</tbody></table>';
    const pop = dados.populacao;
    if (pop) {
        html += '<h3 class="sub-titulo">O que isto muda para as pessoas</h3>';
        html += '<ul class="factos">';
        html += `<li>Vivem nesta zona <strong>${pessoasTexto(pop.total_na_zona)} pessoas</strong>.</li>`;
        if (pop.saem_do_deserto > 0) {
            html += `<li><strong>${pessoasTexto(pop.saem_do_deserto)} deixam o deserto de saúde</strong>`
                + (pop.saem_do_deserto_65 > 0 ? `, das quais ${pessoasTexto(pop.saem_do_deserto_65)} com 65+` : '')
                + ` <span class="sub">(${pessoasTexto(pop.deserto_antes)} antes, ${pessoasTexto(pop.deserto_depois)} depois)</span></li>`;
        } else if (pop.deserto_antes === 0) {
            html += '<li>Já não havia ninguém em deserto de saúde nesta zona — a unidade nova encurta caminhos, não resolve exclusões.</li>';
        }
        if (pop.pessoas_que_melhoram > 0) {
            html += `<li><strong>${pessoasTexto(pop.pessoas_que_melhoram)} pessoas</strong> ficam mais perto do que estão hoje.</li>`;
        }
        if (pop.ganho_minutos_por_pessoa !== null) {
            html += `<li>Tempo médio por pessoa: <strong>${numero(pop.minutos_medios_antes)} → ${numero(pop.minutos_medios_depois)} min</strong>`
                + ` <span class="sub">(menos ${numero(pop.ganho_minutos_por_pessoa)} min)</span></li>`;
        }
        if (pop.horas_pessoa_poupadas > 0) {
            html += `<li><strong>${pessoasTexto(pop.horas_pessoa_poupadas)} horas</strong> poupadas no total, `
                + 'se toda a gente da zona fizesse a viagem uma vez '
                + '<span class="sub">(é a moeda que permite comparar dois locais entre si)</span></li>';
        }
        html += '</ul>';
    }

    html += `<p class="info">Poupa ${numero(dados.ganho_minutos)} min em média (${ganhoPct}%) por quadrícula. `
        + `${dados.candidatos_testados} locais testados, num cálculo de ${numero(dados.passo_km)} em ${numero(dados.passo_km)} km. `
        + (pop
            ? 'Os candidatos foram escolhidos onde mais gente está mal servida, e não pelo ponto mais remoto — '
              + 'sem população, o método punha a unidade no monte mais isolado da zona. '
            : 'Sem dados de população, os candidatos são os pontos com pior tempo, ainda que lá não viva ninguém. ')
        + `O mapa mostra os tempos já com a unidade proposta.</p>`;
    html += '<div class="botoes"><button type="button" class="link" id="btn-limpar-plano">Remover proposta</button></div>';

    $('resumo-planeamento').innerHTML = html;
    $('btn-limpar-plano').addEventListener('click', () => {
        limparGrelha();
        $('resumo-planeamento').innerHTML = '';
    });
}

// ---------- área de influência ----------
//
// "Que território é que este hospital serve na prática?" — não é a área administrativa
// que lhe está atribuída, é aquela de que ele é mesmo o mais próximo por estrada.

async function verInfluencia(unidade) {
    mostrarSeparador('zonas');
    const caixa = $('resumo-influencia');
    const tipo = unidade.tipos.includes(selTipo.value) ? selTipo.value : unidade.tipos[0];
    selTipo.value = tipo;

    const zona = parametrosDaZona();
    if (zona.demasiadoGrande) {
        caixa.innerHTML = '<p class="erro">A zona visível é demasiado grande. Aproxima o mapa à volta da unidade.</p>';
        return;
    }

    const pedido = ++estado.pedidoZona;
    caixa.innerHTML = `<p class="info">A calcular a área servida por ${esc(unidade.nome)}…</p>`;

    try {
        const query = new URLSearchParams({
            id: unidade.id, tipo, ...zona.query, sem_tag: estado.semTag ? '1' : '0',
        });
        const dados = await api(`api/influencia.php?${query}`);
        if (pedido !== estado.pedidoZona) return;
        desenharManchas(dados.raster, tipo, { destacar: true, origem: 'influencia' });
        mostrarInfluencia(dados);
    } catch (erro) {
        if (pedido === estado.pedidoZona) caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    }
}

function mostrarInfluencia(dados) {
    const r = dados.resumo;
    let html = `<p class="destaque">${esc(dados.unidade.nome)}</p>`;

    if (!r.celulas_servidas) {
        html += '<p class="info">Nesta zona não há nenhum sítio de que esta unidade seja a mais próxima. '
            + 'Há outra mais perto em todo o lado. Experimenta afastar o mapa.</p>';
    } else {
        const pct = Math.round((r.celulas_servidas / r.celulas_total) * 100);
        if (r.pessoas && r.pessoas.servidas > 0) {
            html += `<p class="numero-destaque">É a unidade mais próxima de <strong>${pessoasTexto(r.pessoas.servidas)} pessoas</strong> nesta zona.</p>`;
        }
        html += `<p>É a mais próxima em <strong>${pct}%</strong> da zona visível, cerca de <strong>${numero(r.area_km2, 0)} km²</strong>.</p>`;
        html += `<ul class="factos"><li>Tempo médio de quem lá está: <strong>${numero(r.minutos_medios)} min</strong></li>`;
        if (r.pessoas && r.pessoas.servidas > 0) {
            html += `<li>Com 65 ou mais anos: <strong>${pessoasTexto(r.pessoas.servidas_65)}</strong>`
                + (r.pessoas.pct_65 !== null ? ` <span class="sub">(${numero(r.pessoas.pct_65)}% de quem serve)</span>` : '') + '</li>';
            if (r.pessoas.minutos_medios_por_pessoa !== null) {
                html += `<li>Tempo médio por pessoa: <strong>${numero(r.pessoas.minutos_medios_por_pessoa)} min</strong>`
                    + ' <span class="sub">(conta gente, não quadrículas)</span></li>';
            }
            if (r.pessoas.outras_na_zona > 0) {
                html += `<li>Outras <strong>${pessoasTexto(r.pessoas.outras_na_zona)} pessoas</strong> desta zona têm outra unidade mais perto.</li>`;
            }
        }
        if (r.ponto_mais_distante) {
            html += `<li>Ponto mais afastado que serve: <strong>${minutosTexto(r.ponto_mais_distante.minutos)} min</strong>`
                + ` <button type="button" class="link" id="btn-ver-distante">ver no mapa</button></li>`;
        }
        html += '</ul>';
    }
    html += '<p class="info">A mancha a cor é a que esta unidade serve; o resto da zona fica esbatido.</p>';
    html += '<div class="botoes"><button type="button" class="link" id="btn-limpar-influencia">Remover</button></div>';

    $('resumo-influencia').innerHTML = html;
    const btDistante = $('btn-ver-distante');
    if (btDistante) {
        btDistante.addEventListener('click', () => {
            mapa.setView([r.ponto_mais_distante.lat, r.ponto_mais_distante.lon], 12);
        });
    }
    $('btn-limpar-influencia').addEventListener('click', () => {
        limparGrelha();
        $('resumo-influencia').innerHTML = '';
    });
}

// volta a calcular o que estiver no ecrã com as opções atuais
function recalcular() {
    if (estado.ponto) analisarPonto();
    if (camadas.grelha && camadas.grelha.origem === 'zona') pedirGrelha(camadas.grelha.params);
    estado.guardadas.forEach((m) => { m.resumo = null; });
    desenharGuardadas();
    atualizarResumosGuardadas();
    estado.comparacao.forEach((item) => calcularComparacao(item));
}
