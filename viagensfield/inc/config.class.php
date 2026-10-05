<?php

/**
 * Plugin Viagens Field - configurações, permissões e utilitários comuns
 */
class PluginViagensfieldConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    /** Itens que recebem a aba "Viagens" */
    public const ITEMTYPES = ['Ticket', 'Change', 'Problem', 'Project'];

    /** Áreas com permissão própria (perfis/usuários) */
    public const AREAS = [
        'registrar' => 'Registrar viagens',
        'relatorio' => 'Painel e relatórios',
        'caixa'     => 'Caixa (entradas e retiradas)',
    ];

    public const STATUS = [
        'pendente'  => 'Pendente',
        'efetuada'  => 'Efetuada',
        'cancelada' => 'Cancelada',
    ];

    public const MEIOS_PADRAO = ['Aplicativo de transporte', 'Táxi', 'Veículo da empresa', 'Veículo próprio', 'Transporte público', 'Outro'];

    public static function getTypeName($nb = 0): string
    {
        return 'Viagens Field';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function ehAdmin(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    public static function campoCsrf(): string
    {
        $token = self::tokenCsrf();
        return $token === '' ? '' : '<input type="hidden" name="_glpi_csrf_token" value="' . self::e($token) . '">';
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_plugin_viagensfield_configs',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]) as $row) {
            return $row['value'];
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => 'glpi_plugin_viagensfield_configs', 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            return $DB->update('glpi_plugin_viagensfield_configs', ['value' => $value], ['name' => $name]);
        }
        return $DB->insert('glpi_plugin_viagensfield_configs', ['name' => $name, 'value' => $value]);
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_viagensfield_configs']) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name, '[]'), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    public static function idsConfig(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    // =====================================================================
    // Opções
    // =====================================================================

    public static function valorMinimoAlerta(): float
    {
        return (float) self::getConfig('valor_minimo_alerta', '0');
    }

    public static function emailsAlerta(): array
    {
        $lista = preg_split('/[\s,;]+/', (string) self::getConfig('emails_alerta', '')) ?: [];
        return array_values(array_unique(array_filter($lista, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    public static function bloquearSemSaldo(): bool
    {
        return self::getConfig('bloquear_sem_saldo', '1') === '1';
    }

    public static function mostrarEntidadesFilhas(): bool
    {
        return self::getConfig('entidades_filhas', '0') === '1';
    }

    public static function meios(): array
    {
        $lista = array_values(array_filter(array_map('trim', self::getArrayConfig('meios')), fn($m) => $m !== ''));
        return $lista ?: self::MEIOS_PADRAO;
    }

    // =====================================================================
    // Permissões
    // =====================================================================

    /**
     * Acesso por área. Administradores (Configurar > Atualizar) sempre podem.
     * "registrar" sem ninguém marcado vale para todos que enxergam o item;
     * "relatorio" e "caixa" sem ninguém marcado ficam só para administradores.
     */
    public static function pode(string $area): bool
    {
        if (!isset(self::AREAS[$area]) || !Session::getLoginUserID()) {
            return false;
        }
        if (self::ehAdmin()) {
            return true;
        }
        $perfis   = self::idsConfig($area . '_profiles');
        $usuarios = self::idsConfig($area . '_users');
        if ($area === 'registrar' && !$perfis && !$usuarios) {
            return true;
        }
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($perfil, $perfis, true) || in_array((int) Session::getLoginUserID(), $usuarios, true);
    }

    public static function podeAlgumPainel(): bool
    {
        return self::pode('relatorio') || self::pode('caixa');
    }

    // =====================================================================
    // Valores e textos
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    /** "1.234,56", "1234.56" ou "R$ 12" -> float */
    public static function paraNumero($valor): float
    {
        if (is_int($valor) || is_float($valor)) {
            return round((float) $valor, 2);
        }
        $texto = preg_replace('/[^\d,.\-]/', '', (string) $valor);
        if ($texto === '' || $texto === '-') {
            return 0.0;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif (substr_count($texto, '.') > 1) {
            $texto = str_replace('.', '', $texto);
        }
        return round((float) $texto, 2);
    }

    public static function moeda($valor, bool $simbolo = true): string
    {
        return ($simbolo ? 'R$ ' : '') . number_format((float) $valor, 2, ',', '.');
    }

    public static function km($valor): string
    {
        return number_format((float) $valor, 1, ',', '.');
    }

    public static function nomeStatus(string $status): string
    {
        return self::STATUS[$status] ?? $status;
    }

    /** Selo de status nas cores leves do tema */
    public static function seloStatus(string $status): string
    {
        return '<span class="viagensfield-status viagensfield-status-' . self::e($status) . '">' . self::e(self::nomeStatus($status)) . '</span>';
    }

    public static function richtextVazio(?string $valor): bool
    {
        return $valor === null || trim(strip_tags(str_replace('&nbsp;', ' ', $valor))) === '';
    }

    public static function nomeUsuario(int $users_id): string
    {
        static $cache = [];
        if ($users_id <= 0) {
            return '';
        }
        if (!isset($cache[$users_id])) {
            global $DB;
            $cache[$users_id] = '';
            foreach ($DB->request([
                'SELECT' => ['name', 'firstname', 'realname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $users_id],
                'LIMIT'  => 1,
            ]) as $u) {
                $cache[$users_id] = self::montarNome($u);
            }
        }
        return $cache[$users_id];
    }

    public static function montarNome(array $u): string
    {
        $partes = array_filter([trim((string) ($u['firstname'] ?? '')), trim((string) ($u['realname'] ?? ''))]);
        return $partes ? implode(' ', $partes) : (string) ($u['name'] ?? '');
    }

    public static function nomeEntidade(int $entities_id): string
    {
        static $cache = [];
        if (!isset($cache[$entities_id])) {
            $cache[$entities_id] = (string) Dropdown::getDropdownName('glpi_entities', $entities_id);
        }
        return $cache[$entities_id];
    }

    /** Só o nome da entidade (sem a hierarquia), para listas e gráficos */
    public static function nomeEntidadeCurto(int $entities_id): string
    {
        static $cache = [];
        if (!isset($cache[$entities_id])) {
            global $DB;
            $cache[$entities_id] = '';
            foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entities_id], 'LIMIT' => 1]) as $r) {
                $cache[$entities_id] = (string) $r['name'];
            }
            if ($cache[$entities_id] === '') {
                $cache[$entities_id] = self::nomeEntidade($entities_id);
            }
        }
        return $cache[$entities_id];
    }

    // =====================================================================
    // Listas para seletores
    // =====================================================================

    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = (string) $r['name'];
        }
        return $lista;
    }

    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
        ]) as $u) {
            $nome = self::montarNome($u);
            $lista[(int) $u['id']] = $nome . ($nome !== $u['name'] ? ' (' . $u['name'] . ')' : '');
        }
        return $lista;
    }

    /** IDs das entidades filhas (entities_id > 0) */
    public static function idsEntidadesFilhas(): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /**
     * Entidades para Origem/Destino: as que o usuário enxerga; as filhas ficam
     * escondidas a menos que a opção "Mostrar entidades filhas" esteja ligada.
     * $manter garante que um valor já gravado continue aparecendo na edição.
     */
    public static function entidadesOrigemDestino(array $manter = []): array
    {
        global $DB;
        $where = [];
        $ativas = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        if ($ativas) {
            $where['id'] = $ativas;
        }
        if (!self::mostrarEntidadesFilhas()) {
            $filhas = self::idsEntidadesFilhas();
            if ($filhas) {
                $where[] = ['NOT' => ['id' => $filhas]];
            }
        }
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'completename'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => $where,
            'ORDER'  => 'completename ASC',
        ]) as $r) {
            $lista[(int) $r['id']] = (string) ($r['completename'] ?: $r['name']);
        }
        foreach (array_filter(array_map('intval', $manter), fn($id) => $id >= 0) as $id) {
            if (!isset($lista[$id])) {
                $lista[$id] = self::nomeEntidade($id);
            }
        }
        return $lista;
    }

    // =====================================================================
    // Itens vinculados
    // =====================================================================

    public static function nomeItemtype(string $itemtype, int $nb = 1): string
    {
        return match ($itemtype) {
            'Ticket'  => $nb > 1 ? 'Chamados' : 'Chamado',
            'Change'  => $nb > 1 ? 'Mudanças' : 'Mudança',
            'Problem' => $nb > 1 ? 'Problemas' : 'Problema',
            'Project' => $nb > 1 ? 'Projetos' : 'Projeto',
            default   => $itemtype,
        };
    }

    public static function iconeItemtype(string $itemtype): string
    {
        return match ($itemtype) {
            'Ticket'  => 'ti ti-alert-circle',
            'Change'  => 'ti ti-clipboard-check',
            'Problem' => 'ti ti-alert-triangle',
            'Project' => 'ti ti-briefcase',
            default   => 'ti ti-link',
        };
    }

    public static function urlItem(string $itemtype, int $items_id): string
    {
        if (!in_array($itemtype, self::ITEMTYPES, true) || $items_id <= 0) {
            return '';
        }
        return $itemtype::getFormURLWithID($items_id);
    }

    /** Link "Chamado #12 - título" */
    public static function linkItem(string $itemtype, int $items_id, string $titulo = ''): string
    {
        $rotulo = self::nomeItemtype($itemtype) . ' #' . $items_id . ($titulo !== '' ? ' - ' . $titulo : '');
        $url = self::urlItem($itemtype, $items_id);
        if ($url === '') {
            return self::e($rotulo);
        }
        return '<a href="' . self::e($url) . '"><i class="' . self::iconeItemtype($itemtype) . ' me-1"></i>' . self::e($rotulo) . '</a>';
    }

    /** Tabela de atores (usuários) de cada tipo ITIL */
    private static function tabelaAtores(string $itemtype): array
    {
        return match ($itemtype) {
            'Ticket'  => ['glpi_tickets_users', 'tickets_id'],
            'Change'  => ['glpi_changes_users', 'changes_id'],
            'Problem' => ['glpi_problems_users', 'problems_id'],
            default   => ['', ''],
        };
    }

    /**
     * Requerentes do item: ['u:ID' => nome] para usuários e ['e:email' => email]
     * para requerentes só por e-mail. No Projeto, o responsável faz esse papel.
     */
    public static function requerentesDoItem(string $itemtype, int $items_id): array
    {
        global $DB;
        $lista = [];
        if ($itemtype === 'Project') {
            $projeto = new Project();
            if ($projeto->getFromDB($items_id) && (int) $projeto->fields['users_id'] > 0) {
                $lista['u:' . (int) $projeto->fields['users_id']] = self::nomeUsuario((int) $projeto->fields['users_id']);
            }
            return $lista;
        }
        [$tabela, $campo] = self::tabelaAtores($itemtype);
        if ($tabela === '') {
            return $lista;
        }
        foreach ($DB->request([
            'SELECT'    => ['iu.users_id', 'iu.alternative_email', 'u.name', 'u.firstname', 'u.realname'],
            'FROM'      => $tabela . ' AS iu',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'iu' => 'users_id']]],
            'WHERE'     => ['iu.' . $campo => $items_id, 'iu.type' => CommonITILActor::REQUESTER],
        ]) as $r) {
            $uid = (int) $r['users_id'];
            $email = trim((string) $r['alternative_email']);
            if ($uid > 0 && $r['name'] !== null) {
                $lista['u:' . $uid] = self::montarNome($r);
            } elseif ($email !== '') {
                $lista['e:' . $email] = $email;
            }
        }
        return $lista;
    }

    /** Técnicos atribuídos ao item (no Projeto, os membros da equipe) */
    public static function tecnicosDoItem(string $itemtype, int $items_id): array
    {
        global $DB;
        $ids = [];
        if ($itemtype === 'Project') {
            foreach ($DB->request([
                'SELECT' => ['items_id'],
                'FROM'   => 'glpi_projectteams',
                'WHERE'  => ['projects_id' => $items_id, 'itemtype' => 'User'],
            ]) as $r) {
                $ids[] = (int) $r['items_id'];
            }
            return $ids;
        }
        [$tabela, $campo] = self::tabelaAtores($itemtype);
        if ($tabela === '') {
            return $ids;
        }
        foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => $tabela,
            'WHERE'  => [$campo => $items_id, 'type' => CommonITILActor::ASSIGN, 'users_id' => ['>', 0]],
        ]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        return $ids;
    }

    /**
     * URL de um arquivo de public/ com versão (evita cache antigo depois de atualizar).
     * O GLPI 11/12 serve a pasta public/ do plugin direto em /plugins/<nome>/.
     */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $caminho = preg_replace('#^public/#', '', $caminho);
        return $CFG_GLPI['root_doc'] . '/plugins/viagensfield/' . $caminho . '?v=' . PLUGIN_VIAGENSFIELD_VERSION;
    }

    /** Renderiza o multiselect com pesquisa usado na configuração */
    public static function renderMultiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...'): string
    {
        $selecionados = array_map('intval', $selecionados);
        $marcados = [];
        $demais = [];
        foreach ($opcoes as $id => $rotulo) {
            if (in_array((int) $id, $selecionados, true)) {
                $marcados[$id] = $rotulo;
            } else {
                $demais[$id] = $rotulo;
            }
        }
        asort($marcados, SORT_NATURAL | SORT_FLAG_CASE);
        asort($demais, SORT_NATURAL | SORT_FLAG_CASE);
        $ordenadas = $marcados + $demais;

        $h = '<div class="viagensfield-ms" data-viagensfield-ms data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="0">';
        $h .= '<button type="button" class="viagensfield-ms-cabecalho form-select form-select-sm" data-viagensfield-ms-abrir>'
            . '<span class="viagensfield-ms-texto"></span></button>';
        $h .= '<div class="viagensfield-ms-dropdown" hidden>';
        $h .= '<div class="viagensfield-ms-topo"><input type="text" class="form-control form-control-sm viagensfield-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="viagensfield-ms-todos"><input type="checkbox" class="viagensfield-check" data-viagensfield-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="viagensfield-ms-opcoes">';
        foreach ($ordenadas as $id => $rotulo) {
            $marcado = in_array((int) $id, $selecionados, true);
            $h .= '<label class="viagensfield-ms-opcao' . ($marcado ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower((string) $rotulo)) . '">'
                . '<input type="checkbox" class="viagensfield-check" name="' . self::e($name) . '[]" value="' . (int) $id . '"' . ($marcado ? ' checked' : '') . '>'
                . '<span>' . self::e($rotulo) . '</span></label>';
        }
        $h .= '</div></div>';
        $h .= '<div class="viagensfield-ms-contador"></div>';
        $h .= '</div>';
        return $h;
    }
}
