// Testes de browser. Precisa do servidor falso e do servidor da app a correr:
//   ./testes/servidores.sh && node testes/browser.mjs

import { chromium } from 'playwright';
import { readFileSync, readdirSync, unlinkSync, existsSync } from 'fs';

const APP = 'http://127.0.0.1:8098';

// O container de testes não tem acesso ao cdnjs nem ao tile server: serve o Leaflet
// a partir do node_modules e responde aos tiles com uma imagem vazia.
const LEAFLET_JS = readFileSync('node_modules/leaflet/dist/leaflet.js', 'utf8');
const LEAFLET_CSS = readFileSync('node_modules/leaflet/dist/leaflet.css', 'utf8');
const PNG_VAZIO = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

let ok = 0;
const falhas = [];

function verificar(descricao, condicao, detalhe = '') {
    if (condicao) {
        ok++;
        console.log(`  ok   ${descricao}`);
    } else {
        falhas.push(descricao);
        console.log(`  FALHA ${descricao}${detalhe ? ` -> ${detalhe}` : ''}`);
    }
}

async function esperar(cond, limite = 30000, intervalo = 200) {
    const fim = Date.now() + limite;
    while (Date.now() < fim) {
        try {
            if (await cond()) return true;
        } catch { /* ainda não está pronto */ }
        await new Promise((r) => setTimeout(r, intervalo));
    }
    return false;
}

const navegador = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const erros = [];

async function novaPagina(opcoes = {}) {
    const ctx = await navegador.newContext({ viewport: { width: 1400, height: 1000 }, serviceWorkers: 'block', ...opcoes });
    const page = await ctx.newPage();
    await page.route('**/leaflet.js', (r) => r.fulfill({ contentType: 'application/javascript', body: LEAFLET_JS }));
    await page.route('**/leaflet.css', (r) => r.fulfill({ contentType: 'text/css', body: LEAFLET_CSS }));
    await page.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
    await page.route('**/tile.openstreetmap.org/**', (r) => r.fulfill({ contentType: 'image/png', body: PNG_VAZIO }));
    page.on('pageerror', (e) => erros.push(e.message));
    return { ctx, page };
}

const prontaComDados = (page) => esperar(async () => (await page.evaluate(() => estado.unidades.length)) > 0);
const DEPURAR = process.env.DEPURAR === '1';
const analisar = async (page, lat, lon, nome) => {
    await page.evaluate(([y, x, n]) => { escolherPonto(y, x, n); }, [lat, lon, nome]);
    const bem = await esperar(async () => (await page.locator('#resultados .resultado').count()) === 3);
    if (!bem && DEPURAR) {
        console.log('[depurar] erros de página:', JSON.stringify(erros.slice(-5)));
        console.log('[depurar] html:', (await page.locator('#resultados').innerHTML()).slice(0, 500));
    }
    return bem;
};

// ---------- 1. arranque e dados estáticos ----------
console.log('\n== 1. Arranque com o ficheiro de dados ==');
let { ctx, page } = await novaPagina();
const pedidos = [];
page.on('request', (r) => { if (r.url().includes('/api/')) pedidos.push(r.url()); });
await page.goto(APP, { waitUntil: 'domcontentloaded' });

verificar('carrega as unidades num pedido só', await prontaComDados(page));
const nUnidades = await page.evaluate(() => estado.unidades.length);
verificar('desenha todas as unidades no mapa', (await page.evaluate(() => marcadores.size)) === nUnidades, `${nUnidades}`);
verificar('um único pedido a api/unidades.php', pedidos.filter((u) => u.includes('unidades.php')).length === 1,
    pedidos.filter((u) => u.includes('unidades.php')).join(' '));
verificar('nenhum pedido de blocos (foi simplificado)', !pedidos.some((u) => u.includes('blocos=')));
verificar('os cinco separadores existem', (await page.locator('[role="tab"]').count()) === 5);
verificar('mostra a data dos dados', /\d{2}\/\d{2}\/\d{4}/.test(await page.textContent('#info-dados')));

// ícone do separador do navegador
const icone = await page.getAttribute('link[rel="icon"]', 'href');
verificar('declara um ícone para o separador', icone === 'assets/icone.svg', String(icone));
const respIcone = await page.request.get(APP + '/' + icone);
verificar('o ícone existe e é SVG', respIcone.ok() && (await respIcone.text()).includes('<svg'), `HTTP ${respIcone.status()}`);

