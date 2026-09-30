'use strict';

// Converte uma grelha de valores em manchas com contorno curvo (marching squares + suavização).
//
// Porquê: uma grelha de quadrados diz "cada quadrícula está nesta classe", mas o acesso à saúde
// não muda em degraus de 5 km — muda de forma contínua. Os quadrados são um artefacto do método
// de cálculo, não uma propriedade do território. As manchas mostram a mesma informação sem
// fingir uma precisão em linha reta que não existe.
//
// Método:
//   1. marching squares sobre a grelha, para cada limiar, dando os segmentos da fronteira;
//   2. os segmentos são cosidos em anéis fechados (pela identidade da aresta, não por
//      comparação de vírgula flutuante, que não fecharia os anéis de forma fiável);
//   3. cada anel é suavizado com o algoritmo de Chaikin.

// ---------- marching squares ----------
//
// Em cada célula da grelha olha-se para os 4 cantos e marca-se quais estão "dentro"
// (valor <= limiar). As 16 combinações possíveis dizem por onde passa a fronteira.
// Os pontos ficam nas arestas, interpolados entre os valores dos cantos.
//
// Arestas identificadas por nome: "h_i_j" é a horizontal entre (i,j) e (i+1,j),
// "v_i_j" é a vertical entre (i,j) e (i,j+1). Assim dois segmentos vizinhos partilham
// exatamente a mesma chave e a costura dos anéis é exata.

const CASOS = {
    1: [['v', 0, 0, 'h', 0, 0]],
    2: [['h', 0, 0, 'v', 1, 0]],
    3: [['v', 0, 0, 'v', 1, 0]],
    4: [['v', 1, 0, 'h', 0, 1]],
    6: [['h', 0, 0, 'h', 0, 1]],
    7: [['v', 0, 0, 'h', 0, 1]],
    8: [['h', 0, 1, 'v', 0, 0]],
    9: [['h', 0, 1, 'h', 0, 0]],
    11: [['h', 0, 1, 'v', 1, 0]],
    12: [['v', 1, 0, 'v', 0, 0]],
    13: [['v', 1, 0, 'h', 0, 0]],
    14: [['h', 0, 0, 'v', 0, 0]],
};

// Um valor em falta (fora do território, ou sem rota) conta como "muito acima do limiar",
// para a mancha nunca invadir zonas sobre as quais não se sabe nada.
//
// A grelha é lida com uma moldura de uma célula à volta, sempre "fora". Sem essa moldura,
// uma mancha que toque a borda do retângulo analisado ficava com a fronteira aberta e o
// anel fechava-se por um atalho, inventando formas que não existem.
function valorDe(valores, largura, altura, i, j) {
    if (i < 0 || j < 0 || i >= largura || j >= altura) return Infinity;
    const v = valores[j * largura + i];
    return v === null || v === undefined || Number.isNaN(v) ? Infinity : v;
}

function interpolar(a, b, limiar) {
    if (!Number.isFinite(a) && !Number.isFinite(b)) return 0.5;
    if (!Number.isFinite(a)) return 0.02; // quase no canto conhecido
    if (!Number.isFinite(b)) return 0.98;
    const d = b - a;
    if (Math.abs(d) < 1e-9) return 0.5;
    return Math.min(0.98, Math.max(0.02, (limiar - a) / d));
}

// Devolve os anéis fechados da região onde valor <= limiar, em coordenadas de grelha.
function anelsDoLimiar(valores, largura, altura, limiar) {
    const segmentos = new Map(); // chave da aresta de partida -> { fim, pontos }
    const pontos = new Map();    // chave da aresta -> [x, y] em coordenadas de grelha

    const registar = (orient, i, j, x, y) => {
        const chave = `${orient}_${i}_${j}`;
        if (!pontos.has(chave)) pontos.set(chave, [x, y]);
        return chave;
    };

    for (let j = -1; j < altura; j++) {
        for (let i = -1; i < largura; i++) {
            const vBL = valorDe(valores, largura, altura, i, j);
            const vBR = valorDe(valores, largura, altura, i + 1, j);
            const vTR = valorDe(valores, largura, altura, i + 1, j + 1);
            const vTL = valorDe(valores, largura, altura, i, j + 1);

            let caso = (vBL <= limiar ? 1 : 0) | (vBR <= limiar ? 2 : 0)
                | (vTR <= limiar ? 4 : 0) | (vTL <= limiar ? 8 : 0);
            if (caso === 0 || caso === 15) continue;

            // pontos nas quatro arestas desta célula
            const eB = () => registar('h', i, j, i + interpolar(vBL, vBR, limiar), j);
            const eT = () => registar('h', i, j + 1, i + interpolar(vTL, vTR, limiar), j + 1);
            const eL = () => registar('v', i, j, i, j + interpolar(vBL, vTL, limiar));
            const eR = () => registar('v', i + 1, j, i + 1, j + interpolar(vBR, vTR, limiar));

            let lista = CASOS[caso];

            // casos ambíguos (5 e 10): dois cantos opostos dentro. O centro decide se a
            // região é uma só mancha ou duas pontas que se tocam.
            if (caso === 5 || caso === 10) {
                const finitos = [vBL, vBR, vTR, vTL].filter(Number.isFinite);
                const centro = finitos.length ? finitos.reduce((a, b) => a + b, 0) / finitos.length : Infinity;
                const ligado = centro <= limiar;
                if (caso === 5) {
                    lista = ligado
                        ? [['v', 0, 0, 'h', 0, 1], ['v', 1, 0, 'h', 0, 0]]
                        : [['v', 0, 0, 'h', 0, 0], ['v', 1, 0, 'h', 0, 1]];
                } else {
                    lista = ligado
                        ? [['h', 0, 0, 'v', 0, 0], ['h', 0, 1, 'v', 1, 0]]
                        : [['h', 0, 0, 'v', 1, 0], ['h', 0, 1, 'v', 0, 0]];
                }
            }

            const chaveDe = (orient, di, dj) => {
                if (orient === 'h') return dj === 0 ? eB() : eT();
                return di === 0 ? eL() : eR();
            };

            lista.forEach(([o1, i1, j1, o2, i2, j2]) => {
                const de = chaveDe(o1, i1, j1);
                const para = chaveDe(o2, i2, j2);
                if (de !== para) segmentos.set(de, para);
            });
        }
    }

    // costura: seguir cada cadeia de segmentos até fechar o anel
    const aneis = [];
    const porVisitar = new Set(segmentos.keys());
    while (porVisitar.size) {
        const inicio = porVisitar.values().next().value;
        const anel = [];
        let atual = inicio;
        while (atual !== undefined && porVisitar.has(atual)) {
            porVisitar.delete(atual);
            anel.push(pontos.get(atual));
            atual = segmentos.get(atual);
        }
        if (anel.length >= 3) aneis.push(anel);
    }
    return aneis;
}

