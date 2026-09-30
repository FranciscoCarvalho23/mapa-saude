'use strict';

// Separador "Local": pesquisa, análise de um ponto, percurso e ficha de emergência.

// ---------- pesquisa ----------
//
// A caixa procura duas coisas: unidades de saúde pelo nome (feito aqui no browser, sobre os
// dados já carregados, por isso responde a cada tecla sem pedir nada a ninguém) e moradas no
// Nominatim (só ao submeter, porque a política deles não permite pedir a cada tecla).

function unidadesPorNome(texto, limite = 6) {
    const procurado = normalizar(texto);
    if (procurado.length < 2) return [];
    const comeca = [];
    const contem = [];
    for (const u of estado.unidades) {
        const nome = normalizar(u.nome);
        if (nome.startsWith(procurado)) comeca.push(u);
        else if (nome.includes(procurado)) contem.push(u);
        if (comeca.length >= limite) break;
    }
    return [...comeca, ...contem].slice(0, limite);
}

function desenharSugestoes(grupos) {
    const lista = $('sugestoes');
    lista.innerHTML = '';
    let vazio = true;

    grupos.forEach((grupo) => {
        if (!grupo.itens.length) return;
        vazio = false;
        const titulo = document.createElement('li');
        titulo.className = 'grupo';
        titulo.textContent = grupo.titulo;
        titulo.setAttribute('role', 'presentation');
        lista.appendChild(titulo);

        grupo.itens.forEach((item) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.innerHTML = `<span class="sug-nome">${esc(item.nome)}</span>`
                + (item.detalhe ? `<span class="sug-detalhe">${esc(item.detalhe)}</span>` : '');
            botao.addEventListener('click', () => {
                lista.innerHTML = '';
                item.accao();
            });
            li.appendChild(botao);
            lista.appendChild(li);
        });
    });

    if (vazio) lista.innerHTML = '';
}

function sugestoesLocais() {
    const texto = $('morada').value.trim();
    if (texto.length < 2) {
        $('sugestoes').innerHTML = '';
        return;
    }
    desenharSugestoes([{
        titulo: 'Unidades de saúde',
        itens: unidadesPorNome(texto).map((u) => ({
            nome: u.nome,
            detalhe: [u.localidade, rotuloTipos(u.tipos)].filter(Boolean).join(' · '),
            accao: () => {
                $('morada').value = u.nome;
                focarUnidade(u);
                escolherPonto(u.lat, u.lon, u.nome);
            },
        })),
    }]);
}

$('morada').addEventListener('input', sugestoesLocais);

$('form-pesquisa').addEventListener('submit', async (e) => {
    e.preventDefault();
    const texto = $('morada').value.trim();
    const lista = $('sugestoes');
    const unidades = unidadesPorNome(texto);

    // se só há uma unidade com este nome e nada mais óbvio, vai direto
    lista.innerHTML = '<li class="info">A procurar moradas…</li>';

    if (estado.offline) {
        desenharSugestoes([{
            titulo: 'Unidades de saúde (sem ligação)',
            itens: unidades.map((u) => ({
                nome: u.nome,
                detalhe: u.localidade,
                accao: () => escolherPonto(u.lat, u.lon, u.nome),
            })),
        }]);
        if (!unidades.length) lista.innerHTML = '<li class="info">Sem ligação: só é possível procurar unidades pelo nome.</li>';
        return;
    }

    try {
        const moradas = await api(`api/geocode.php?q=${encodeURIComponent(texto)}`);
        const grupos = [
            {
                titulo: 'Moradas',
                itens: moradas.map((r) => ({
                    nome: r.nome,
                    detalhe: null,
                    accao: () => {
                        $('morada').value = r.nome;
                        escolherPonto(r.lat, r.lon, r.nome, true);
                    },
                })),
            },
            {
                titulo: 'Unidades de saúde',
                itens: unidades.map((u) => ({
                    nome: u.nome,
                    detalhe: u.localidade,
                    accao: () => {
                        focarUnidade(u);
                        escolherPonto(u.lat, u.lon, u.nome);
                    },
                })),
            },
        ];
        if (!moradas.length && !unidades.length) {
            lista.innerHTML = '<li class="info">Nada encontrado. Experimenta acrescentar a localidade, por exemplo «Rua de Santa Catarina, Porto».</li>';
            return;
        }
        if (moradas.length === 1 && !unidades.length) {
            lista.innerHTML = '';
            escolherPonto(moradas[0].lat, moradas[0].lon, moradas[0].nome, true);
            return;
        }
        desenharSugestoes(grupos);
    } catch (erro) {
        lista.innerHTML = `<li class="erro">${esc(erro.message)}</li>`;
    }
});

