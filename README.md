# How long does it take to reach a hospital?

A map of access to healthcare in Portugal. For any address, it computes driving time to the nearest emergency room, health centre and maternity ward, identifies "health deserts", and helps decide where a new unit is missing.

Two things set it apart from other accessibility maps.

**It counts people, not area.** A territory map says "40% of the country is far away", which sounds dramatic and means almost nothing, because that territory is mostly empty mountain. This project answers the question that matters to whoever decides and to whoever lives there: how many people, who they are, and where.

**It uses a trained machine learning model to search for sites.** Asking "where should the next unit go" is a combinatorial optimisation problem, and every evaluation used to cost a full round trip to a routing engine. A geographically weighted regression model, trained on travel times the router had already computed, answers in microseconds instead of seconds. That is what turns "confirm three guesses" into "search the entire country".

Open data only, no API keys: units via Overpass (OpenStreetMap), addresses via Nominatim, road travel times via OSRM, population from the Eurostat Census 2021 grid, and base map tiles from OSM.

## Features

**For people looking for care**

- **Single point analysis.** Address, map click, or browser location. Minutes to each type of unit, a rating (good, limited, desert) and the three nearest options of each type. It answers in under a second plus OSRM time, because a straight-line prefilter reduces 1,500 units to 15 candidates before asking for real travel times.
- **Turn by turn directions.** The route drawn on the map with instructions in Portuguese ("Turn left onto Avenida Central", "At the roundabout, take the 2nd exit"), including the distance of each manoeuvre.
- **Search by unit name.** Typing "São João" or "Maternidade" finds the unit in already loaded data, with no waiting on anyone. Addresses still go to Nominatim, but only on submit.
- **Printable emergency sheet.** An A4 page with the address, GPS coordinates, the nearest emergency room and two alternatives, plus 112 and SNS 24. To stick on the fridge or hand to whoever is watching the kids.
- **Works offline.** The app and its data are stored on the device via a service worker. With no connection it still says which unit is nearest and how far, as the crow flies, and says clearly that this is what it is doing.

**For people making a decision**

- **My addresses.** Save home, work, your parents' house, and see access for all of them at once. Stays on the device only.
- **Compare addresses.** Up to 4 addresses side by side, with the best one highlighted per care type. The comparison lives in the page URL, so the link can be sent to someone. For anyone choosing a house, a school or a care home.

**For public services, councils and journalists**

- **Health deserts as shapes, not squares.** Computes time to the nearest unit across the whole visible area and draws it as continuous colour regions with smoothed contours and percentages per class. The grid was an artefact of the computation; the shape shows the same information without pretending access changes in flat 5 km steps. A precomputed national map is also included.
- **Where is a unit missing?** Tests several sites in the visible area and proposes the one where a new unit would help most, stating how much the average time drops and how much of the area leaves desert status.
- **Catchment area.** Clicking a unit shows the territory it is, in practice, the nearest one to by road. Which is not the same as the administrative area assigned to it, and the difference between the two is usually the interesting part.
- **CSV export.** Units, computed points in the area (with population per cell), comparisons and saved addresses, in files that Portuguese Excel opens without asking anything.

**In people, not in area** (the «Pessoas» tab)

The tab that changes how the whole map reads. The same regions, counted in human beings.

- **National portrait in people.** How many people live in each access class, with the 65+ bracket broken out, because that is who uses emergency care most, drives least, and lives furthest away. Includes an equity quotient: how many times more likely you are to be in a health desert if you are 65 or older.
- **Where the most people live far away.** The regions with the largest population above the threshold, merging adjacent cells and sorted by people, not by the most remote point, which is always an empty hilltop. Each row jumps to that place on the map.
- **A sentence ready to quote.** With the numbers and the source already attached, so whoever writes about this does not have to do arithmetic or invent phrasing.
- **Where this address sits nationally.** Single point analysis now says "worse than the access of X% of the Portuguese population" along with the national median. A time on its own does not tell you whether it is good or bad: 25 minutes feels long in Lisbon and is a luxury inland.
- **How many people live around you.** Population within a 10 km radius, with the 65+ share.
- **People or area, your choice.** The regional summary toggles between the two readings. They usually tell opposite stories, and the difference between them is the entire point of this project.
- **Planning by people.** "Where is a unit missing" now picks candidates where the most people are underserved, rather than the point with the worst time, and reports how many people leave the health desert and how many total hours are saved.
- **Catchment in people.** "Serves 1,200 km²" tells nobody anything; "it is the nearest unit to 84,000 people" tells you everything.