// urgência confirmada (cheia) vs. por confirmar (só contorno)
const estilos = await page.evaluate(() => {
    const cheia = { total: 0, contorno: 0 };
    estado.unidades.filter((u) => u.tipos.includes('urgencia')).forEach((u) => {
        cheia.total++;
        if (u.urgencia !== 'sim') cheia.contorno++;
    });
    const alguma = estado.unidades.find((u) => u.tipos.includes('urgencia') && u.urgencia !== 'sim');
    return { ...cheia, estiloContorno: alguma ? estiloUnidade(alguma, 'urgencia').fillColor : null };
});
verificar('as urgências por confirmar ficam com o interior branco',
    estilos.contorno === 0 || estilos.estiloContorno === '#FFFFFF', JSON.stringify(estilos));
verificar('a legenda explica cheio vs. contorno', (await page.locator('.legenda-mapa li').count()) === 4);

// ---------- 2. análise, percurso passo a passo ----------
console.log('\n== 2. Análise e percurso ==');
verificar('analisa um ponto', await analisar(page, 41.15, -8.61, 'Porto'));
const tempos = await page.locator('#resultados .tempo strong').allTextContents();
verificar('os três tipos têm tempo', tempos.length === 3 && tempos.every((t) => /^\d+$/.test(t)), tempos.join(' | '));

await page.locator('#resultados .acoes button').first().click();
verificar('desenha o percurso', await esperar(async () => page.evaluate(() => !!camadas.rota)));
verificar('mostra instruções passo a passo', await esperar(async () => (await page.locator('.passos li').count()) >= 3),
    `${await page.locator('.passos li').count()} passos`);
const primeiroPasso = await page.locator('.passos li .passo-texto').first().textContent();
verificar('instruções em português', /Sair|Virar|Continuar|rotunda/i.test(primeiroPasso), primeiroPasso);
verificar('mostra a distância de cada passo', (await page.locator('.passos .passo-dist').count()) >= 2);

await page.locator('#btn-limpar-rota').click();
verificar('remove o percurso', !(await page.evaluate(() => !!camadas.rota)));

// ---------- 3. pesquisa de unidades por nome ----------
console.log('\n== 3. Pesquisa por nome de unidade ==');
await page.fill('#morada', 'Maternidade');
verificar('sugere unidades enquanto se escreve', await esperar(async () => (await page.locator('#sugestoes button').count()) > 0),
    `${await page.locator('#sugestoes button').count()} sugestões`);
const semRede = pedidos.length;
await page.waitForTimeout(400);
verificar('a sugestão local não faz pedidos ao servidor', pedidos.length === semRede);
await page.locator('#sugestoes button').first().click();
verificar('clicar na sugestão analisa a unidade', await esperar(async () => (await page.locator('#resultados .resultado').count()) === 3));

// ---------- 4. moradas guardadas ----------
console.log('\n== 4. Moradas guardadas ==');
await analisar(page, 41.15, -8.61, 'Rua de Teste, Porto');
page.once('dialog', (d) => d.accept('Casa'));
await page.click('#btn-guardar');
verificar('guarda a morada', await esperar(async () => (await page.locator('.lista-moradas > li').count()) === 1));
verificar('mostra os tempos de cada tipo', await esperar(async () => (await page.locator('.morada-tempos li').count()) === 3));
verificar('guarda no dispositivo', (await page.evaluate(() => JSON.parse(localStorage.getItem('mapa-saude:guardadas')).length)) === 1);

// sobrevive a recarregar a página
await page.reload({ waitUntil: 'domcontentloaded' });
await prontaComDados(page);
await page.click('[data-painel="moradas"]');
verificar('a morada sobrevive a recarregar', await esperar(async () => (await page.locator('.lista-moradas > li').count()) === 1));
await page.locator('[data-remover]').first().click();
verificar('remove a morada', (await page.locator('.lista-moradas > li').count()) === 0);