$('btn-localizacao').addEventListener('click', () => {
    if (!navigator.geolocation) {
        $('sugestoes').innerHTML = '<li class="erro">Este browser não permite obter a localização.</li>';
        return;
    }
    $('sugestoes').innerHTML = '<li class="info">A obter a localização…</li>';
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            $('sugestoes').innerHTML = '';
            escolherPonto(pos.coords.latitude, pos.coords.longitude, null, true);
        },
        () => {
            $('sugestoes').innerHTML = '<li class="erro">Não foi possível obter a localização. Autoriza o acesso no browser ou pesquisa a morada.</li>';
        },
        { timeout: 10000 },
    );
});

// ---------- análise de um ponto ----------

function escolherPonto(lat, lon, nome = null, ajustar = false) {
    estado.ponto = { lat, lon, nome, ajustar };
    estado.resultados = null;
    marcarPonto(lat, lon);
    mostrarSeparador('local');

    if (!nome && !estado.offline) {
        const ponto = estado.ponto;
        api(`api/geocode.php?lat=${lat}&lon=${lon}`)
            .then((r) => {
                if (estado.ponto !== ponto || !r.nome) return;
                ponto.nome = r.nome;
                const local = document.querySelector('#resultados .local');
                if (local) local.textContent = r.nome;
            })
            .catch(() => {}); // sem morada, ficam as coordenadas
    }

    analisarPonto();
}

function nomeDoPonto() {
    const p = estado.ponto;
    return p.nome || `${p.lat.toFixed(5)}, ${p.lon.toFixed(5)}`;
}

async function analisarPonto() {
    const pedido = ++estado.pedidoPonto;
    const { lat, lon } = estado.ponto;
    const caixa = $('resultados');
    caixa.innerHTML = '<p class="info">A calcular tempos de viagem…</p>';
    limparRota();

    if (estado.offline) {
        mostrarResultadosOffline();
        return;
    }

    const params = new URLSearchParams({
        lat: lat.toFixed(5),
        lon: lon.toFixed(5),
        sem_tag: estado.semTag ? '1' : '0',
    });

    try {
        const dados = await api(`api/tempos.php?${params}`);
        if (pedido !== estado.pedidoPonto) return; // entretanto foi escolhido outro ponto
        estado.resultados = dados;
        mostrarResultados(dados);
    } catch (erro) {
        if (pedido !== estado.pedidoPonto) return;
        caixa.innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
        $('bloco-acoes-local').hidden = true;
    }
}

// Sem ligação não há OSRM: mostra-se a distância em linha reta, dito de forma clara.
// Não é o tempo de viagem, mas num aperto saber qual é o hospital mais perto já ajuda.
function mostrarResultadosOffline() {
    const { lat, lon } = estado.ponto;
    let html = `<p class="local">${esc(nomeDoPonto())}</p>`;
    html += '<p class="aviso">Sem ligação. Distâncias em linha reta, a partir dos dados guardados no dispositivo — o tempo de carro será maior.</p>';

    TIPOS.forEach((tipo) => {
        const lista = estado.unidades
            .filter((u) => u.tipos.includes(tipo) && (tipo !== 'urgencia' || estado.semTag || u.urgencia === 'sim'))
            .map((u) => ({ u, km: distanciaKm(lat, lon, u.lat, u.lon) }))
            .sort((a, b) => a.km - b.km)
            .slice(0, 3);
        html += `<section class="resultado r-sem_dados"><header><h3>${esc(CONFIG.tipos[tipo])}</h3>`
            + `<p class="tempo">${lista.length ? `<strong>${numero(lista[0].km)}</strong> km` : '<strong>–</strong>'}</p></header>`;
        if (lista.length) {
            html += `<p class="destino">${esc(lista[0].u.nome)}${lista[0].u.localidade ? `, ${esc(lista[0].u.localidade)}` : ''}</p>`;
        }
        html += '</section>';
    });

    $('resultados').innerHTML = html;
    $('bloco-acoes-local').hidden = false;
    desenharComparacao(); // o botão "Juntar à comparação" depende de haver um ponto escolhido
}

