<?php

/**
 * Plugin Viagens Field para GLPI 11 e 12
 * Viagens de campo dos técnicos (ligadas a Chamados, Mudanças, Problemas e Projetos),
 * livro-caixa global, relatórios e comprovantes.
 */

define('PLUGIN_VIAGENSFIELD_VERSION', '1.0.1');
define('PLUGIN_VIAGENSFIELD_MIN_GLPI', '11.0.0');
define('PLUGIN_VIAGENSFIELD_MAX_GLPI', '12.99.99');

function plugin_init_viagensfield(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['viagensfield'] = true;

    if (!Plugin::isPluginActive('viagensfield')) {
        return;
    }

    // Tabela <-> classe da viagem (o plural automático do GLPI seria "viagems")
    $CFG_GLPI['glpitablesitemtype']['PluginViagensfieldViagem'] = 'glpi_plugin_viagensfield_viagens';
    $CFG_GLPI['glpiitemtypetables']['glpi_plugin_viagensfield_viagens'] = 'PluginViagensfieldViagem';

    Plugin::registerClass('PluginViagensfieldConfig');
    Plugin::registerClass('PluginViagensfieldMenu');
    Plugin::registerClass('PluginViagensfieldCaixa');
    Plugin::registerClass('PluginViagensfieldRelatorio');
    Plugin::registerClass('PluginViagensfieldComprovante');
    // Aba "Viagens" nos itens e comprovantes como documentos nativos
    Plugin::registerClass('PluginViagensfieldViagem', [
        'addtabon'       => PluginViagensfieldConfig::ITEMTYPES,
        'document_types' => true,
    ]);

    $PLUGIN_HOOKS['show_count_on_tabs']['viagensfield'] = true;

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['viagensfield'] = 'front/config.php';
    }

    $PLUGIN_HOOKS['menu_toadd']['viagensfield'] = ['tools' => 'PluginViagensfieldMenu'];
}

function plugin_version_viagensfield(): array
{
    return [
        'name'         => 'Viagens Field',
        'version'      => PLUGIN_VIAGENSFIELD_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_VIAGENSFIELD_MIN_GLPI,
                'max' => PLUGIN_VIAGENSFIELD_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_viagensfield_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_VIAGENSFIELD_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI ' . PLUGIN_VIAGENSFIELD_MIN_GLPI . ' ou superior.';
        return false;
    }
    return true;
}

function plugin_viagensfield_check_config($verbose = false): bool
{
    return true;
}
