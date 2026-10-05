<?php

/**
 * Plugin Viagens Field - instalação e opções de busca nos itens
 */

function plugin_viagensfield_install(): bool
{
    global $DB;

    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    if (!$DB->tableExists('glpi_plugin_viagensfield_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_viagensfield_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }

    // Viagens de campo
    if (!$DB->tableExists('glpi_plugin_viagensfield_viagens')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_viagensfield_viagens` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `item_titulo` varchar(255) NOT NULL DEFAULT '',
            `data_hora` timestamp NULL DEFAULT NULL,
            `users_id_tecnico` int unsigned NOT NULL DEFAULT 0,
            `users_id_requerente` int unsigned NOT NULL DEFAULT 0,
            `requerente_email` varchar(255) NOT NULL DEFAULT '',
            `entities_id_origem` int unsigned NOT NULL DEFAULT 0,
            `entities_id_destino` int unsigned NOT NULL DEFAULT 0,
            `meio` varchar(100) NOT NULL DEFAULT '',
            `km` decimal(10,1) NOT NULL DEFAULT 0.0,
            `custo` decimal(12,2) NOT NULL DEFAULT 0.00,
            `status` varchar(20) NOT NULL DEFAULT 'efetuada',
            `comment` longtext,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `users_id_tecnico` (`users_id_tecnico`),
            KEY `data_hora` (`data_hora`),
            KEY `status` (`status`)
        ) $opcoes");
    }

    // Livro-caixa global: entradas, retiradas, despesas de viagem e estornos
    if (!$DB->tableExists('glpi_plugin_viagensfield_caixas')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_viagensfield_caixas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tipo` varchar(20) NOT NULL DEFAULT 'entrada',
            `valor` decimal(12,2) NOT NULL DEFAULT 0.00,
            `saldo_anterior` decimal(12,2) NOT NULL DEFAULT 0.00,
            `saldo_posterior` decimal(12,2) NOT NULL DEFAULT 0.00,
            `plugin_viagensfield_viagens_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `observacao` text,
            `data_hora` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tipo` (`tipo`),
            KEY `viagem` (`plugin_viagensfield_viagens_id`),
            KEY `entities_id` (`entities_id`),
            KEY `data_hora` (`data_hora`)
        ) $opcoes");
    }

    $padroes = [
        'registrar_profiles' => json_encode([]),
        'registrar_users'    => json_encode([]),
        'relatorio_profiles' => json_encode([]),
        'relatorio_users'    => json_encode([]),
        'caixa_profiles'     => json_encode([]),
        'caixa_users'        => json_encode([]),
        'valor_minimo_alerta' => '500.00',
        'emails_alerta'      => '',
        'bloquear_sem_saldo' => '1',
        'entidades_filhas'   => '0',
        'meios'              => json_encode(['Aplicativo de transporte', 'Táxi', 'Veículo da empresa', 'Veículo próprio', 'Transporte público', 'Outro']),
    ];
    foreach ($padroes as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_viagensfield_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_viagensfield_configs', ['name' => $nome, 'value' => $valor]);
        }
    }

    // Pasta temporária dos comprovantes enviados em partes
    $pasta = GLPI_PLUGIN_DOC_DIR . '/viagensfield/envio';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0755, true);
    }

    return true;
}

function plugin_viagensfield_uninstall(): bool
{
    // As tabelas e os dados do plugin são mantidos: reinstalar recupera tudo.
    return true;
}

/**
 * Colunas nativas na busca de Chamados, Mudanças, Problemas e Projetos:
 * "Custo de viagens" (soma sem as canceladas) e "Viagens" (quantidade).
 * Aparecem em "Selecionar colunas" da lista, com ordenação e filtro do próprio GLPI.
 */
function plugin_viagensfield_getAddSearchOptionsNew($itemtype): array
{
    global $DB;

    if (!class_exists('PluginViagensfieldConfig')
        || !in_array($itemtype, PluginViagensfieldConfig::ITEMTYPES, true)
        || !PluginViagensfieldConfig::pode('relatorio')) {
        return [];
    }

    $tabela = 'glpi_plugin_viagensfield_viagens';
    $juncao = [
        'jointype'  => 'itemtype_item',
        'condition' => ['NEWTABLE.status' => ['<>', 'cancelada']],
    ];

    return [
        [
            'id'            => '76100',
            'table'         => $tabela,
            'field'         => 'custo',
            'name'          => 'Custo de viagens',
            'datatype'      => 'decimal',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'nometa'        => true,
            'joinparams'    => $juncao,
            // Mesma técnica do "Custo total" nativo: não soma duas vezes quando há outras junções
            'computation'   => new \Glpi\DBAL\QueryExpression(
                '(SUM(' . $DB::quoteName('TABLE.custo') . ') / COUNT(' . $DB::quoteName('TABLE.id') . ')) * COUNT(DISTINCT ' . $DB::quoteName('TABLE.id') . ')'
            ),
        ],
        [
            'id'            => '76101',
            'table'         => $tabela,
            'field'         => 'id',
            'name'          => 'Viagens',
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'nometa'        => true,
            'joinparams'    => $juncao,
        ],
    ];
}