// ---------- 5. comparar moradas ----------
console.log('\n== 5. Comparar moradas ==');
for (const [lat, lon, nome] of [[41.15, -8.61, 'Porto'], [37.02, -7.93, 'Faro']]) {
    await analisar(page, lat, lon, nome);
    await esperar(async () => !(await page.locator('#btn-comparar-juntar').isDisabled()));
    await page.click('#btn-comparar-juntar');
    await page.waitForTimeout(500);
}
verificar('duas moradas na tabela', await esperar(async () => (await page.locator('.tabela-comparacao tbody tr').count()) === 2));
verificar('mostra minutos nas 6 células', await esperar(async () => (await page.locator('.tabela-comparacao tbody td strong').count()) === 6));
verificar('destaca a melhor por tipo', (await page.locator('.tabela-comparacao .vence').count()) > 0);
verificar('marca A e B no mapa', (await page.locator('.marca-comparacao').count()) === 2);

const hash = await page.evaluate(() => location.hash);
verificar('guarda a comparação no endereço', /^#c=[-\d.]+,[-\d.]+,/.test(hash), hash);
const { ctx: ctx2, page: page2 } = await novaPagina();
await page2.goto(APP + hash, { waitUntil: 'domcontentloaded' });
verificar('o link reconstrói a comparação', await esperar(async () => (await page2.locator('.tabela-comparacao tbody tr').count()) === 2, 40000));
await ctx2.close();

// ---------- 6. zonas: grelha, planeamento, influência ----------
console.log('\n== 6. Zonas ==');
await page.evaluate(() => { mapa.setView([41.15, -8.61], 10); });
await page.click('[data-painel="zonas"]');
await page.click('#btn-zona');
verificar('calcula a grelha da zona', await esperar(async () => (await page.locator('#resumo-grelha .destaque').count()) === 1, 60000),
    (await page.textContent('#resumo-grelha')).slice(0, 80));
const manchas = await page.evaluate(() => {
    if (!camadas.grelha) return null;
    const poligonos = camadas.grelha.camada.getLayers();
    // cada polígono é uma lista de anéis; conta-se o maior anel de todos
    const maior = Math.max(...poligonos.map((p) => Math.max(...p.getLatLngs().map((a) => a.length))));
    return { poligonos: poligonos.length, maiorAnel: maior };
});
verificar('desenha manchas no mapa', manchas && manchas.poligonos >= 2, JSON.stringify(manchas));
// Um anel quadriculado teria 4 pontos por célula; a suavização multiplica-os.
verificar('as manchas são curvas, não quadrículas', manchas && manchas.maiorAnel > 40, `maior anel: ${manchas && manchas.maiorAnel} pontos`);
verificar('mostra a legenda das classes', (await page.locator('#resumo-grelha .legenda li').count()) === 4);

// a análise de zona traz população: o resumo alterna entre pessoas e área, e o mapa
// pode passar a mostrar os círculos de gente por cima das manchas
const temPopZona = (await page.locator('#resumo-grelha .alternador-mapa').count()) === 1;
verificar('a zona traz população', temPopZona, (await page.textContent('#resumo-grelha')).slice(0, 100));
if (temPopZona) {
    verificar('conta pessoas por omissão', /pessoas desta zona/.test(await page.textContent('#resumo-grelha')));
    await page.locator('#resumo-grelha .alternador button[data-medida="area"]').click();
    verificar('alterna para área', /da área analisada/.test(await page.textContent('#resumo-grelha')));
    await page.locator('#resumo-grelha .alternador button[data-mapa="pessoas"]').click();
    verificar('desenha os círculos de população',
        await esperar(async () => page.evaluate(() => !!camadas.pessoas)));
    await page.locator('#resumo-grelha .alternador button[data-mapa="critico"]').click();
    verificar('o modo crítico explica-se', /acima de \d+ min/.test(await page.textContent('#legenda-mapa-pessoas')));
    await page.locator('#resumo-grelha .alternador button[data-mapa="acesso"]').click();
    verificar('voltar a acesso tira os círculos', !(await page.evaluate(() => !!camadas.pessoas)));
}

await page.click('#btn-planear');
verificar('sugere onde falta uma unidade', await esperar(async () => (await page.locator('.tabela-antes-depois').count()) === 1, 120000),
    (await page.textContent('#resumo-planeamento')).slice(0, 120));
const textoPlano = await page.textContent('#resumo-planeamento');
verificar('mostra antes e depois', /Agora/.test(textoPlano) && /Com a nova/.test(textoPlano));
verificar('mostra os minutos poupados', /Poupa [\d,]+ min/.test(textoPlano), textoPlano.match(/Poupa[^.]*/)?.[0] || '');
verificar('marca o local proposto no mapa', (await page.locator('.marca-proposta').count()) === 1);
await page.locator('#btn-limpar-plano').click();

// influência: abre o popup de uma urgência e pede a área que serve
await page.evaluate(() => {
    // qualquer urgência serve; centra-se o mapa nela antes de pedir a área
    const u = estado.unidades.find((x) => x.tipos.includes('urgencia')
        && x.lat > 41.0 && x.lat < 41.4 && x.lon > -8.8 && x.lon < -8.3)
        || estado.unidades.find((x) => x.tipos.includes('urgencia'));
    mapa.setView([u.lat, u.lon], 10);
    verInfluencia(u);
});
verificar('calcula a área de influência', await esperar(async () => (await page.locator('#resumo-influencia .destaque').count()) === 1, 60000),
    (await page.textContent('#resumo-influencia')).slice(0, 120));
const textoInf = await page.textContent('#resumo-influencia');
verificar('diz a área em km²', /km²/.test(textoInf) || /nenhum sítio/i.test(textoInf), textoInf.slice(0, 100));

// mapa nacional pré-calculado (data/grelha_<tipo>.json) — fica para o fim da secção
// porque enquadra o país inteiro e desfaz o enquadramento dos testes anteriores.
await page.click('#btn-nacional');
verificar('desenha o mapa nacional pré-calculado', await esperar(async () => page.evaluate(
    () => !!camadas.grelha && camadas.grelha.origem === 'nacional' && camadas.grelha.rasters.length >= 2), 30000),
    (await page.textContent('#resumo-grelha')).slice(0, 100));
verificar('junta as três regiões num só conjunto de manchas', await page.evaluate(
    () => camadas.grelha && camadas.grelha.camada.getLayers().length >= 4));

// ---------- 7. ficha de emergência ----------
console.log('\n== 7. Ficha de emergência ==');
await page.click('[data-painel="local"]');
await analisar(page, 41.15, -8.61, 'Rua de Teste, Porto');
await page.evaluate(() => { construirFicha(); });
const ficha = await page.textContent('#ficha');
verificar('a ficha tem a morada', ficha.includes('Rua de Teste, Porto'));
verificar('a ficha tem o 112', ficha.includes('112'));
verificar('a ficha tem o SNS 24', ficha.includes('808 24 24 24'));
verificar('a ficha tem coordenadas para o GPS', /41\.15\d*, -8\.61\d*/.test(ficha), ficha.slice(0, 120));
verificar('a ficha lista os três tipos', (await page.locator('#ficha .ficha-tipo').count()) === 3);
verificar('a ficha está escondida no ecrã', await page.evaluate(() => getComputedStyle(document.getElementById('ficha')).display) === 'none');

// ---------- 8. exportação CSV ----------
console.log('\n== 8. Exportar CSV ==');
for (const [botao, nome, minLinhas] of [
    ['#btn-csv-local', 'acesso-saude-local.csv', 4],
    ['#btn-csv-unidades', 'unidades-saude-portugal.csv', 10],
]) {
    if (botao === '#btn-csv-unidades') await page.click('[data-painel="dados"]');
    const [download] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click(botao)]);
    const caminho = await download.path();
    const conteudo = readFileSync(caminho, 'utf8');
    verificar(`descarrega ${nome}`, download.suggestedFilename() === nome, download.suggestedFilename());
    verificar(`${nome} tem cabeçalho e linhas`, conteudo.split('\n').length > minLinhas, `${conteudo.split('\n').length} linhas`);
    verificar(`${nome} usa ponto e vírgula e BOM (Excel PT)`, conteudo.startsWith('﻿') && conteudo.includes(';'));
}

