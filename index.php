<?php
require_once __DIR__ . '/config.php';

// só o que o JavaScript precisa de saber
$configJs = [
    'tipos' => cfg('tipos'),
    'limiares' => cfg('limiares'),
    'semTag' => cfg('urgencia_inclui_sem_tag'),
    'maxCelulas' => cfg('max_celulas_zona'),
    'maxComparacao' => cfg('max_comparacao'),
];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quanto tempo até ao hospital? Mapa de acesso à saúde em Portugal</title>
    <meta name="description" content="Tempo de viagem até à urgência, centro de saúde e maternidade mais próximos, a partir de qualquer morada em Portugal. Compara moradas, encontra desertos de saúde e funciona sem ligação.">
    <meta name="theme-color" content="#1C2B36">
    <link rel="icon" type="image/svg+xml" href="assets/icone.svg">
    <link rel="apple-touch-icon" href="assets/icone.svg">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        // aplica o tema antes de pintar, para não haver um clarão ao carregar
        try {
            var t = localStorage.getItem('mapa-saude:tema');
            if (t) document.documentElement.dataset.tema = t;
        } catch (e) {}
    </script>
</head>
<body>
<div class="app">
    <aside class="painel">
        <header class="cabecalho">
            <div class="cabecalho-topo">
                <h1>Quanto tempo até ao hospital?</h1>
                <button type="button" id="btn-tema" class="icone" aria-label="Mudar entre tema claro e escuro" title="Tema claro/escuro">◐</button>
            </div>
            <p>Tempo de viagem de carro, em Portugal, até à urgência, ao centro de saúde e à maternidade mais próximos.</p>
        </header>

        <form id="form-pesquisa" class="pesquisa" autocomplete="off" role="search">
            <label for="morada">Morada, localidade ou nome de uma unidade</label>
            <div class="linha-pesquisa">
                <input id="morada" type="search" placeholder="Ex.: Rua do Almada, Porto" minlength="2" required
                       aria-describedby="dica-pesquisa" aria-autocomplete="list" aria-controls="sugestoes">
                <button type="submit">Procurar</button>
            </div>
            <p class="dica" id="dica-pesquisa">
                Também podes clicar no mapa ou
                <button type="button" id="btn-localizacao" class="link">usar a tua localização</button>.
            </p>
            <ul id="sugestoes" class="sugestoes" role="listbox" aria-label="Resultados da pesquisa"></ul>
        </form>

        <nav class="separadores" role="tablist" aria-label="Secções">
            <button type="button" role="tab" id="sep-local" aria-controls="painel-local" aria-selected="true" data-painel="local">Local</button>
            <button type="button" role="tab" id="sep-moradas" aria-controls="painel-moradas" aria-selected="false" data-painel="moradas">Moradas</button>
            <button type="button" role="tab" id="sep-zonas" aria-controls="painel-zonas" aria-selected="false" data-painel="zonas">Zonas</button>
            <button type="button" role="tab" id="sep-pessoas" aria-controls="painel-pessoas" aria-selected="false" data-painel="pessoas">Pessoas</button>
            <button type="button" role="tab" id="sep-dados" aria-controls="painel-dados" aria-selected="false" data-painel="dados">Dados</button>
        </nav>

        <!-- ---------- Local ---------- -->
        <div class="folha" id="painel-local" role="tabpanel" aria-labelledby="sep-local">
            <section id="resultados" class="resultados" aria-live="polite"></section>

            <section class="bloco" id="bloco-acoes-local" hidden>
                <h2>Guardar e partilhar</h2>
                <div class="botoes">
                    <button type="button" id="btn-guardar" class="secundario">Guardar esta morada</button>
                    <button type="button" id="btn-comparar-juntar" class="secundario">Juntar à comparação</button>
                </div>
                <div class="botoes">
                    <button type="button" id="btn-ficha" class="link">Ficha de emergência para imprimir</button>
                    <button type="button" id="btn-csv-local" class="link">Exportar CSV</button>
                </div>
            </section>

            <section class="bloco" id="bloco-percurso" hidden>
                <h2>Percurso</h2>
                <div id="percurso"></div>
            </section>
        </div>

        <!-- ---------- Moradas ---------- -->
        <div class="folha" id="painel-moradas" role="tabpanel" aria-labelledby="sep-moradas" hidden>
            <section class="bloco sem-topo">
                <h2>As minhas moradas</h2>
                <p>Guarda os sítios que te interessam — casa, trabalho, a casa dos teus pais — e vê o acesso de todos de uma vez. Ficam só neste dispositivo.</p>
                <div id="guardadas" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Comparar moradas</h2>
                <p>A escolher casa, escola ou lar? Junta até <?= (int) cfg('max_comparacao') ?> moradas e vê lado a lado a que distância cada uma fica dos cuidados de saúde.</p>
                <div id="comparacao" aria-live="polite"></div>
            </section>
        </div>

        <!-- ---------- Zonas ---------- -->
        <div class="folha" id="painel-zonas" role="tabpanel" aria-labelledby="sep-zonas" hidden>
            <section class="bloco sem-topo">
                <h2>Desertos de saúde</h2>
                <p>Calcula o tempo até à unidade mais próxima em toda a zona visível e desenha as áreas por classe de acesso.</p>
                <label for="tipo-grelha">Tipo de cuidado</label>
                <select id="tipo-grelha"></select>
                <div class="botoes">
                    <button type="button" id="btn-zona">Analisar zona visível</button>
                    <button type="button" id="btn-nacional" class="secundario">Mapa nacional</button>
                </div>
                <div id="resumo-grelha" class="resumo-grelha" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Onde falta uma unidade?</h2>
                <p>Procura, na zona visível, o sítio onde uma unidade nova daria mais jeito, e mostra quanto melhoraria o acesso de toda a zona.</p>
                <div class="botoes">
                    <button type="button" id="btn-planear">Procurar o melhor local</button>
                </div>
                <div id="resumo-planeamento" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Área de influência</h2>
                <p>Clica numa unidade no mapa e escolhe «Ver área que serve» para veres o território de que ela é, na prática, a mais próxima.</p>
                <div id="resumo-influencia" aria-live="polite"></div>
            </section>
        </div>

        <!-- ---------- Pessoas ---------- -->
        <div class="folha" id="painel-pessoas" role="tabpanel" aria-labelledby="sep-pessoas" hidden>
            <section class="bloco sem-topo">
                <h2>Quem fica de fora</h2>
                <p>O mesmo mapa, lido em pessoas e não em área. Quarenta por cento do território pode ficar longe e isso quase não querer dizer nada — a serra não tem ninguém. O que conta é quanta gente fica longe, e quem.</p>
                <div class="botoes">
                    <button type="button" id="btn-retrato-pessoas">Ver retrato nacional</button>
                </div>
                <div id="resumo-pessoas" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Onde vive mais gente longe</h2>
                <p>Não é o sítio mais remoto do país — esse é sempre um monte sem ninguém. São as manchas onde o problema afeta mais pessoas, que é o que uma câmara ou uma ULS precisa de saber.</p>
                <label for="tipo-pessoas">Tipo de cuidado</label>
                <select id="tipo-pessoas"></select>
                <div class="botoes">
                    <button type="button" id="btn-piores">Listar as zonas</button>
                </div>
                <div id="resumo-piores" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Onde construir</h2>
                <p>Dadas N unidades novas, onde é que as devíamos pôr? A resposta é procurada por otimização sobre o país inteiro, e não por tentativa e erro: o tempo de viagem é estimado por um modelo treinado nos tempos que o OSRM já calculou, o que permite avaliar dezenas de milhares de configurações em vez de três.</p>
                <div class="linha-campos">
                    <label for="otim-quantas">Unidades novas</label>
                    <input type="number" id="otim-quantas" min="1" max="8" value="3" inputmode="numeric">
                </div>
                <fieldset class="escolha">
                    <legend>O que maximizar</legend>
                    <label class="opcao"><input type="radio" name="otim-objetivo" value="tempo" checked>
                        Minutos poupados no total <span class="sub">(beneficia muita gente um pouco)</span></label>
                    <label class="opcao"><input type="radio" name="otim-objetivo" value="deserto">
                        Pessoas tiradas do deserto <span class="sub">(concentra em quem está pior)</span></label>
                </fieldset>
                <div class="botoes">
                    <button type="button" id="btn-otimizar">Procurar os melhores locais</button>
                </div>
                <div id="resumo-otimizacao" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Mapa de criticidade</h2>
                <p>As manchas de acesso respondem a «onde é longe». Não respondem a «onde é que isso afeta muita gente» — e é essa que decide onde vale a pena construir. Este mapa junta as duas: manchas por baixo, população por cima.</p>
                <div id="resumo-critico" aria-live="polite"></div>
            </section>
        </div>

        <!-- ---------- Dados ---------- -->
        <div class="folha" id="painel-dados" role="tabpanel" aria-labelledby="sep-dados" hidden>
            <section class="bloco sem-topo">
                <h2>Retrato do país</h2>
                <div id="retrato" aria-live="polite"></div>
            </section>

            <section class="bloco">
                <h2>Como ler o mapa</h2>
                <ul class="legenda-mapa">
                    <li><span class="amostra cheia" style="--cor:#1F4E9C"></span>
                        <span><strong>Urgência confirmada</strong> — tem <code>emergency=yes</code> no OpenStreetMap.</span></li>
                    <li><span class="amostra vazia" style="--cor:#1F4E9C"></span>
                        <span><strong>Urgência por confirmar</strong> — é um hospital geral, mas ninguém preencheu a etiqueta da urgência. Pode não ter urgência, ou ter só em certos horários.</span></li>
                    <li><span class="amostra cheia" style="--cor:#5E7A8A"></span><span>Centro de saúde, USF ou UCSP.</span></li>
                    <li><span class="amostra cheia" style="--cor:#7A4CA0"></span><span>Maternidade.</span></li>
                </ul>
                <p class="info">As manchas de cor mostram o tempo até à unidade mais próxima. A fronteira entre classes é interpolada entre os pontos calculados — é uma estimativa contínua, não um limite exato.</p>
            </section>

            <section class="bloco">
                <h2>Definições</h2>
                <label class="opcao">
                    <input type="checkbox" id="chk-sem-tag">
                    Contar hospitais sem informação de urgência no OpenStreetMap
                </label>
                <p class="info">É esta opção que decide se os pontos de contorno branco contam. Ligada é mais realista (a maioria dos hospitais gerais tem urgência); desligada é mais rigorosa (só conta o que está confirmado).</p>
            </section>

            <section class="bloco">
                <h2>Exportar</h2>
                <p>Para quem precisa dos números noutro lado: folha de cálculo, relatório ou SIG.</p>
                <div class="botoes">
                    <button type="button" id="btn-csv-unidades" class="secundario">Unidades (CSV)</button>
                    <button type="button" id="btn-csv-grelha" class="secundario">Pontos da zona (CSV)</button>
                </div>
            </section>

            <section class="bloco">
                <h2>Sobre os dados</h2>
                <div id="info-dados" class="info">A carregar…</div>
                <p class="info">Tempos estimados pela rede de estradas com o OSRM, sem trânsito nem tempo de estacionamento. Os limiares de «bom acesso» e «deserto» são assumidos, não oficiais.</p>
            </section>
        </div>

        <footer class="notas">
            <p>Unidades de saúde, estradas e mapa: © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">contribuidores do OpenStreetMap</a>, licença ODbL.</p>
            <p id="estado-ligacao" class="estado-ligacao" hidden>Sem ligação: a usar os dados guardados no dispositivo.</p>
        </footer>
    </aside>

    <main id="mapa" class="mapa" aria-label="Mapa de Portugal com unidades de saúde"></main>
</div>

<!-- Ficha de emergência: escondida no ecrã, é o único bloco que sai na impressão -->
<div id="ficha" class="ficha" hidden aria-hidden="true"></div>

<script>window.APP_CONFIG = <?= json_encode($configJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script src="assets/js/nucleo.js"></script>
<script src="assets/js/contornos.js"></script>
<script src="assets/js/mapa.js"></script>
<script src="assets/js/local.js"></script>
<script src="assets/js/moradas.js"></script>
<script src="assets/js/zonas.js"></script>
<script src="assets/js/pessoas.js"></script>
<script src="assets/js/dados.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
