<?php

/**
 * Plugin Viagens Field - item "Viagens" no menu Ferramentas
 */
class PluginViagensfieldMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Viagens';
    }

    public static function getMenuName(): string
    {
        return 'Viagens';
    }

    public static function getIcon(): string
    {
        return 'ti ti-car';
    }

    public static function canView(): bool
    {
        return PluginViagensfieldConfig::podeAlgumPainel();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }

        $painel = '/plugins/viagensfield/front/painel.php';
        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $painel,
            'icon'    => self::getIcon(),
            'links'   => ['search' => $painel],
            'options' => [
                'painel' => [
                    'title' => 'Painel',
                    'page'  => $painel,
                    'icon'  => 'ti ti-chart-bar',
                    'links' => ['search' => $painel],
                ],
            ],
        ];

        if (PluginViagensfieldConfig::ehAdmin()) {
            $config = '/plugins/viagensfield/front/config.form.php';
            $menu['links']['config'] = $config;
            $menu['options']['config'] = [
                'title' => 'Configuração',
                'page'  => $config,
                'icon'  => 'ti ti-settings',
                'links' => ['search' => $config],
            ];
        }

        return $menu;
    }
}