// ---------- 8b. retrato do país ----------
console.log('\n== 8b. Retrato do país ==');
await page.click('[data-painel="dados"]');
verificar('mostra a percentagem do país em deserto', await esperar(
    async () => (await page.locator('#retrato-desertos .barras').first().locator('li').count()) === 3, 20000),
    (await page.textContent('#retrato-desertos')).slice(0, 120));
const textoRetrato = await page.textContent('#retrato-desertos');
verificar('mostra também a população em deserto',
    /População em deserto/.test(textoRetrato) && (await page.locator('#retrato-desertos .barras').count()) === 2,
    textoRetrato.slice(0, 160));


// ---------- 8c. separador Pessoas ----------
console.log('\n== 8c. Pessoas ==');
await page.click('[data-painel="pessoas"]');
verificar('o separador Pessoas abre', !(await page.locator('#painel-pessoas').isHidden()));

await page.click('#btn-retrato-pessoas');
const temRetrato = await esperar(async () => (await page.locator('.cartao-pessoas').count()) === 3, 30000);
verificar('mostra um cartão por tipo de cuidado', temRetrato,
    `${await page.locator('.cartao-pessoas').count()} cartões`);

if (temRetrato) {
    const textoPessoas = await page.textContent('#resumo-pessoas');
    verificar('fala em pessoas, não em área', /pessoas vivem a mais de/.test(textoPessoas), textoPessoas.slice(0, 120));
    verificar('tem uma tabela por classe de acesso', (await page.locator('.tabela-pessoas tbody tr').count()) >= 6);
    verificar('oferece uma frase pronta a citar', (await page.locator('.btn-copiar').count()) === 3);
    verificar('a frase indica a fonte', /Eurostat|Census 2021/.test(await page.textContent('.citar .frase')));
}

