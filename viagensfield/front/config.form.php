<?php

/**
 * Plugin Viagens Field - configuração: permissões, caixa/alertas e opções das viagens
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

if (!PluginViagensfieldConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$C = PluginViagensfieldConfig::class;
$e = [$C, 'e'];
$acao = $CFG_GLPI['root_doc'] . '/plugins/viagensfield/front/config.form.php';
$abas = ['permissoes' => ['Permissões', 'ti ti-shield-lock'], 'caixa' => ['Caixa e alertas', 'ti ti-cash'], 'viagens' => ['Viagens', 'ti ti-car']];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'permissoes');
if (!isset($abas[$aba])) {
    $aba = 'permissoes';
}

// ---------------------------------------------------------------- processamento
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    $ids = fn($campo) => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST[$campo] ?? [])), fn($v) => $v > 0)));

    switch ((string) $_POST['save_action']) {
        case 'salvar_permissoes':
            foreach (array_keys(PluginViagensfieldConfig::AREAS) as $area) {
                $C::setArrayConfig($area . '_profiles', $ids($area . '_profiles'));
                $C::setArrayConfig($area . '_users', $ids($area . '_users'));
            }
            Session::addMessageAfterRedirect('Permissões salvas.', true, INFO);
            break;

        case 'salvar_caixa':
            $minimo = max(0, $C::paraNumero($_POST['valor_minimo_alerta'] ?? '0'));
            $C::setConfig('valor_minimo_alerta', number_format($minimo, 2, '.', ''));
            $informados = preg_split('/[\s,;]+/', (string) ($_POST['emails_alerta'] ?? '')) ?: [];
            $validos = array_values(array_unique(array_filter($informados, fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL))));
            $invalidos = array_values(array_filter($informados, fn($m) => $m !== '' && !filter_var($m, FILTER_VALIDATE_EMAIL)));
            $C::setConfig('emails_alerta', implode(', ', $validos));
            $C::setConfig('bloquear_sem_saldo', !empty($_POST['bloquear_sem_saldo']) ? '1' : '0');
            Session::addMessageAfterRedirect('Configurações do caixa salvas.', true, INFO);
            if ($invalidos) {
                Session::addMessageAfterRedirect('E-mails ignorados por serem inválidos: ' . implode(', ', $invalidos), true, WARNING);
            }
            break;

        case 'salvar_viagens':
            $meios = [];
            foreach ((array) ($_POST['meios'] ?? []) as $m) {
                $m = mb_substr(trim((string) $m), 0, 100);
                if ($m !== '' && !in_array(mb_strtolower($m), array_map('mb_strtolower', $meios), true)) {
                    $meios[] = $m;
                }
            }
            $C::setArrayConfig('meios', $meios ?: PluginViagensfieldConfig::MEIOS_PADRAO);
            $C::setConfig('entidades_filhas', !empty($_POST['entidades_filhas']) ? '1' : '0');
            Session::addMessageAfterRedirect('Opções das viagens salvas.', true, INFO);
            break;
    }
}

Html::header('Viagens Field', $_SERVER['PHP_SELF'], 'tools', 'PluginViagensfieldMenu', 'config');

echo '<link rel="stylesheet" href="' . $e($C::urlAsset('public/css/config.css')) . '">';
echo '<script src="' . $e($C::urlAsset('public/js/multiselect.js')) . '"></script>';

$perfis = $C::listarPerfis();
$usuarios = $C::listarUsuarios();

function viagensfield_switch(string $nome, bool $marcado, string $rotulo, string $dica = ''): string
{
    $e = [PluginViagensfieldConfig::class, 'e'];
    $id = 'viagensfield-sw-' . $nome;
    return '<div class="form-check form-switch viagensfield-switch">'
        . '<input class="form-check-input" type="checkbox" role="switch" id="' . $e($id) . '" name="' . $e($nome) . '" value="1"' . ($marcado ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . $e($id) . '">' . $e($rotulo) . ($dica !== '' ? '<small>' . $e($dica) . '</small>' : '') . '</label></div>';
}

function viagensfield_rodape(string $aba): string
{
    // O token CSRF (GLPI 11) entra pelo Html::closeForm()
    return '<input type="hidden" name="aba" value="' . PluginViagensfieldConfig::e($aba) . '">'
        . '<div class="viagensfield-cfg-rodape"><button type="submit" class="btn btn-sm viagensfield-btn-salvar"><i class="ti ti-device-floppy me-1"></i>Salvar</button></div>';
}

echo '<div class="viagensfield-cfg">';

echo '<ul class="nav nav-tabs viagensfield-cfg-abas" role="tablist">';
foreach ($abas as $chave => [$rotulo, $icone]) {
    echo '<li class="nav-item"><a href="#" class="nav-link' . ($chave === $aba ? ' active' : '') . '" data-viagensfield-aba="' . $chave . '">'
        . '<i class="' . $icone . ' me-1"></i>' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ------------------------------------------------------------------ Permissões
echo '<div class="viagensfield-cfg-painel" data-viagensfield-painel="permissoes"' . ($aba === 'permissoes' ? '' : ' hidden') . '>';
echo '<form method="post" action="' . $e($acao) . '">';
echo '<input type="hidden" name="save_action" value="salvar_permissoes">';
echo '<div class="card viagensfield-card"><div class="card-header"><h5><i class="ti ti-shield-lock"></i>Quem pode usar cada parte</h5></div><div class="card-body">';
echo '<p class="viagensfield-texto"><i class="ti ti-info-circle"></i><span>Administradores (Configurar &gt; Atualizar) sempre têm acesso a tudo. '
    . 'Em <b>Registrar viagens</b>, deixar perfis e usuários vazios libera a aba para todos que enxergam o chamado, mudança, problema ou projeto.</span></p>';
echo '<div class="viagensfield-cfg-tabela-wrap"><table class="table table-sm viagensfield-cfg-tabela"><thead><tr><th>Área</th><th>Perfis</th><th>Usuários</th></tr></thead><tbody>';
$descricoes = [
    'registrar' => 'Aba "Viagens" nos itens: registrar, editar e cancelar viagens.',
    'relatorio' => 'Menu Ferramentas &gt; Viagens: listagem, gráficos e exportação; colunas de custo na busca dos itens.',
    'caixa'     => 'Aba Caixa do painel: entradas, retiradas e extrato; excluir viagens com estorno.',
];
foreach (PluginViagensfieldConfig::AREAS as $area => $rotulo) {
    echo '<tr><td class="viagensfield-cfg-area"><b>' . $e($rotulo) . '</b><small>' . $descricoes[$area] . '</small></td>';
    echo '<td>' . $C::renderMultiselect($area . '_profiles', $perfis, $C::idsConfig($area . '_profiles'), 'Nenhum perfil') . '</td>';
    echo '<td>' . $C::renderMultiselect($area . '_users', $usuarios, $C::idsConfig($area . '_users'), 'Nenhum usuário') . '</td></tr>';
}
echo '</tbody></table></div>';
echo viagensfield_rodape('permissoes');
echo '</div></div>';
Html::closeForm();
echo '</div>';

// ------------------------------------------------------------------ Caixa
echo '<div class="viagensfield-cfg-painel" data-viagensfield-painel="caixa"' . ($aba === 'caixa' ? '' : ' hidden') . '>';
echo '<form method="post" action="' . $e($acao) . '">';
echo '<input type="hidden" name="save_action" value="salvar_caixa">';
echo '<div class="card viagensfield-card"><div class="card-header"><h5><i class="ti ti-cash"></i>Caixa e alertas</h5></div><div class="card-body">';
echo '<div class="viagensfield-cfg-grade">';
echo '<div class="viagensfield-cfg-campo"><label for="viagensfield-minimo">Saldo mínimo para alerta</label>'
    . '<div class="input-group viagensfield-input-curto"><span class="input-group-text">R$</span>'
    . '<input type="text" inputmode="decimal" class="form-control" id="viagensfield-minimo" name="valor_minimo_alerta" value="'
    . $e($C::moeda($C::valorMinimoAlerta(), false)) . '"></div>'
    . '<small>Quando um lançamento deixar o saldo abaixo deste valor, os e-mails abaixo recebem um aviso. Use 0 para desligar.</small></div>';
echo '<div class="viagensfield-cfg-campo"><label for="viagensfield-emails">E-mails para o alerta</label>'
    . '<input type="text" class="form-control" id="viagensfield-emails" name="emails_alerta" value="' . $e($C::getConfig('emails_alerta', '')) . '" placeholder="financeiro@empresa.com.br, gestor@empresa.com.br">'
    . '<small>Separe por vírgula. As notificações do GLPI precisam estar ativas.</small></div>';
echo '</div>';
echo viagensfield_switch('bloquear_sem_saldo', $C::bloquearSemSaldo(), 'Bloquear viagem sem saldo', 'Recusa o registro (ou o aumento de custo) quando o valor é maior que o saldo do caixa.');
echo '<div class="viagensfield-cfg-saldo"><i class="ti ti-wallet"></i>Saldo atual: <b>' . $e($C::moeda(PluginViagensfieldCaixa::saldo())) . '</b>'
    . '<a href="' . $e($CFG_GLPI['root_doc'] . '/plugins/viagensfield/front/painel.php?aba=caixa') . '">Abrir o caixa</a></div>';
echo viagensfield_rodape('caixa');
echo '</div></div>';
Html::closeForm();
echo '</div>';

// ------------------------------------------------------------------ Viagens
echo '<div class="viagensfield-cfg-painel" data-viagensfield-painel="viagens"' . ($aba === 'viagens' ? '' : ' hidden') . '>';
echo '<form method="post" action="' . $e($acao) . '">';
echo '<input type="hidden" name="save_action" value="salvar_viagens">';
echo '<div class="card viagensfield-card"><div class="card-header"><h5><i class="ti ti-car"></i>Opções das viagens</h5></div><div class="card-body">';
echo '<div class="viagensfield-cfg-campo"><label>Meios de transporte</label><small>Opções do campo "Meio de transporte" no registro da viagem. Aparecem na ordem desta lista.</small>';
echo '<div class="viagensfield-meios" data-viagensfield-meios>';
foreach ($C::meios() as $meio) {
    echo '<div class="viagensfield-meio"><input type="text" class="form-control form-control-sm" name="meios[]" value="' . $e($meio) . '" maxlength="100">'
        . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-viagensfield-remover-meio title="Remover"><i class="ti ti-x"></i></button></div>';
}
echo '</div>';
echo '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-viagensfield-adicionar-meio><i class="ti ti-plus me-1"></i>Adicionar meio</button>';
echo '</div>';
echo viagensfield_switch('entidades_filhas', $C::mostrarEntidadesFilhas(), 'Mostrar entidades filhas em Origem/Destino', 'Desligado: só aparecem as entidades principais (as filhas ficam escondidas).');
echo viagensfield_rodape('viagens');
echo '</div></div>';
Html::closeForm();
echo '</div>';

echo '</div>';

// Abas com URL atualizada e lista de meios de transporte
echo <<<HTML
<script>
(function () {
    'use strict';
    var raiz = document.querySelector('.viagensfield-cfg');
    if (!raiz) { return; }
    raiz.querySelectorAll('[data-viagensfield-aba]').forEach(function (link) {
        link.addEventListener('click', function (ev) {
            ev.preventDefault();
            var aba = link.getAttribute('data-viagensfield-aba');
            raiz.querySelectorAll('[data-viagensfield-aba]').forEach(function (l) { l.classList.toggle('active', l === link); });
            raiz.querySelectorAll('[data-viagensfield-painel]').forEach(function (p) { p.hidden = p.getAttribute('data-viagensfield-painel') !== aba; });
            raiz.querySelectorAll('input[name="aba"]').forEach(function (i) { i.value = aba; });
            var url = new URL(window.location.href);
            url.searchParams.set('aba', aba);
            history.replaceState(null, '', url.toString());
        });
    });
    var lista = raiz.querySelector('[data-viagensfield-meios]');
    raiz.addEventListener('click', function (ev) {
        var remover = ev.target.closest('[data-viagensfield-remover-meio]');
        if (remover) {
            remover.closest('.viagensfield-meio').remove();
            return;
        }
        if (ev.target.closest('[data-viagensfield-adicionar-meio]')) {
            var linha = document.createElement('div');
            linha.className = 'viagensfield-meio';
            linha.innerHTML = '<input type="text" class="form-control form-control-sm" name="meios[]" maxlength="100" placeholder="Novo meio de transporte">'
                + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-viagensfield-remover-meio title="Remover"><i class="ti ti-x"></i></button>';
            lista.appendChild(linha);
            linha.querySelector('input').focus();
        }
    });
})();
</script>
HTML;

Html::footer();