**Criticality map**

The colour regions answer "where is it far". They do not answer the next question, which is the one that decides investment: "where does that hit a lot of people". A huge red region in the mountains and a small one around a town of eight thousand paint the same and are not worth the same.

- **People on top of the regions.** One circle per cell, with **area** proportional to population (not radius, because area is what the eye reads in a circle) and colour by access class. You see immediately where the regions catch people and where they catch mountain.
- **Critical mode.** Only cells above the threshold, with colour showing by how much they exceed it. Large dark circle = many people very far away.
- **Problem weight.** `people × minutes above threshold`, in person-minutes. It is the currency that lets you compare "many people slightly above" with "few people far above", and the ranking can be sorted by both, because they produce different lists and neither is wrong.
- **Ranking marked on the map.** The top regions appear numbered, with popups, over the national map.

**Where to build** (optimisation)

Given N new units, where should we put them? Found by optimisation over the entire country, not by trial and error over three guesses.

- **Machine learning travel time model.** Geographically weighted local linear regression, trained on travel times OSRM had already computed and evaluated on held-out data. For emergency rooms: R² of 0.85 and mean error of 7.2 minutes, against 0.67 and 8.7 for a single national regression line. It answers in microseconds, and that is what makes the search possible at all.
- **Simulated annealing.** Tens of thousands of configurations evaluated in seconds, with restarts and jumps between regions so it does not get stuck in a local optimum.
- **Two objectives to choose from**, because they are different questions with different answers: minimise total minutes (helps many people a little) or pull the maximum number of people out of the desert (concentrates on those worst off).
- **Verification against OSRM.** The model is an approximation and the result always states the margin it was obtained with; a button puts the chosen sites to the test with real routing.

## Running it

Requirements: PHP 7.4 or later with the `curl` extension (XAMPP ships with it).

1. In `config.php`, change the `user_agent` email. Nominatim and OSRM policies require the application to identify itself.
2. Generate the unit data (once; see the next section):

   ```bash
   php scripts/atualizar_unidades.php
   ```

3. Start the server:
   - **XAMPP:** copy the folder into `htdocs/` and open `http://localhost/mapa-saude/`.
   - **Without XAMPP:** inside the folder, run `php -S localhost:8000` and open `http://localhost:8000/`.

The `php scripts/...` commands must be run from inside the project folder (where `index.php` is).

Optional, for the national map and the percentages in the "Dados" tab:

```bash
php scripts/gerar_grelha.php todos 5
```

## Tests

215 tests, none of which touch the real APIs: `testes/falso.php` mocks Overpass, OSRM and Nominatim, so the suite runs offline and without consuming anyone's rate limit.

```bash
php testes/correr.php      # 112 API tests

npm install playwright leaflet
./testes/servidores.sh
node testes/browser.mjs     # 103 browser tests
```

`correr.php` backs up `data/unidades.json` and `data/correcoes.json` on startup and restores them on exit, even if the tests blow up halfway. See `testes/README.md` for cleanup after `servidores.sh`.

## The data

Units live in `data/unidades.json`, a ~300 KB file generated by `scripts/atualizar_unidades.php`. The page reads that file and nothing else; it never talks to Overpass.

This is deliberate. Few health units open or close in a year, so it makes no sense to ask a public server for the data on every visit: the page starts instantly, whoever clones the project has data immediately, and an entire class of failures disappears (429 errors, timeouts, servers down).

**Updating** (once a quarter is plenty):

```bash
php scripts/atualizar_unidades.php              # the normal path
php scripts/atualizar_unidades.php --estado     # which servers are available
php scripts/atualizar_unidades.php --tudo       # ignore cache and download everything
php scripts/atualizar_unidades.php --blocos-so  # skip the single country query
php scripts/atualizar_unidades.php --diagnostico e.json  # what was discarded and why
```

The script tries, in this order:

1. **A single query for the whole country**, one request. This is the normal path and, being one request instead of 78, it does not hit the per-IP limits of public servers. It works because the query uses bounding boxes and exact tag filters; the old version used `area["ISO3166-1"="PT"]` with regular expressions over names, which no index covers, and so always returned `Query timed out`.
2. **Block by block** (~78 half-degree squares, each with its own cache), only if the first fails. Run it again and it only requests the ones still missing.

