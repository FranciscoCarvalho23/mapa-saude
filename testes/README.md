# Testes

Não tocam nas APIs reais: `falso.php` imita o Overpass, o OSRM e o Nominatim, por isso os
testes correm sem ligação e sem gastar o limite de pedidos de ninguém.

```bash
php testes/correr.php      # 112 testes de API
```

Browser (Playwright), a partir da pasta do projeto:

```bash
npm install playwright leaflet
./testes/servidores.sh
node testes/browser.mjs     # 103 testes
```

O `browser.mjs` serve o Leaflet a partir do `node_modules` e responde aos tiles com uma
imagem vazia, para não depender do cdnjs nem do servidor de tiles do OSM.

## Os teus dados ficam como estavam

O `correr.php` gera `data/unidades.json` e `data/correcoes.json` com dados fictícios — e
antigamente deixava-os lá, o que apagava em silêncio os dados reais de quem corria os
testes. Agora guarda os dois ficheiros ao arrancar e repõe-nos ao sair, mesmo que os
testes rebentem a meio.

O `servidores.sh` é que não repõe nada: escreve um `config.local.php` a apontar para o
servidor falso e limpa a cache. Depois de usares o `browser.mjs`, arruma:

```bash
kill $(cat /tmp/falso.pid) $(cat /tmp/app.pid) 2>/dev/null
rm -f config.local.php testes/falso_*.log testes/falso_*.json
```

Se tiveres corrido o `browser.mjs` com o `servidores.sh`, o `data/unidades.json` pode ter
ficado com os dados fictícios. Nesse caso:

```bash
php scripts/atualizar_unidades.php
```

## O que é coberto

- **API** (`correr.php`): blocos, query única, geração e recuperação do `data/unidades.json`,
  classificação das unidades e o seu diagnóstico, correções manuais, análise de um ponto,
  raster da zona (origem, passo, máscara de terra), influência, planeamento, geocodificação
  e população (resumo nacional, ranking de zonas, percentil, população por quadrícula na
  análise de zona), modelo de tempos (R² e erro fora do treino, bate a reta global) e
  otimização. As secções da população e da otimização ignoram-se sozinhas se ainda não
  existirem o `data/populacao_pt.csv`, os mapas nacionais ou o modelo treinado.
- **Browser** (`browser.mjs`): arranque com um só pedido, ícone do separador, marcadores
  cheios vs. só contorno, análise e percurso passo a passo, pesquisa por nome, moradas
  guardadas, comparação e o link que a reconstrói, manchas curvas no mapa, mapa nacional pré-calculado, retrato do país, planeamento,
  área de influência, ficha de emergência, CSV, separador «Pessoas» (retrato nacional,
  frase citável, ranking de zonas por população e por gravidade, círculos de população
  sobre as manchas e mapa nacional de criticidade), otimização de localizações
  (locais distintos e dentro de Portugal, nunca piorar o deserto nem o tempo médio,
  cache entre chamadas, os dois objetivos), tema escuro, funcionamento sem ligação
  (service worker incluído) e o ecrã de telemóvel.
