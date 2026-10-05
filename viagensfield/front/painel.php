<?php

/**
 * Plugin Viagens Field - painel: Viagens | Relatórios | Caixa
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

$C = PluginViagensfieldConfig::class;
$podeRelatorio = $C::pode('relatorio');
$podeCaixa = $C::pode('caixa');
if (!$podeRelatorio && !$podeCaixa) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$e = [$C, 'e'];

$abas = [];
if ($podeRelatorio) {
    $abas['viagens'] = ['Viagens', 'ti ti-list'];
    $abas['relatorios'] = ['Relatórios', 'ti ti-chart-bar'];
}
if ($podeCaixa) {
    $abas['caixa'] = ['Caixa', 'ti ti-cash'];
}
$aba = (string) ($_GET['aba'] ?? '');
if (!isset($abas[$aba])) {
    $aba = array_key_first($abas);
}

$filtros = PluginViagensfieldRelatorio::lerFiltros($_GET);
if ($filtros['de'] === '' && !isset($_GET['de'])) {
    $filtros['de'] = date('Y-m-01', strtotime('first day of -2 months'));
}

Html::header('Viagens', $_SERVER['PHP_SELF'], 'tools', 'PluginViagensfieldMenu', 'painel');

echo '<link rel="stylesheet" href="' . $e($C::urlAsset('public/css/viagensfield.css')) . '">';
if ($podeRelatorio) {
    echo '<script src="' . $e($CFG_GLPI['root_doc'] . '/lib/echarts.min.js') . '"></script>';
}
echo '<script src="' . $e($C::urlAsset('public/js/viagem.js')) . '"></script>';
echo '<script src="' . $e($C::urlAsset('public/js/painel.js')) . '"></script>';

echo '<div class="viagensfield-painel" id="viagensfield-painel"'
    . ' data-ajax="' . $e($CFG_GLPI['root_doc'] . '/plugins/viagensfield/front/ajax.php') . '"'
    . ' data-token="' . $e($C::tokenCsrf()) . '"'
    . ' data-aba="' . $e($aba) . '">';

echo '<ul class="nav nav-tabs viagensfield-abas" role="tablist">';
foreach ($abas as $chave => [$rotulo, $icone]) {
    echo '<li class="nav-item"><a href="#" class="nav-link' . ($chave === $aba ? ' active' : '') . '" data-viagensfield-aba="' . $chave . '">'
        . '<i class="' . $icone . ' me-1"></i>' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- filtros (Viagens e Relatórios)
if ($podeRelatorio) {
    $opcoes = PluginViagensfieldRelatorio::opcoesFiltros();
    echo '<form class="viagensfield-filtros" id="viagensfield-filtros"' . ($aba === 'caixa' ? ' hidden' : '') . ' autocomplete="off">';
    echo '<div class="viagensfield-filtro viagensfield-filtro-data"><label>De</label>';
    Html::showDateField('de', ['value' => $filtros['de'], 'maybeempty' => true]);
    echo '</div>';
    echo '<div class="viagensfield-filtro viagensfield-filtro-data"><label>Até</label>';
    Html::showDateField('ate', ['value' => $filtros['ate'], 'maybeempty' => true]);
    echo '</div>';
    echo '<div class="viagensfield-filtro"><label>Técnico</label>';
    Dropdown::showFromArray('tecnico', [0 => 'Todos'] + $opcoes['tecnicos'], ['value' => $filtros['tecnico'], 'width' => '190px']);
    echo '</div>';
    echo '<div class="viagensfield-filtro"><label>Entidade</label>';
    Dropdown::showFromArray('entidade', [-1 => 'Todas'] + $opcoes['entidades'], ['value' => $filtros['entidade'], 'width' => '220px']);
    echo '</div>';
    echo '<div class="viagensfield-filtro"><label>Status</label>';
    Dropdown::showFromArray('status', ['' => 'Todos'] + PluginViagensfieldConfig::STATUS, ['value' => $filtros['status'], 'width' => '140px']);
    echo '</div>';
    $tipos = ['' => 'Todos'];
    foreach (PluginViagensfieldConfig::ITEMTYPES as $t) {
        $tipos[$t] = PluginViagensfieldConfig::nomeItemtype($t, 2);
    }
    echo '<div class="viagensfield-filtro"><label>Vínculo</label>';
    Dropdown::showFromArray('itemtype', $tipos, ['value' => $filtros['itemtype'], 'width' => '140px']);
    echo '</div>';
    echo '<div class="viagensfield-filtro-acoes">';
    echo '<button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Filtrar</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-viagensfield-limpar><i class="ti ti-x me-1"></i>Limpar</button>';
    echo '</div>';
    echo '</form>';

    // KPIs (compartilhados pelas duas abas)
    echo '<div class="viagensfield-kpis" id="viagensfield-kpis"' . ($aba === 'caixa' ? ' hidden' : '') . '>';
    $kpis = [
        'quantidade' => ['Viagens', 'ti ti-car'],
        'custo'      => ['Custo total', 'ti ti-cash'],
        'km'         => ['Distância', 'ti ti-route'],
        'media'      => ['Custo médio', 'ti ti-sum'],
        'por_km'     => ['Custo por km', 'ti ti-gauge'],
        'pendentes'  => ['Pendentes', 'ti ti-clock'],
        'canceladas' => ['Canceladas', 'ti ti-circle-x'],
    ];
    foreach ($kpis as $chave => [$rotulo, $icone]) {
        echo '<div class="viagensfield-kpi"><i class="' . $icone . '"></i><div><span class="viagensfield-kpi-rotulo">' . $e($rotulo)
            . '</span><span class="viagensfield-kpi-valor" data-kpi="' . $chave . '">—</span></div></div>';
    }
    echo '</div>';

    // ------------------------------------------------------------ aba Viagens
    echo '<section class="viagensfield-secao" data-viagensfield-painel="viagens"' . ($aba === 'viagens' ? '' : ' hidden') . '>';
    echo '<div class="card viagensfield-card">';
    echo '<div class="card-header viagensfield-card-topo"><h5><i class="ti ti-list"></i>Viagens <span class="viagensfield-contador" id="viagensfield-contador"></span></h5>';
    echo '<div class="viagensfield-ferramentas">';
    echo '<input type="search" class="form-control form-control-sm viagensfield-busca" id="viagensfield-busca" placeholder="Pesquisar na lista...">';
    echo '<select class="form-select form-select-sm viagensfield-por-pagina" id="viagensfield-por-pagina">';
    foreach ([15, 25, 50, 100] as $n) {
        echo '<option value="' . $n . '"' . ($n === 25 ? ' selected' : '') . '>' . $n . ' por página</option>';
    }
    echo '</select>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-viagensfield-exportar="csv"><i class="ti ti-file-spreadsheet me-1"></i>CSV</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-viagensfield-exportar="imprimir"><i class="ti ti-printer me-1"></i>Imprimir</button>';
    echo '</div></div>';
    echo '<div class="table-responsive"><table class="table table-hover table-sm viagensfield-tabela" id="viagensfield-tabela-viagens"><thead><tr>'
        . '<th data-sort="id" data-tipo="numero">#</th>'
        . '<th data-sort="data_iso" data-tipo="texto">Data</th>'
        . '<th data-sort="item_texto" data-tipo="texto">Vínculo</th>'
        . '<th data-sort="entidade" data-tipo="texto">Entidade</th>'
        . '<th data-sort="tecnico" data-tipo="texto">Técnico</th>'
        . '<th data-sort="destino" data-tipo="texto">Trajeto</th>'
        . '<th data-sort="meio" data-tipo="texto">Meio</th>'
        . '<th data-sort="km" data-tipo="numero" class="text-end">Km</th>'
        . '<th data-sort="custo" data-tipo="numero" class="text-end">Custo</th>'
        . '<th data-sort="status_nome" data-tipo="texto">Status</th>'
        . '<th class="viagensfield-no-export text-center">Comprovantes</th>'
        . '</tr></thead><tbody><tr><td colspan="11" class="viagensfield-carregando"><i class="ti ti-loader"></i>Carregando...</td></tr></tbody></table></div>';
    echo '<div class="viagensfield-paginacao" id="viagensfield-paginacao"></div>';
    echo '</div>';
    echo '</section>';

    // ------------------------------------------------------------ aba Relatórios
    echo '<section class="viagensfield-secao" data-viagensfield-painel="relatorios"' . ($aba === 'relatorios' ? '' : ' hidden') . '>';
    echo '<div class="viagensfield-graficos">';
    $graficos = [
        'meses'     => ['Evolução mensal do custo', 'ti ti-chart-line'],
        'status'    => ['Viagens por status', 'ti ti-chart-pie'],
        'entidades' => ['Custo por entidade (10 maiores)', 'ti ti-building'],
        'tecnicos'  => ['Custo por técnico (10 maiores)', 'ti ti-user'],
    ];
    foreach ($graficos as $chave => [$rotulo, $icone]) {
        echo '<div class="card viagensfield-card"><div class="card-header"><h5><i class="' . $icone . '"></i>' . $e($rotulo) . '</h5></div>'
            . '<div class="card-body"><div class="viagensfield-grafico" data-grafico="' . $chave . '"></div></div></div>';
    }
    echo '</div>';
    echo '</section>';
}

// ---------------------------------------------------------------- aba Caixa
if ($podeCaixa) {
    echo '<section class="viagensfield-secao" data-viagensfield-painel="caixa"' . ($aba === 'caixa' ? '' : ' hidden') . '>';

    echo '<div class="viagensfield-kpis viagensfield-kpis-caixa">';
    $kpisCaixa = [
        'saldo'    => ['Saldo atual', 'ti ti-wallet'],
        'entrada'  => ['Entradas no período', 'ti ti-arrow-down-left'],
        'retirada' => ['Retiradas no período', 'ti ti-arrow-up-right'],
        'despesa'  => ['Despesas de viagem', 'ti ti-car'],
        'estorno'  => ['Estornos', 'ti ti-arrow-back-up'],
    ];
    foreach ($kpisCaixa as $chave => [$rotulo, $icone]) {
        echo '<div class="viagensfield-kpi"><i class="' . $icone . '"></i><div><span class="viagensfield-kpi-rotulo">' . $e($rotulo)
            . '</span><span class="viagensfield-kpi-valor" data-caixa-kpi="' . $chave . '">—</span></div></div>';
    }
    echo '</div>';
    echo '<div class="viagensfield-alerta viagensfield-alerta-aviso" id="viagensfield-saldo-baixo" hidden><i class="ti ti-alert-triangle"></i><span></span></div>';

    echo '<div class="viagensfield-caixa-grade">';

    // Novo lançamento
    echo '<div class="card viagensfield-card"><div class="card-header"><h5><i class="ti ti-plus"></i>Novo lançamento</h5></div><div class="card-body">';
    echo '<form id="viagensfield-form-caixa" class="viagensfield-form-caixa" autocomplete="off">';
    echo '<div class="btn-group viagensfield-segmento" role="group">'
        . '<input type="radio" class="btn-check" name="tipo" id="viagensfield-tipo-entrada" value="entrada" checked>'
        . '<label class="btn btn-sm btn-outline-secondary" for="viagensfield-tipo-entrada"><i class="ti ti-arrow-down-left me-1"></i>Entrada</label>'
        . '<input type="radio" class="btn-check" name="tipo" id="viagensfield-tipo-retirada" value="retirada">'
        . '<label class="btn btn-sm btn-outline-secondary" for="viagensfield-tipo-retirada"><i class="ti ti-arrow-up-right me-1"></i>Retirada</label>'
        . '</div>';
    echo '<div class="viagensfield-cfg-campo"><label for="viagensfield-caixa-valor">Valor</label><div class="input-group"><span class="input-group-text">R$</span>'
        . '<input type="text" inputmode="decimal" class="form-control" id="viagensfield-caixa-valor" name="valor" data-viagensfield-moeda placeholder="0,00" required></div></div>';
    echo '<div class="viagensfield-cfg-campo"><label for="viagensfield-caixa-obs">Motivo</label>'
        . '<input type="text" class="form-control" id="viagensfield-caixa-obs" name="observacao" maxlength="1000" placeholder="Ex.: reposição do caixa de março" required></div>';
    echo '<div class="viagensfield-form-rodape"><button type="submit" class="btn btn-sm viagensfield-btn-salvar"><i class="ti ti-device-floppy me-1"></i>Registrar</button></div>';
    echo '</form>';
    echo '</div></div>';

    // Extrato
    echo '<div class="card viagensfield-card"><div class="card-header viagensfield-card-topo"><h5><i class="ti ti-history"></i>Extrato</h5>';
    echo '<form class="viagensfield-ferramentas" id="viagensfield-filtros-caixa" autocomplete="off">';
    echo '<div class="viagensfield-filtro viagensfield-filtro-data"><label>De</label>';
    Html::showDateField('caixa_de', ['value' => date('Y-m-01', strtotime('first day of -2 months')), 'maybeempty' => true]);
    echo '</div><div class="viagensfield-filtro viagensfield-filtro-data"><label>Até</label>';
    Html::showDateField('caixa_ate', ['value' => '', 'maybeempty' => true]);
    echo '</div><div class="viagensfield-filtro"><label>Tipo</label>';
    Dropdown::showFromArray('caixa_tipo', ['' => 'Todos'] + PluginViagensfieldCaixa::TIPOS, ['value' => '', 'width' => '160px']);
    echo '</div>';
    echo '<button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Filtrar</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-viagensfield-exportar="caixa"><i class="ti ti-file-spreadsheet me-1"></i>CSV</button>';
    echo '</form></div>';
    echo '<div class="table-responsive viagensfield-extrato"><table class="table table-hover table-sm viagensfield-tabela" id="viagensfield-tabela-caixa"><thead><tr>'
        . '<th>Data</th><th>Tipo</th><th>Motivo / vínculo</th><th>Usuário</th><th class="text-center">Comprovantes</th><th class="text-end">Valor</th><th class="text-end">Saldo após</th>'
        . '</tr></thead><tbody><tr><td colspan="7" class="viagensfield-carregando"><i class="ti ti-loader"></i>Carregando...</td></tr></tbody></table></div>';
    echo '</div>';

    echo '</div>';
    echo '</section>';
}

echo '</div>';

Html::footer();
