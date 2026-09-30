<?php
// Reduz a grelha populacional do Eurostat (Census 2021, células de 1 km) ao que
// este projeto precisa: só Portugal, em WGS84, num ficheiro pequeno.
//
// Uso: php scripts/preparar_populacao.php <caminho/para/ESTAT_Census_2021_V3.csv>
//
// Entrada:  ~340 MB, a Europa inteira, coordenadas em ETRS89-LAEA (EPSG:3035)
// Saída:    data/populacao_pt.csv com lat,lon,t,t65 (~3 MB)
//
// O ficheiro é lido linha a linha: nunca entra inteiro em memória.
//
// Fonte dos dados: Eurostat, Census 2021 population grid (© União Europeia, 2026).
// A reutilização obriga a indicar a fonte e a declarar que os dados foram modificados.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser corrido na linha de comandos.');
}

$entrada = $argv[1] ?? '';
if ($entrada === '' || !is_readable($entrada)) {
    echo "Uso: php scripts/preparar_populacao.php <caminho/para/ESTAT_Census_2021_V3.csv>\n";
    exit(1);
}

$saida = __DIR__ . '/../data/populacao_pt.csv';

// ---------- Projeção ----------

// Inversa da Lambert Azimutal de Área Igual (ETRS89-LAEA, EPSG:3035) para WGS84.
// Fórmulas: EPSG Guidance Note 7-2 / Snyder, "Map Projections - A Working Manual".
// Verificada contra o exemplo oficial da EPSG: (3962799.45, 2999718.85) -> 50°N, 5°E.
function laea_para_wgs84($este, $norte)
{
    static $constantes = null;

    if ($constantes === null) {
        $a = 6378137.0;           // semieixo maior, GRS80
        $e2 = 0.00669438002290;   // primeira excentricidade ao quadrado
        $e = sqrt($e2);
        $lat0 = deg2rad(52.0);    // latitude de origem
        $lon0 = deg2rad(10.0);    // meridiano central

        // q(phi): função de área autálica
        $q = function ($phi) use ($e, $e2) {
            $s = sin($phi);
            return (1 - $e2) * ($s / (1 - $e2 * $s * $s)
                - (1 / (2 * $e)) * log((1 - $e * $s) / (1 + $e * $s)));
        };

        $qP = $q(M_PI / 2);
        $beta0 = asin($q($lat0) / $qP);
        $Rq = $a * sqrt($qP / 2);

        $constantes = [
            'e2' => $e2,
            'lat0' => $lat0,
            'lon0' => $lon0,
            'beta0' => $beta0,
            'Rq' => $Rq,
            'D' => $a * cos($lat0)
                / (sqrt(1 - $e2 * sin($lat0) * sin($lat0)) * $Rq * cos($beta0)),
        ];
    }

    ['e2' => $e2, 'lat0' => $lat0, 'lon0' => $lon0,
     'beta0' => $beta0, 'Rq' => $Rq, 'D' => $D] = $constantes;

    $x = $este - 4321000.0;   // falso este
    $y = $norte - 3210000.0;  // falso norte

    $rho = sqrt(($x / $D) * ($x / $D) + ($D * $y) * ($D * $y));
    if ($rho < 1e-12) {
        return [rad2deg($lat0), rad2deg($lon0)];
    }

    $C = 2 * asin($rho / (2 * $Rq));
    $beta = asin(cos($C) * sin($beta0) + ($D * $y * sin($C) * cos($beta0) / $rho));
    $lon = $lon0 + atan2(
        $x * sin($C),
        $D * $rho * cos($beta0) * cos($C) - $D * $D * $y * sin($beta0) * sin($C)
    );

    // latitude autálica -> geodésica (série; erro muito abaixo do metro)
    $e4 = $e2 * $e2;
    $e6 = $e4 * $e2;
    $lat = $beta
        + ($e2 / 3 + 31 * $e4 / 180 + 517 * $e6 / 5040) * sin(2 * $beta)
        + (23 * $e4 / 360 + 251 * $e6 / 3780) * sin(4 * $beta)
        + (761 * $e6 / 45360) * sin(6 * $beta);

    return [rad2deg($lat), rad2deg($lon)];
}

// O código INSPIRE aponta o canto inferior esquerdo da célula; devolvemos o centro.
// Ex.: CRS3035RES1000mN2577000E1081000 -> lado 1000 m, norte 2577000, este 1081000
function grd_id_para_wgs84($grdId)
{
    if (!preg_match('/RES(\d+)mN(\d+)E(\d+)/', $grdId, $m)) {
        return null;
    }
    $meio = (float) $m[1] / 2;
    return laea_para_wgs84((float) $m[3] + $meio, (float) $m[2] + $meio);
}

