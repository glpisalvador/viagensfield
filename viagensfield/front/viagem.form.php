<?php

/**
 * Plugin Viagens Field - formulário nativo da viagem (adicionar, salvar, excluir)
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

$viagem = new PluginViagensfieldViagem();

if (isset($_POST['add'])) {
    $viagem->check(-1, CREATE, $_POST);
    // A mensagem de sucesso é a nativa do GLPI
    $viagem->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $viagem->check((int) $_POST['id'], UPDATE);
    $viagem->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $viagem->check((int) $_POST['id'], PURGE);
    $itemtype = (string) $viagem->fields['itemtype'];
    $items_id = (int) $viagem->fields['items_id'];
    if ($viagem->delete($_POST, true)) {
        Session::addMessageAfterRedirect('Viagem excluída e valor estornado ao caixa.', true, INFO);
    }
    $destino = PluginViagensfieldConfig::urlItem($itemtype, $items_id);
    Html::redirect($destino !== '' && PluginViagensfieldViagem::itemVinculado($itemtype, $items_id) !== null
        ? $destino . '&forcetab=' . rawurlencode(PluginViagensfieldViagem::class . '$1')
        : PluginViagensfieldViagem::getSearchURL());
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(PluginViagensfieldViagem::getSearchURL());
}
$viagem->check($id, READ);

Html::header(PluginViagensfieldViagem::getTypeName(1),$_SERVER['PHP_SELF'], 'tools', 'PluginViagensfieldMenu', 'painel');
$viagem->display(['id' => $id]);
Html::footer();