await page.click('#btn-piores');
const temPiores = await esperar(async () => (await page.locator('.tabela-piores tbody tr').count()) > 0, 30000);
verificar('lista as zonas onde vive mais gente longe', temPiores,
    `${await page.locator('.tabela-piores tbody tr').count()} linhas`);
if (temPiores) {
    verificar('cada zona tem botão para ver no mapa', (await page.locator('.btn-ir-zona').count()) > 0);
    verificar('mostra o peso do problema', /Peso/.test(await page.textContent('.tabela-piores thead')));

    // ordenar por gravidade dá uma lista diferente de ordenar por população
    const porPessoas = await page.locator('.tabela-piores tbody tr td:nth-child(2)').allTextContents();
    await page.locator('.alternador button[data-ordem="criticidade"]').click();
    await esperar(async () => (await page.textContent('#resumo-piores')).includes('gravidade'), 20000);
    const porGravidade = await page.locator('.tabela-piores tbody tr td:nth-child(2)').allTextContents();
    verificar('ordenar por gravidade muda a lista', porPessoas.join() !== porGravidade.join(),
        `${porPessoas.slice(0, 3).join('|')} -> ${porGravidade.slice(0, 3).join('|')}`);

    await page.click('#btn-marcar-criticas');
    verificar('marca as zonas críticas no mapa',
        await esperar(async () => page.evaluate(() => !!camadas.criticas)));
    verificar('as marcas são numeradas', (await page.locator('.marca-critica').count()) > 0);

    await page.click('#btn-mapa-critico');
    verificar('desenha o mapa nacional de criticidade',
        await esperar(async () => page.evaluate(() => !!camadas.pessoas), 40000),
        (await page.textContent('#resumo-critico')).slice(0, 120));
    verificar('explica o que os círculos são', /tamanho é a população/.test(await page.textContent('#resumo-critico')));

    await page.click('#btn-limpar-criticas');
    verificar('limpa o mapa', !(await page.evaluate(() => !!camadas.criticas || !!camadas.pessoas)));

    await page.locator('.btn-ir-zona').first().click();
    verificar('clicar numa zona analisa esse ponto',
        await esperar(async () => page.evaluate(() => !!estado.ponto)));
}


// ---------- 8d. onde construir (otimização) ----------
console.log('\n== 8d. Onde construir ==');
await page.click('[data-painel="pessoas"]');
const temOtimizador = (await page.locator('#btn-otimizar').count()) === 1;
verificar('o otimizador está na página', temOtimizador);