function mostrarResultados(dados) {
    const ponto = estado.ponto;
    estado.opcoes = [];

    let html = `<p class="local">${esc(nomeDoPonto())}</p>`;
    if (dados.origem.estrada_m > 1000) {
        html += `<p class="aviso">O ponto fica a ${numero(dados.origem.estrada_m / 1000)} km da estrada mais próxima; o tempo real será maior.</p>`;
    }
    TIPOS.forEach((tipo) => {
        html += blocoResultado(tipo, dados.resultados[tipo] || [], (dados.comparacao_nacional || {})[tipo]);
    });
    html += blocoVizinhanca(dados.vizinhanca);

    const caixa = $('resultados');
    caixa.innerHTML = html;
    caixa.querySelectorAll('[data-opcao]').forEach((botao) => {
        botao.addEventListener('click', () => verRota(estado.opcoes[botao.dataset.opcao]));
    });
    $('bloco-acoes-local').hidden = false;
    desenharComparacao(); // o botão "Juntar à comparação" depende de haver um ponto escolhido

    // vindo da pesquisa, enquadra o ponto e as unidades mais próximas
    if (ponto.ajustar) {
        const pontos = [[ponto.lat, ponto.lon]];
        TIPOS.forEach((tipo) => {
            const melhor = (dados.resultados[tipo] || [])[0];
            if (melhor && melhor.minutos !== null) pontos.push([melhor.lat, melhor.lon]);
        });
        mapa.fitBounds(pontos, { padding: [50, 50], maxZoom: 13 });
        ponto.ajustar = false;
    }
}

// Quanta gente partilha este sítio. Serve para duas coisas: dá escala ao problema
// ("não sou só eu") e, numa zona vazia, explica porque é que não há lá uma unidade.
function blocoVizinhanca(v) {
    if (!v || !v.pessoas) return '';
    // Fica de propósito fora da classe .resultado: não é o resultado de um tipo de
    // cuidado, é contexto sobre o sítio. Misturá-los baralhava a leitura e os testes.
    let html = '<section class="contexto"><h3>À tua volta</h3>';
    html += `<p><strong class="numero-grande">${pessoasTexto(v.pessoas)}</strong> pessoas vivem a menos de ${v.raio_km} km daqui`;
    if (v.pessoas_65) {
        html += `, ${pessoasTexto(v.pessoas_65)} com 65 ou mais anos`
            + (v.pct_65 !== null ? ` (${numero(v.pct_65)}%)` : '');
    }
    html += '.</p>';
    html += '<p class="info">População residente dos Censos 2021, grelha de 1 km do Eurostat.</p>';
    html += '</section>';
    return html;
}

function botaoRota(opcao, texto) {
    estado.opcoes.push(opcao);
    return `<button type="button" class="link" data-opcao="${estado.opcoes.length - 1}">${texto}</button>`;
}

