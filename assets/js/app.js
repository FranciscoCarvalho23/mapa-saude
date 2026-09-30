'use strict';

// Arranque: carrega as unidades, repõe o que estava guardado e liga o modo offline.

async function carregarUnidades() {
    try {
        const dados = await api('api/unidades.php');
        estado.unidades = dados.unidades;
        estado.porId = new Map(dados.unidades.map((u) => [u.id, u]));
        estado.infoDados = { atualizado_em: dados.atualizado_em, total: dados.total, licenca: dados.licenca };

        // guarda uma cópia local: é o que permite a página funcionar sem ligação
        guardarLocal('unidades', { atualizado_em: dados.atualizado_em, total: dados.total, licenca: dados.licenca, unidades: dados.unidades });

        desenharUnidades(estado.unidades);
        return true;
    } catch (erro) {
        // sem servidor (ou sem rede): tenta a cópia guardada no dispositivo
        const copia = lerLocal('unidades', null);
        if (copia && copia.unidades && copia.unidades.length) {
            estado.unidades = copia.unidades;
            estado.porId = new Map(copia.unidades.map((u) => [u.id, u]));
            estado.infoDados = { atualizado_em: copia.atualizado_em, total: copia.total, licenca: copia.licenca };
            estado.offline = true;
            $('estado-ligacao').hidden = false;
            desenharUnidades(estado.unidades);
            return true;
        }
        $('info-dados').innerHTML = `<span class="erro">${esc(erro.message)}</span>`;
        $('resultados').innerHTML = `<p class="erro">Não foi possível carregar as unidades de saúde. ${esc(erro.message)}</p>`;
        return false;
    }
}

// Se o browser já tinha autorização de localização, centra o mapa na pessoa.
// Sem autorização não pergunta nada: mostra o país inteiro.
async function centrarNaPessoa() {
    try {
        if (!navigator.permissions || !navigator.geolocation) return;
        const permissao = await navigator.permissions.query({ name: 'geolocation' });
        if (permissao.state !== 'granted') return;
        await new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    mapa.setView([pos.coords.latitude, pos.coords.longitude], 11);
                    resolve();
                },
                resolve,
                { timeout: 5000, maximumAge: 600000 },
            );
        });
    } catch {
        // API de permissões indisponível: fica a vista do país
    }
}

async function arrancar() {
    atualizarEstadoLigacao();
    aplicarTemaAoMapa(temaAtual());

    estado.semTag = lerLocal('semTag', CONFIG.semTag);
    $('chk-sem-tag').checked = estado.semTag;

    const separador = lerLocal('separador', 'local');
    if (['local', 'moradas', 'zonas', 'dados'].includes(separador)) mostrarSeparador(separador);

    carregarGuardadas();
    desenharComparacao();

    await centrarNaPessoa();
    const ok = await carregarUnidades();
    if (!ok) return;

    mostrarInfoDados();
    desenharRetrato();
    await lerComparacaoDoEndereco();
    atualizarResumosGuardadas();
}

// Service worker: guarda a aplicação e os dados para funcionar sem ligação.
// Só faz sentido em https ou em localhost, que é onde o browser o permite.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {
            // sem service worker a página funciona à mesma, só não funciona offline
        });
    });
}

arrancar();