// ---------- Leitura ----------

$fe = fopen($entrada, 'r');
if (!$fe) {
    exit("Não foi possível abrir $entrada\n");
}

$cabecalho = fgetcsv($fe, 0, ',', '"', '');
if (!$cabecalho) {
    exit("O ficheiro está vazio ou não é CSV.\n");
}
// tira o BOM da primeira coluna, se existir
$cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalho[0]);

// As colunas são procuradas pelo nome: se o Eurostat mudar a ordem, isto aguenta.
$col = array_flip(array_map('trim', $cabecalho));
foreach (['GRD_ID', 'T', 'Y_GE65', 'CNTR_ID'] as $obrigatoria) {
    if (!isset($col[$obrigatoria])) {
        exit("Falta a coluna $obrigatoria no CSV. Colunas encontradas: "
            . implode(', ', $cabecalho) . "\n");
    }
}

$fs = fopen($saida, 'w');
if (!$fs) {
    exit("Não foi possível escrever em $saida\n");
}
fputcsv($fs, ['lat', 'lon', 't', 't65'], ',', '"', '');

$lidas = 0;
$doPais = 0;
$escritas = 0;
$vazias = 0;
$confidenciais = 0;   // -8888
$indisponiveis = 0;   // -9999
$semCodigo = 0;
$totalPop = 0;
$total65 = 0;
$minLat = 90.0;
$maxLat = -90.0;
$minLon = 180.0;
$maxLon = -180.0;

echo "A ler $entrada\n";

while (($linha = fgetcsv($fe, 0, ',', '"', '')) !== false) {
    $lidas++;
    if ($lidas % 250000 === 0) {
        echo '  ' . number_format($lidas) . " linhas lidas, $escritas células de Portugal\n";
    }

    // Células de fronteira vêm com vários países ("ES-PT"), por isso não dá para comparar
    // com igualdade. Os códigos têm sempre duas letras, portanto procurar "PT" é seguro.
    if (strpos($linha[$col['CNTR_ID']] ?? '', 'PT') === false) {
        continue;
    }
    $doPais++;

    $t = (int) $linha[$col['T']];
    $t65 = (int) $linha[$col['Y_GE65']];

    // -8888 = escondido por confidencialidade, -9999 = indisponível.
    // Nunca somar isto como se fosse população.
    if ($t === -8888) {
        $confidenciais++;
        continue;
    }
    if ($t === -9999) {
        $indisponiveis++;
        continue;
    }
    if ($t <= 0) {
        $vazias++;
        continue;
    }
    if ($t65 < 0) {
        $t65 = 0; // total conhecido, escalão etário escondido
    }

    $coords = grd_id_para_wgs84($linha[$col['GRD_ID']]);
    if ($coords === null) {
        $semCodigo++;
        continue;
    }
    [$lat, $lon] = $coords;

    fputcsv($fs, [round($lat, 5), round($lon, 5), $t, $t65], ',', '"', '');
    $escritas++;
    $totalPop += $t;
    $total65 += $t65;
    $minLat = min($minLat, $lat);
    $maxLat = max($maxLat, $lat);
    $minLon = min($minLon, $lon);
    $maxLon = max($maxLon, $lon);
}

fclose($fe);
fclose($fs);
@chmod($saida, 0666); // o Apache corre com outro utilizador

echo "\n--- Resumo ---\n";
echo 'Linhas lidas:            ' . number_format($lidas) . "\n";
echo 'Células com PT:          ' . number_format($doPais) . "\n";
echo 'Células escritas:        ' . number_format($escritas) . "\n";
echo 'Células sem população:   ' . number_format($vazias) . "\n";
echo 'Confidenciais (-8888):   ' . number_format($confidenciais) . "\n";
echo 'Indisponíveis (-9999):   ' . number_format($indisponiveis) . "\n";
if ($semCodigo) {
    echo "Códigos ilegíveis:       $semCodigo\n";
}
echo 'População total:         ' . number_format($totalPop) . "\n";
echo 'Com 65 ou mais:          ' . number_format($total65)
    . ($totalPop ? ' (' . round(100 * $total65 / $totalPop, 1) . '%)' : '') . "\n";
if ($escritas) {
    printf("Limites:                 lat %.4f a %.4f, lon %.4f a %.4f\n",
        $minLat, $maxLat, $minLon, $maxLon);
}
echo "\nGuardado em data/populacao_pt.csv ("
    . number_format(filesize($saida) / 1048576, 1) . " MB)\n";
echo "\nConfere: a população residente em Portugal nos Censos 2021 foi 10.343.066.\n";
echo "Se o total acima andar perto disso, a leitura está certa.\n";