function blocoResultado(tipo, opcoes, comparacao = null) {
    const alcancaveis = opcoes.filter((o) => o.minutos !== null);
    const melhor = alcancaveis[0] || null;
    const minutos = melhor ? melhor.minutos : null;
    const classe = classificar(tipo, minutos);

    const [bom, limite] = CONFIG.limiares[tipo];
    const maximo = limite * 1.5;
    const marca = minutos === null ? '' : `<i class="marca" style="left:${(Math.min(minutos, maximo) / maximo) * 100}%"></i>`;

    let html = `
        <section class="resultado r-${classe}">
            <header>
                <h3>${esc(CONFIG.tipos[tipo])}</h3>
                <p class="tempo">${minutos === null ? '<strong>–</strong>' : `<strong>${minutosTexto(minutos)}</strong> min`}</p>
            </header>
            <div class="escala" aria-hidden="true">
                <span style="flex:${bom};background:var(--bom)"></span>
                <span style="flex:${limite - bom};background:var(--limitado)"></span>
                <span style="flex:${maximo - limite};background:var(--deserto)"></span>
                ${marca}
            </div>
            <p class="estado">${textoClasse(tipo, classe)}</p>`;

    // Um tempo sozinho não diz se é bom ou mau: 25 minutos parecem muito a quem vive
    // em Lisboa e são um luxo para quem vive no interior. O percentil dá a régua.
    if (comparacao && comparacao.pct_melhor !== null) {
        const melhorQue = comparacao.pct_melhor;
        let frase;
        if (melhorQue >= 99.5) {
            frase = 'Está entre os piores acessos do país';
        } else if (melhorQue >= 50) {
            frase = `Pior do que o acesso de <strong>${numero(melhorQue)}%</strong> dos portugueses`;
        } else {
            frase = `Melhor do que o acesso de <strong>${numero(100 - melhorQue)}%</strong> dos portugueses`;
        }
        html += `<p class="percentil">${frase}`
            + (comparacao.mediana_nacional !== null
                ? ` <span class="sub">(mediana nacional: ${minutosTexto(comparacao.mediana_nacional)} min)</span>` : '')
            + '</p>';
    }

    if (melhor) {
        html += `<p class="destino">${esc(melhor.nome)}${melhor.km !== null ? `, ${numero(melhor.km)} km por estrada` : ''}</p>`;
        if (tipo === 'urgencia' && melhor.urgencia !== 'sim') {
            html += '<p class="aviso">Este hospital não tem a urgência confirmada no OpenStreetMap.</p>';
        }
        html += `<div class="acoes">${botaoRota(melhor, 'Ver percurso')}</div>`;

        const outras = alcancaveis.slice(1);
        if (outras.length) {
            html += '<details><summary>Outras opções próximas</summary><ol>';
            outras.forEach((o) => {
                html += `<li>${esc(o.nome)}: ${minutosTexto(o.minutos)} min ${botaoRota(o, 'ver percurso')}</li>`;
            });
            html += '</ol></details>';
        }
    }

    return `${html}</section>`;
}

// ---------- percurso ----------

async function verRota(opcao) {
    limparRota();
    const { lat, lon } = estado.ponto;
    const params = new URLSearchParams({
        lat1: lat.toFixed(5), lon1: lon.toFixed(5), lat2: opcao.lat, lon2: opcao.lon,
    });
    $('bloco-percurso').hidden = false;
    $('percurso').innerHTML = '<p class="info">A calcular o percurso…</p>';

    try {
        const rota = await api(`api/rota.php?${params}`);
        camadas.rota = L.polyline(rota.linha, {
            renderer: rendererMapa, color: '#2457A6', weight: 5, opacity: 0.85, interactive: false,
        }).addTo(mapa);
        if (camadas.ponto) camadas.ponto.bringToFront();
        mapa.fitBounds(camadas.rota.getBounds(), { padding: [40, 40] });
        mostrarPercurso(opcao, rota);
    } catch (erro) {
        $('percurso').innerHTML = `<p class="erro">${esc(erro.message)}</p>`;
    }
}

function mostrarPercurso(destino, rota) {
    let html = `<p class="destino">Até ${esc(destino.nome)}</p>`;
    html += `<p class="tempo-percurso"><strong>${duracaoTexto(rota.minutos)}</strong> · ${numero(rota.km)} km</p>`;

    if (rota.passos && rota.passos.length) {
        html += '<ol class="passos">';
        rota.passos.forEach((passo) => {
            html += `<li><span class="passo-texto">${esc(passo.texto)}</span>`;
            if (passo.metros > 0) html += `<span class="passo-dist">${distanciaTexto(passo.metros)}</span>`;
            html += '</li>';
        });
        html += '</ol>';
    }
    html += '<button type="button" class="link" id="btn-limpar-rota">Remover percurso</button>';

    $('percurso').innerHTML = html;
    $('btn-limpar-rota').addEventListener('click', limparRota);
}