// ---------- suavização ----------

// Chaikin: em cada passagem, cada segmento é substituído por dois pontos a 1/4 e 3/4.
// O anel fica com o dobro dos vértices e sem cantos; duas passagens chegam para
// as manchas deixarem de parecer desenhadas a régua.
function suavizar(anel, passagens = 2) {
    let atual = anel;
    for (let p = 0; p < passagens; p++) {
        if (atual.length < 4) break;
        const novo = [];
        for (let k = 0; k < atual.length; k++) {
            const [x1, y1] = atual[k];
            const [x2, y2] = atual[(k + 1) % atual.length];
            novo.push([x1 + (x2 - x1) * 0.25, y1 + (y2 - y1) * 0.25]);
            novo.push([x1 + (x2 - x1) * 0.75, y1 + (y2 - y1) * 0.75]);
        }
        atual = novo;
    }
    return atual;
}

// ---------- ligação ao mapa ----------

// Área aproximada de um anel em coordenadas de grelha (fórmula do shoelace),
// para deitar fora manchas de meia célula que só fazem sujidade.
function areaAnel(anel) {
    let soma = 0;
    for (let k = 0; k < anel.length; k++) {
        const [x1, y1] = anel[k];
        const [x2, y2] = anel[(k + 1) % anel.length];
        soma += x1 * y2 - x2 * y1;
    }
    return Math.abs(soma) / 2;
}

// Média de cada célula com as vizinhas. Usado na máscara do território, que é 0 ou 1:
// sem isto o marching squares só pode cortar a meio das células e a costa fica em degraus.
function suavizarCampo(valores, largura, altura, passagens = 2) {
    let atual = valores;
    for (let p = 0; p < passagens; p++) {
        const novo = new Array(largura * altura);
        for (let j = 0; j < altura; j++) {
            for (let i = 0; i < largura; i++) {
                let soma = 0;
                let n = 0;
                for (let dj = -1; dj <= 1; dj++) {
                    for (let di = -1; di <= 1; di++) {
                        const x = i + di;
                        const y = j + dj;
                        const v = (x < 0 || y < 0 || x >= largura || y >= altura) ? 0 : atual[y * largura + x];
                        soma += v;
                        n++;
                    }
                }
                novo[j * largura + i] = soma / n;
            }
        }
        atual = novo;
    }
    return atual;
}

// Converte os anéis de um limiar em coordenadas geográficas, prontos para o Leaflet.
// `raster` = { sul, oeste, dLat, dLon, largura, altura, valores }
function manchasDoLimiar(raster, limiar, opcoes = {}) {
    const areaMinima = opcoes.areaMinima ?? 0.4;
    const passagens = opcoes.passagens ?? 2;
    return anelsDoLimiar(raster.valores, raster.largura, raster.altura, limiar)
        .filter((anel) => areaAnel(anel) >= areaMinima)
        .map((anel) => suavizar(anel, passagens).map(([x, y]) => [
            raster.sul + (y + 0.5) * raster.dLat,
            raster.oeste + (x + 0.5) * raster.dLon,
        ]));
}

// Manchas do território analisado (a base sobre a qual as classes se pintam).
// A máscara 0/1 é suavizada primeiro, senão a costa sai aos degraus da grelha.
function manchasDoTerritorio(raster) {
    const campo = suavizarCampo(raster.terra.map((t) => (t ? 1 : 0)), raster.largura, raster.altura, 2);
    return manchasDoLimiar(
        { ...raster, valores: campo.map((v) => 1 - v) }, // 1 - v: "dentro" passa a ser valor baixo
        0.5,
        { passagens: 3, areaMinima: 0.4 },
    );
}

// Valor da grelha debaixo de um ponto geográfico (para o tooltip)
function valorNoPonto(raster, lat, lon) {
    const i = Math.floor((lon - raster.oeste) / raster.dLon);
    const j = Math.floor((lat - raster.sul) / raster.dLat);
    if (i < 0 || j < 0 || i >= raster.largura || j >= raster.altura) return undefined;
    if (raster.terra && !raster.terra[j * raster.largura + i]) return undefined;
    return raster.valores[j * raster.largura + i];
}
