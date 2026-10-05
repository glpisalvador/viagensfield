<?php

/**
 * Plugin Viagens Field - endpoint AJAX (JSON).
 * Leituras por GET (não consomem token CSRF); lançamentos no caixa por POST.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno ao processar a solicitação.']);
    }
});

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

while (ob_get_level() > 0) {
    ob_end_clean();
}

function viagensfield_json(array $dados): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Só o POST consome o token do GLPI 11; as leituras não geram tokens à toa
    $dados['new_token'] = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? PluginViagensfieldConfig::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function viagensfield_erro(string $mensagem): never
{
    viagensfield_json(['success' => false, 'mensagem' => $mensagem]);
}

Session::checkLoginUser();

$acao = (string) ($_REQUEST['action'] ?? '');
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

switch ($acao) {
    case 'relatorio':
        if (!PluginViagensfieldConfig::pode('relatorio')) {
            viagensfield_erro('Sem acesso aos relatórios de viagens.');
        }
        viagensfield_json(['success' => true] + PluginViagensfieldRelatorio::consultar($_GET));

    case 'caixa':
        if (!PluginViagensfieldConfig::pode('caixa')) {
            viagensfield_erro('Sem acesso ao caixa.');
        }
        $de = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($_GET['de'] ?? '')) ? substr((string) $_GET['de'], 0, 10) : '';
        $ate = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($_GET['ate'] ?? '')) ? substr((string) $_GET['ate'], 0, 10) : '';
        $totais = PluginViagensfieldCaixa::totais($de, $ate);
        $saldo = PluginViagensfieldCaixa::saldo();
        $minimo = PluginViagensfieldConfig::valorMinimoAlerta();
        viagensfield_json([
            'success'    => true,
            'saldo'      => PluginViagensfieldConfig::moeda($saldo),
            'saldo_baixo' => $saldo < 0 || ($minimo > 0 && $saldo < $minimo),
            'minimo'     => $minimo > 0 ? PluginViagensfieldConfig::moeda($minimo) : '',
            'totais'     => array_map(fn($v) => PluginViagensfieldConfig::moeda($v), $totais),
            'lancamentos' => PluginViagensfieldCaixa::lancamentos([
                'de' => $de, 'ate' => $ate, 'tipo' => (string) ($_GET['tipo'] ?? ''),
            ], 1000),
        ]);

    case 'caixa_movimentar':
        if (!$post) {
            viagensfield_erro('Método não permitido.');
        }
        if (!PluginViagensfieldConfig::pode('caixa')) {
            viagensfield_erro('Sem acesso ao caixa.');
        }
        $tipo = (string) ($_POST['tipo'] ?? '');
        if (!in_array($tipo, ['entrada', 'retirada'], true)) {
            viagensfield_erro('Tipo de lançamento inválido.');
        }
        $valor = PluginViagensfieldConfig::paraNumero($_POST['valor'] ?? '0');
        if ($valor <= 0 || $valor > 999999999) {
            viagensfield_erro('Informe um valor maior que zero.');
        }
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        if ($observacao === '') {
            viagensfield_erro('Descreva o motivo do lançamento.');
        }
        if ($tipo === 'retirada' && PluginViagensfieldConfig::bloquearSemSaldo() && $valor > PluginViagensfieldCaixa::saldo()) {
            viagensfield_erro('A retirada é maior que o saldo disponível (' . PluginViagensfieldConfig::moeda(PluginViagensfieldCaixa::saldo()) . ').');
        }
        $id = PluginViagensfieldCaixa::movimentar($tipo, $valor, ['observacao' => $observacao]);
        if ($id <= 0) {
            viagensfield_erro('Não foi possível registrar o lançamento.');
        }
        viagensfield_json([
            'success'  => true,
            'mensagem' => ($tipo === 'entrada' ? 'Entrada' : 'Retirada') . ' de ' . PluginViagensfieldConfig::moeda($valor) . ' registrada.',
            'saldo'    => PluginViagensfieldConfig::moeda(PluginViagensfieldCaixa::saldo()),
        ]);

    // ------------------------------------------------ comprovantes (envio em partes de 1 MB)
    case 'comprovante_iniciar':
    case 'comprovante_parte':
    case 'comprovante_concluir':
    case 'comprovante_cancelar':
        if (!$post) {
            viagensfield_erro('Método não permitido.');
        }
        if (!PluginViagensfieldConfig::pode('registrar')) {
            viagensfield_erro('Sem permissão para anexar comprovantes.');
        }
        $id = (string) ($_POST['id'] ?? '');
        if ($acao === 'comprovante_iniciar') {
            [$novo, $erro] = PluginViagensfieldComprovante::iniciar((string) ($_POST['nome'] ?? ''), (int) ($_POST['tamanho'] ?? 0));
            if ($novo === null) {
                viagensfield_erro($erro);
            }
            viagensfield_json(['success' => true, 'id' => $novo]);
        }
        if ($acao === 'comprovante_parte') {
            $erro = PluginViagensfieldComprovante::gravarParte($id, (int) ($_POST['indice'] ?? -1), (array) ($_FILES['parte'] ?? []));
            if ($erro !== '') {
                viagensfield_erro($erro);
            }
            viagensfield_json(['success' => true]);
        }
        if ($acao === 'comprovante_concluir') {
            [$arquivo, $erro] = PluginViagensfieldComprovante::concluir($id);
            if ($arquivo === null) {
                viagensfield_erro($erro);
            }
            viagensfield_json(['success' => true, 'arquivo' => $arquivo]);
        }
        PluginViagensfieldComprovante::cancelar($id);
        viagensfield_json(['success' => true]);

    default:
        viagensfield_erro('Ação desconhecida.');
}