If any block fails, the file is **not** replaced, so you never end up with blank regions.

**If Overpass refuses everything** (429 errors, and your IP gets blocked for a few minutes), there is a manual path that takes 2 minutes and never fails:

```bash
php scripts/atualizar_unidades.php --query > query.txt
# paste the contents into https://overpass-turbo.eu and hit Run
# Export > Data > "raw OSM data directly from Overpass API", save the .json
php scripts/atualizar_unidades.php path/to/that.json
```

It is worth committing `data/unidades.json`.

**How many units should you expect?** Around 1,500 (the current file has 1,553: 1,216 health centres, 370 emergency rooms, 47 maternity wards). That number is not the official SNS registry: it is the intersection of two things, what is mapped in OpenStreetMap and what the classification rules recognise.

**Maternity wards are a special case, and it is worth knowing why.** Only 6 come out of automatic classification, because the `healthcare:speciality=obstetrics` tag barely exists in Portuguese OSM data. The other 41 come from `data/correcoes.json`, identified by hand. Anyone regenerating the data without that file gets a very different maternity map, which makes `correcoes.json` as much a part of the dataset as `unidades.json`.

To see exactly what was discarded and why:

```bash
php scripts/atualizar_unidades.php --query > query.txt   # export the .json via Overpass Turbo
php scripts/atualizar_unidades.php --diagnostico that.json
```

It prints the element count, how many were dropped for having no name, for being veterinary, for having no coordinates, and for falling into no category, with examples of what was discarded, which is what lets you tell whether a name pattern is missing from `includes/dados_osm.php`.

**Data licence:** the data comes from OpenStreetMap, under ODbL 1.0. Redistributing this derived extract is permitted with attribution and under the same licence; the file carries a `licenca` field and the page shows attribution in the footer.

## Structure

```
index.php               page (tabbed panel + map)
config.php              API URLs, thresholds, cache
manifest.webmanifest    install as an app
sw.js                   service worker (offline support)
assets/icone.svg        browser tab and app icon
api/
  unidades.php          serves data/unidades.json, with ETag
  geocode.php           search and reverse geocoding (Nominatim)
  tempos.php            times from a point to each type
  rota.php              route + turn by turn directions
  grelha.php            travel time raster for an area (becomes shapes client-side)
  influencia.php        the area a unit actually serves
  planeamento.php       where a unit is missing
  populacao.php         national portrait, worst areas, percentile, problem weight
  otimizar.php          where to place N new units nationally
includes/
  funcoes.php           JSON, file cache, rate limiting, HTTP
  geo.php               Haversine, nearest neighbours, point in polygon, grid
  blocos.php            block splitting (only used when updating data)
  dados_osm.php         Overpass query, classification and data file
  osrm.php              /table and /route requests, directions in Portuguese
  analise.php           point analysis, catchment and planning
  populacao.php         population per cell, classes, percentile, worst regions
  modelo.php            travel time ML model (locally weighted regression)
  otimizacao.php        simulated annealing for facility location
scripts/
  atualizar_unidades.php  generates data/unidades.json
  gerar_grelha.php        generates the national maps (with population, if available)
  preparar_populacao.php  reduces the Eurostat grid to data/populacao_pt.csv
  treinar_modelo.php      trains and evaluates the travel time model
  otimizar.php            searches for the best sites, with more time than the API
assets/js/              nucleo, contornos, mapa, local, moradas, zonas, pessoas, dados, app
  pessoas.js            the «Pessoas» tab and the criticality map
  contornos.js          marching squares + Chaikin: turns the raster into curved shapes
assets/css/style.css    light and dark theme, print styles
data/                   unidades.json, correcoes.json, portugal.geojson,
                        populacao_pt.csv, grelha_<type>.json, modelo_tempo_<type>.json
cache/                  must be writable (OSRM, Nominatim)
testes/                 see testes/README.md
```

## Population

Travel times alone are not enough. `data/populacao_pt.csv` carries resident population per 1 km cell, and that is what makes it possible to say how many **people** are far away instead of how many square kilometres.