if (temOtimizador) {
    await page.fill('#otim-quantas', '2');
    await page.click('#btn-otimizar');
    const correu = await esperar(async () => (await page.locator('#resumo-otimizacao .destaque').count()) === 1, 120000);
    verificar('encontra locais para unidades novas', correu,
        (await page.textContent('#resumo-otimizacao')).slice(0, 120));

    if (correu) {
        const texto = await page.textContent('#resumo-otimizacao');
        verificar('propõe o número de locais pedido',
            (await page.locator('#resumo-otimizacao tbody tr').count()) >= 2,
            `${await page.locator('#resumo-otimizacao tbody tr').count()} linhas`);
        verificar('marca as propostas no mapa',
            await esperar(async () => page.evaluate(() => !!camadas.propostas)));
        verificar('as marcas são numeradas', (await page.locator('.marca-proposta').count()) === 2);
        verificar('diz quantas pessoas saem do deserto', /deserto de saúde/.test(texto));
        verificar('mostra a margem de erro do modelo', /erro médio de/.test(texto), texto.slice(0, 200));
        verificar('mostra quantas configurações avaliou', /configurações avaliadas/.test(texto));
        verificar('oferece confirmação com o OSRM', (await page.locator('#btn-confirmar-osrm').count()) === 1);

        // a tabela antes/depois nunca pode mostrar uma piora
        const numeros = await page.locator('.tabela-antes-depois .melhor').count();
        verificar('a comparação antes/depois marca melhorias', numeros >= 1, `${numeros} células melhores`);

        await page.click('#btn-limpar-propostas');
        verificar('remove as propostas do mapa', !(await page.evaluate(() => !!camadas.propostas)));
    }
}

// ---------- 9. tema escuro ----------
console.log('\n== 9. Tema escuro ==');
await page.click('#btn-tema');
verificar('muda para escuro', (await page.evaluate(() => document.documentElement.dataset.tema)) === 'escuro');
const fundoEscuro = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
verificar('o fundo escurece', fundoEscuro !== 'rgb(238, 242, 243)', fundoEscuro);
verificar('o mapa acompanha o tema', (await page.locator('.tiles-escuro').count()) > 0);
await page.reload({ waitUntil: 'domcontentloaded' });
verificar('o tema fica guardado', (await page.evaluate(() => document.documentElement.dataset.tema)) === 'escuro');
await page.click('#btn-tema');

// ---------- 10. offline ----------
console.log('\n== 10. Sem ligação ==');
await prontaComDados(page);
await page.evaluate(() => { estado.offline = true; });
await analisar(page, 41.2, -8.5, 'Sítio sem rede').catch(() => {});
verificar('mostra resultados sem ligação', await esperar(async () => (await page.locator('#resultados .resultado').count()) === 3));
const textoOffline = await page.textContent('#resultados');
verificar('avisa que são distâncias em linha reta', /linha reta/.test(textoOffline), textoOffline.slice(0, 120));
verificar('mostra km em vez de minutos', /km/.test(textoOffline));

// O service worker é testado num contexto próprio: quando controla a página, passa por cima
// das rotas do Playwright e o container não tem acesso ao cdnjs.
const ctxSw = await navegador.newContext({ viewport: { width: 1200, height: 800 } });
const pageSw = await ctxSw.newPage();
await pageSw.route('**/tile.openstreetmap.org/**', (r) => r.fulfill({ contentType: 'image/png', body: PNG_VAZIO }));
await pageSw.goto(APP, { waitUntil: 'domcontentloaded' });
const temSw = await esperar(async () => pageSw.evaluate(() => navigator.serviceWorker.getRegistrations().then((r) => r.length > 0)), 20000);
verificar('regista o service worker', temSw);
const guardouDados = await esperar(async () => pageSw.evaluate(async () => {
    const nomes = await caches.keys();
    if (!nomes.length) return false;
    const cache = await caches.open(nomes[0]);
    const guardadas = await cache.keys();
    return guardadas.some((r) => r.url.includes('api/unidades.php'));
}), 20000);
verificar('guarda os dados das unidades para uso offline', guardouDados);
await ctxSw.close();

// ---------- 11. telemóvel e erros ----------
console.log('\n== 11. Telemóvel ==');
await ctx.close();
({ ctx, page } = await novaPagina({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }));
await page.goto(APP, { waitUntil: 'domcontentloaded' });
verificar('carrega em ecrã pequeno', await prontaComDados(page));
verificar('sem scroll horizontal', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
verificar('os separadores cabem', await page.evaluate(() => {
    const n = document.querySelector('.separadores');
    return n.scrollWidth <= n.clientWidth + 2;
}));
await ctx.close();

verificar('sem erros de JavaScript em toda a sessão', erros.length === 0, erros.slice(0, 3).join(' | '));

await navegador.close();

console.log('\n' + '='.repeat(52));
console.log(`${ok} testes passaram`);
if (falhas.length) {
    console.log(`${falhas.length} FALHARAM:`);
    falhas.forEach((f) => console.log(`  - ${f}`));
    process.exit(1);
}
console.log('Tudo ok.');