// ---------- ficha de emergência ----------
//
// Um cartão A4 para pôr no frigorífico ou dar a quem fica com os miúdos: qual é a urgência
// mais próxima, quanto tempo demora, as coordenadas para o GPS e os números que se ligam.
// É o único bloco que sai na impressão (ver @media print).

function construirFicha() {
    if (!estado.resultados) return false;
    const r = estado.resultados.resultados;
    const agora = new Date().toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' });

    let html = `<h2>Ficha de emergência</h2>`;
    html += `<p class="ficha-morada"><strong>${esc(nomeDoPonto())}</strong></p>`;
    html += `<p class="ficha-coord">Coordenadas para o GPS: ${estado.ponto.lat.toFixed(5)}, ${estado.ponto.lon.toFixed(5)}</p>`;

    html += '<div class="ficha-numeros"><div><span class="ficha-num">112</span><span>Emergência médica</span></div>'
        + '<div><span class="ficha-num">808 24 24 24</span><span>SNS 24 — aconselhamento</span></div></div>';

    TIPOS.forEach((tipo) => {
        const lista = (r[tipo] || []).filter((o) => o.minutos !== null);
        if (!lista.length) return;
        html += `<section class="ficha-tipo"><h3>${esc(CONFIG.tipos[tipo])}</h3><ol>`;
        lista.slice(0, 3).forEach((o) => {
            html += `<li><strong>${esc(o.nome)}</strong>`;
            if (o.localidade) html += `, ${esc(o.localidade)}`;
            html += `<br><span class="ficha-detalhe">${minutosTexto(o.minutos)} min de carro`;
            if (o.km !== null) html += ` · ${numero(o.km)} km`;
            html += ` · ${o.lat.toFixed(5)}, ${o.lon.toFixed(5)}</span>`;
            if (tipo === 'urgencia' && o.urgencia !== 'sim') {
                html += '<br><span class="ficha-detalhe">Urgência não confirmada — confirma antes de ir.</span>';
            }
            html += '</li>';
        });
        html += '</ol></section>';
    });

    html += `<p class="ficha-rodape">Tempos estimados pela rede de estradas (OSRM), sem trânsito. `
        + `Dados das unidades: OpenStreetMap. Ficha gerada a ${agora}. Em emergência, liga 112.</p>`;

    $('ficha').innerHTML = html;
    $('ficha').hidden = false;
    $('ficha').setAttribute('aria-hidden', 'false');
    return true;
}

$('btn-ficha').addEventListener('click', () => {
    if (!construirFicha()) return;
    window.print();
});

// depois de imprimir, volta a esconder a ficha
window.addEventListener('afterprint', () => {
    $('ficha').hidden = true;
    $('ficha').setAttribute('aria-hidden', 'true');
});

// ---------- exportar ----------

$('btn-csv-local').addEventListener('click', () => {
    if (!estado.resultados) return;
    const linhas = [['morada', 'lat', 'lon', 'tipo', 'posicao', 'unidade', 'localidade', 'minutos', 'km', 'classificacao']];
    TIPOS.forEach((tipo) => {
        (estado.resultados.resultados[tipo] || []).forEach((o, i) => {
            linhas.push([
                nomeDoPonto(), estado.ponto.lat.toFixed(5), estado.ponto.lon.toFixed(5),
                CONFIG.tipos[tipo], i + 1, o.nome, o.localidade || '',
                o.minutos === null ? '' : numero(o.minutos),
                o.km === null ? '' : numero(o.km),
                textoClasse(tipo, classificar(tipo, o.minutos)),
            ]);
        });
    });
    descarregarCsv('acesso-saude-local.csv', linhas);
});