**Source:** [Eurostat, Census 2021 population grid](https://ec.europa.eu/eurostat/web/gisco/geodata/population-distribution/population-grids), a 1 km² grid in ETRS89-LAEA (EPSG:3035), with totals and age brackets per cell. © European Union, 2026. The licence requires stating the source **and** declaring that the data was modified; the project does both on every screen where the numbers appear.

**Preparing it** (once; the Eurostat file is ~340 MB and does not belong in the repository):

```bash
# download "Census grid 2021" (CSV/GPKG/Raster) from the link above and decompress
php scripts/preparar_populacao.php /path/to/ESTAT_Census_2021_V3.csv
```

The script reads the CSV line by line (it never loads the whole thing), keeps only Portugal, converts from LAEA to WGS84 and writes `data/populacao_pt.csv` with ~41k rows and under 1 MB. At the end it prints a summary to check against: the total should land near **10,343,066**, the resident population from the 2021 census.

After this, `php scripts/gerar_grelha.php todos 5` also stores population inside each national map, and the «Pessoas» tab comes alive.

### What the numbers say

With the data generated on 2026-09-22 and the thresholds below, to give a sense of scale:

| | above 1st threshold | in a health desert | 65+ in a desert |
|---|---|---|---|
| emergency room | 659,331 (6.4%) | 55,890 (0.5%) | 0.7%, or **1.3×** more likely |
| health centre | 837,283 (8.1%) | 67,460 (0.7%) | 1.1%, or **1.8×** more likely |
| maternity ward | 794,144 (7.7%) | 352,070 (3.4%) | 4.7%, or **1.4×** more likely |

The last column is the equity quotient: the probability that a resident aged 65 or over is in a health desert, divided by that of the average Portuguese resident. It is always above 1, and it is the number that justifies the «Pessoas» tab existing.

### How the arithmetic works

- **Projection.** The inverse Lambert Azimuthal Equal Area, in `includes/populacao.php`, verified against the official example in EPSG Guidance Note 7-2: (3962799.45, 2999718.85) gives exactly 50°N, 5°E.
- **Aggregation.** Each 1 km cell falls into the raster cell containing its centre.
- **Coastline.** Cells whose centre lands in a raster cell marked as sea are snapped to the nearest neighbouring land cell. Without this, entire coastal settlements were lost, and the Portuguese population is very coastal.
- **Averages.** The "per person" average travel time is population weighted. A per-cell average gives an empty mountain range the same weight as a Lisbon neighbourhood; this does not.
- **Percentile.** The national distribution (minutes, people) is built from the national maps and cached until those are regenerated.

### Problem weight

The critical area ranking merges adjacent cells above the threshold into a single region (breadth-first traversal, 8 neighbours) and gives each region two numbers:

- **people**, how many live there above the threshold;
- **weight**, `people × (mean minutes − threshold)`, in person-minutes.

Each region's centre is population weighted, so it lands where the people are and not at the geometric middle. Sorting by one or the other gives different lists: by people, a large town slightly above the threshold rises; by weight, a small island three hours away rises. The two readings are one click apart, deliberately. Choosing between them is a political decision, not a technical one, and the project does not make it for anyone.

### What to declare when citing these numbers

- The total comes out **~0.15% above** the census (10,358,722 against 10,343,066, about 15.6 thousand people). These are the border cells, which Eurostat marks as `ES-PT` and whose population includes people on the Spanish side.
- Cells marked `-8888` (confidentiality) or `-9999` (unavailable) are treated as unknown and **never summed as zero**. Portugal has none; other countries do.
- The age bracket is summed from `Y_GE65`. Eurostat warns that category sums may not match the total because of disclosure control, so the total always comes from `T`.
- Cells with no road route count towards total population but not towards access percentages, and are declared separately ("no computed time").
- The 5 km grid is coarse for dense urban areas. Local analyses are worth generating at a smaller step, which requires running your own OSRM.

## Where to build: model and optimisation

"Where is a unit missing" is a combinatorial optimisation problem, a variant of the *maximal covering location problem*. With 3,500 candidate sites and 5 units there are over 10^15 combinations; there is no way to try them all, and a greedy search gets stuck in the first local optimum it finds.

### The obstacle: every evaluation cost a round trip to OSRM

Evaluating a site means recomputing travel times for the entire grid. With OSRM that is dozens of requests and several seconds, which is why area-level planning only tests three candidates picked up front. That is not searching, it is guessing and confirming.

### The solution: a trained model

`includes/modelo.php` learns the relationship between straight-line distance and real road travel time, from the times OSRM had already computed for the national maps, and answers in microseconds. That difference, from seconds to microseconds, is what turns "confirm three guesses" into "search the whole country".

**Method: locally weighted linear regression** (geographically weighted). A single line for the whole country gives R² of 0.67 for emergency rooms, because the same distance means very different things in the Algarve and in Trás-os-Montes. Instead of one line, thousands are fitted: for each cell, the K = 28 nearest neighbours with known times are found and `t = a + b·d` is fitted by least squares with a Gaussian weight. `a` is the time to reach the road network; `b` is the local pace in minutes per km, and that is where mountain roads separate from motorway.

| emergency rooms | local model | global line |
|---|---|---|
| mean absolute error | **7.2 min** | 8.7 min |
| RMSE | **10.1 min** | 15.0 min |
| R² | **0.852** | 0.675 |

Measured on 726 cells held out entirely from training. Training takes under a second, thanks to a spatial index that avoids comparing every point with every other.

**Quality varies with care type, and that is visible in the interface:**

| type | local R² | global R² | mean error (local) | local vs global RMSE |
|---|---|---|---|---|
| maternity ward | 0.866 | 0.652 | 7.5 min | 10.5 vs 16.9 (−38%) |
| emergency room | 0.852 | 0.675 | 7.2 min | 10.1 vs 15.0 (−32%) |
| health centre | 0.514 | 0.491 | 6.5 min | 9.2 vs 9.4 (−2%) |

The pattern is clear: **the local model wins where distances are large**. For maternity wards, 47 units for the whole country, squared error drops 38%, because there is real geographic variation to explain (mainland and islands, coast and interior) and a single line does not capture it. For health centres the opposite happens: with 1,216 units almost everyone is within 10 km, where time is dominated by reaching the road network rather than by distance. The absolute error is the smallest of the three (6.5 min), but R² is low too, because there is little variation left to explain. A low R² here is not the model failing, it is the problem being easy.

**Order matters.** The model is trained from one specific national map. If you regenerate the maps (new units, corrections, a different step), you have to retrain, or the model describes a country that no longer exists. The project detects this and warns on the page itself, but it is better not to get there:

```bash
php scripts/gerar_grelha.php todos 5
php scripts/treinar_modelo.php todos
```

```bash
php scripts/treinar_modelo.php urgencia    # or "todos"
```

### The optimiser: simulated annealing

`includes/otimizacao.php`. It starts from a solution and swaps one site at a time. Swaps that improve are always accepted; swaps that worsen are accepted with decreasing probability, and that is what allows it to go down a valley in order to climb a higher hill.

- **Biased start** towards where the problem weighs most (people × minutes above threshold), which saves thousands of iterations wandering along the coast.
- **Mixed neighbourhood**: three proposals in four refine the site within ~50 km, the fourth jumps to another region of the country.
- **Independent restarts**, keeping the best. The spread across restarts is reported: if the three give very different results, the search was too short. It is the optimiser itself telling you when not to trust it.

```bash
php scripts/otimizar.php urgencia 5          # writes data/otimizacao_urgencia_5.json
```

The API (`api/otimizar.php`) does the same with a smaller time budget and caches the result; if a script result exists, it uses that.

### Being honest about the result

The model is an approximation, and the project treats it as one:

- the model's error appears **next to the result**, not in a footnote;
- a button verifies the chosen sites against real OSRM routing, on a sample of the cells that benefit most, and shows the measured error;
- "before" and "after" are always measured over **the same cells**. Without that, a cell that previously had no route at all would gain a time and make the desert *grow* when you build a unit. The tests caught exactly that bug.

### The two objectives give different answers

For 4 new emergency rooms:

| objective | leave the desert | hours saved |
|---|---|---|
| minimise total minutes | 17,627 | 40,707 |
| pull people out of the desert | 25,309 | 28,354 |

One helps many people a little, the other concentrates on those worst off, and the gap between them is 44% in people and 30% in hours. Neither is wrong. It is the choice between efficiency and equity, it is a political decision, and the project shows both and decides for nobody.

## Methodology

1. **Unit classification** (done in PHP, from OSM tags):
   - **Emergency room:** `emergency=yes`, or a general hospital without that tag (configurable option in "Dados").
   - **Health centre:** by name (Centro de Saúde, USF, UCSP, Unidade de Saúde, Extensão de Saúde).
   - **Maternity ward:** by name, `healthcare:speciality=obstetrics` or `healthcare=birthing_centre`, plus the manual corrections in `data/correcoes.json`, where most of them come from.
   - **Duplicates:** elements with the same name within ~100 m are merged (the same hospital sometimes appears as both a node and an area).
2. **Straight-line prefilter.** For each point, Haversine distance to every unit; the 5 nearest of each type are kept.
3. **Road travel time.** Those candidates (15 at most) go to OSRM in a single `/table` request. Analysis responds in under a second plus OSRM time.
4. **Grid.** The server returns a regular *raster* (origin, step in degrees, width, height and a value vector per row) instead of a list of squares. Points outside Portugal are discarded using the country polygon and marked in the `terra` vector; the rest are batched into groups that fit in one `/table` request. Points more than 3 km from a road are left without a value.
5. **Shapes.** The rendering shows no cells: `assets/js/contornos.js` runs *marching squares* over the raster and extracts the contour of each class ("up to 30 min", "up to 60 min", …), interpolating the boundary between adjacent points, and smooths it with two Chaikin passes. Nested rings become holes (`fillRule: 'evenodd'`), so a well served valley in the middle of a mountain range appears as a hole in the desert region. The land mask gets a 3×3 blur before being contoured, so the coastline does not come out as a staircase.
6. **Catchment area.** For each point in the area, the nearest unit by road is computed, and the points that chose the unit in question are marked.
7. **Where a unit is missing.** Current times are computed, the worst served points are taken as candidates (spaced apart, so as not to test three points in the same village) and, for each candidate, times are recomputed as if a unit existed there. The one that most reduces the average time wins. It is a heuristic, not an optimum: it serves for orders of magnitude, not as a replacement for a study.
8. **Access classification.** Thresholds live in `config.php` and are assumed, not official:

   | Type | Good access | Limited | Desert |
   |---|---|---|---|
   | Emergency room | up to 30 min | 30 to 60 min | over 60 min |
   | Health centre | up to 15 min | 15 to 30 min | over 30 min |
   | Maternity ward | up to 45 min | 45 to 60 min | over 60 min |

## How to read the map

**Markers.** A **filled** dot is a unit confirmed in the data: for emergency rooms, it has `emergency=yes` in OSM. A **white dot with a blue outline** is a general hospital whose emergency tag nobody filled in: it may have an emergency room, it may only have one at certain hours, it may have none. It only counts towards the calculations if the "Count hospitals with no emergency information" option is enabled under **Dados › Definições**; turning it off is the strict reading, and makes those points stop counting (the regions shrink accordingly). The same legend is on the page, under **Dados › Como ler o mapa**.

**Regions.** Colour is the time to the nearest unit of the selected type. The boundary between classes is interpolated between computed points, at the step shown in the summary: it is a continuous estimate, not an exact limit, and should not be read down to the metre.

## Limitations

- **Times without traffic.** OSRM estimates from the road network, with no traffic and no parking, so values tend to be optimistic.
- **Data quality.** Depends on OSM. The `emergency` tag is missing from many hospitals, and maternity wards depend almost entirely on manual corrections.
- **No distinction between public and private**, and no knowledge of whether an emergency room is closed on a given day.
- **Prefilter.** In rare cases (rivers without bridges, mountain ranges), the nearest unit by road may not be among the nearest in a straight line.
- **Area and people are two different readings.** The percentages in the «Zonas» tab refer to area; those in the «Pessoas» tab to population. When citing a number, it is worth saying which of the two it is, because they usually tell opposite stories.
- **Offline**, distances are straight-line, not travel times.

## Correcting data

The best way is to edit OpenStreetMap directly; every popup links to the element. For local-only corrections, use `data/correcoes.json` (always applied, with no need to re-download):

```json
{
    "remover": ["node/123"],
    "adicionar_tipo": { "way/456": ["maternidade"] },
    "unidades_extra": [
        { "id": "extra/1", "nome": "Hospital X", "lat": 41.15, "lon": -8.61, "tipos": ["urgencia", "maternidade"] }
    ]
}
```

## Routing servers

`osrm_urls` in `config.php` is a list, tried in order; if one server fails, it moves to the next. It ships with two public servers by default.

Public ones have rules, and they are worth knowing before you bang your head against a **403 Forbidden**:

- **Identification is mandatory.** OSRM policy says a User-Agent that does not identify the application leads to a block. If the `user_agent` in `config.php` still has the example email, that is the first place to look; the code detects this and says so in the error message.
- **No heavy usage.** One request per second, maximum. Single point analysis fits comfortably; **an area or national map does not**, those are hundreds of points and the server cuts you off.

In short: for day to day page use the public servers are enough; for the "Zonas" tab and the national maps, run your own OSRM. It is not just good manners, it is the only way for it to be fast and not break halfway.

## Your own OSRM (for areas and national maps)

Once running, area analysis takes seconds instead of minutes and rate limits disappear. With Docker:

```bash
wget https://download.geofabrik.de/europe/portugal-latest.osm.pbf
docker run -t -v "${PWD}:/data" ghcr.io/project-osrm/osrm-backend osrm-extract -p /opt/car.lua /data/portugal-latest.osm.pbf
docker run -t -v "${PWD}:/data" ghcr.io/project-osrm/osrm-backend osrm-partition /data/portugal-latest.osrm
docker run -t -v "${PWD}:/data" ghcr.io/project-osrm/osrm-backend osrm-customize /data/portugal-latest.osrm
docker run -t -p 5000:5000 -v "${PWD}:/data" ghcr.io/project-osrm/osrm-backend osrm-routed --algorithm mld --max-table-size 1000 /data/portugal-latest.osrm
```

Then create `config.local.php`:

```php
<?php
return ['osrm_url' => 'http://localhost:5000', 'osrm_max_coords' => 1000, 'intervalo_osrm' => 0];
```

`osrm_url` (singular) overrides the `osrm_urls` list, so this switches to the local server only.

## Common problems

- **"Access forbidden" / Error 403.** Apache cannot read the files. On macOS this happens with ACLs inherited from the Downloads folder:
  ```bash
  cd /Applications/XAMPP/xamppfiles/htdocs/mapa-saude
  chmod -R -N . && xattr -rc . && chmod -R 755 .
  ```
- **"PHP does not have permission to write to the cache folder".** The web server runs as a different user (on XAMPP for macOS it is `daemon`):
  ```bash
  sudo chown -R daemon:daemon /Applications/XAMPP/xamppfiles/htdocs/mapa-saude/cache
  sudo chmod -R 777 /Applications/XAMPP/xamppfiles/htdocs/mapa-saude/cache
  ```
  Use absolute paths: with relative paths it is easy for the command to hit the wrong folder.
- **"router.project-osrm.org responded with error 403".** The public routing server blocked the requests. Two causes, in order of likelihood: the `user_agent` in `config.php` still has the example email (OSRM policy requires the application to identify itself), or heavy usage, because an area or national map is hundreds of points and exceeds what the demo server allows. Put a real contact in `config.php`; for the Zonas tab, run your own OSRM (section above). The `osrm_urls` list already tries a second public server before giving up.
- **"SSL certificate problem" on XAMPP for Windows.** Download `cacert.pem` from https://curl.se/docs/caextract.html and point `curl.cainfo` at that file in `php.ini`.
- **Error 429 / "Could not connect" when updating.** Public servers rate limit per IP and, after a handful of 429s, stop accepting connections from that IP for a few minutes, hence the `Failed to connect ... after 300 ms`. The script reads the time the server asks for ("Slot available after: ..., in N seconds"), sets that server aside for that long and moves to the others; if all of them are waiting, it stops the round instead of insisting (insisting extends the block). Wait a few minutes and run it again, since already downloaded blocks are cached. If that does not work, use the manual Overpass Turbo path described above.
- **Seeing what is going on:** `php scripts/atualizar_unidades.php --estado` shows which servers are available, how many blocks are cached, and the date of the data file.
- **The page does not work offline.** Service workers are only allowed on `https` or `localhost`. Open the page once with a connection before testing without one.

## Ideas for what comes next

- **Open emergency rooms.** Integrate the emergency room status published by SNS, to distinguish "exists" from "open today".
- **Public transport.** The public OSRM only has the car profile; with your own server you could compare driving and transit, which is the difference between the access of people who drive and people who do not.
- **Isochrones.** Draw the areas reachable in 15, 30 and 60 minutes from each unit.
- **Finer grid.** The 5 km step is coarse for Porto and Lisbon; with your own OSRM you can go down to 1 km and cross with the Eurostat grid without losing resolution.
