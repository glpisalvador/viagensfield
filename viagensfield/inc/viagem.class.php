<?php

/**
 * Plugin Viagens Field - viagem de campo ligada a um Chamado, Mudança, Problema ou Projeto.
 * Formulário nativo do GLPI, histórico, aba Documentos (comprovantes) e integração com o caixa:
 * criar debita; alterar custo ou status ajusta; cancelar ou excluir estorna.
 */
class PluginViagensfieldViagem extends CommonDBTM
{
    // $rightname e $dohistory não são redeclaradas (tipos diferentes no GLPI 11 e 12)

    public function __construct()
    {
        parent::__construct();
        $this->dohistory = true;
    }

    public const TABELA = 'glpi_plugin_viagensfield_viagens';

    /** O plural automático do GLPI geraria "viagems" */
    public static function getTable($classname = null)
    {
        if ($classname === null || $classname === self::class) {
            return self::TABELA;
        }
        return parent::getTable($classname);
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Viagens' : 'Viagem';
    }

    public static function getIcon(): string
    {
        return 'ti ti-car';
    }

    public static function canView(): bool
    {
        return PluginViagensfieldConfig::pode('registrar') || PluginViagensfieldConfig::pode('relatorio');
    }

    public static function canCreate(): bool
    {
        return PluginViagensfieldConfig::pode('registrar');
    }

    public static function canUpdate(): bool
    {
        return PluginViagensfieldConfig::pode('registrar');
    }

    public static function canDelete(): bool
    {
        return false;
    }

    /** Excluir (com estorno) fica com administradores e com quem cuida do caixa */
    public static function canPurge(): bool
    {
        return PluginViagensfieldConfig::pode('registrar')
            && (PluginViagensfieldConfig::ehAdmin() || PluginViagensfieldConfig::pode('caixa'));
    }

    public function canViewItem(): bool
    {
        if (!parent::canViewItem()) {
            return false;
        }
        $item = self::itemVinculado((string) ($this->fields['itemtype'] ?? ''), (int) ($this->fields['items_id'] ?? 0));
        // Item vinculado já excluído: a viagem continua visível para quem tem o painel
        return $item === null ? PluginViagensfieldConfig::pode('relatorio') : $item->canViewItem();
    }

    public function getName($options = []): string
    {
        if (empty($this->fields['id'])) {
            return self::getTypeName(1);
        }
        // O GLPI já mostra "Viagem - ... - ID n" no cabeçalho: aqui basta a data
        return (string) Html::convDateTime($this->fields['data_hora']);
    }

    /** A "lista" das viagens é o painel do plugin */
    public static function getSearchURL($full = true): string
    {
        global $CFG_GLPI;
        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/viagensfield/front/painel.php';
    }

    public function defineTabs($options = []): array
    {
        $abas = [];
        $this->addDefaultFormTab($abas);
        $this->addStandardTab('Document_Item', $abas, $options);
        $this->addStandardTab('Log', $abas, $options);
        return $abas;
    }

    /** Item ITIL/Projeto vinculado, se existir */
    public static function itemVinculado(string $itemtype, int $items_id): ?CommonDBTM
    {
        if (!in_array($itemtype, PluginViagensfieldConfig::ITEMTYPES, true) || $items_id <= 0) {
            return null;
        }
        $item = new $itemtype();
        return $item->getFromDB($items_id) ? $item : null;
    }

    /** Débito efetivo no caixa: canceladas não custam nada */
    public static function debito(string $status, $custo): float
    {
        return $status === 'cancelada' ? 0.0 : round((float) $custo, 2);
    }

    // =====================================================================
    // Aba nos itens
    // =====================================================================

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!in_array($item::class, PluginViagensfieldConfig::ITEMTYPES, true)
            || !($item instanceof CommonDBTM) || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $nb = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $nb = self::contarDoItem($item::class, (int) $item->getID());
        }
        return self::createTabEntry('Viagens', $nb, self::class);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof CommonDBTM && in_array($item::class, PluginViagensfieldConfig::ITEMTYPES, true)) {
            self::mostrarNoItem($item);
        }
        return true;
    }

    public static function contarDoItem(string $itemtype, int $items_id): int
    {
        global $DB;
        return count($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ]));
    }

    public static function viagensDoItem(string $itemtype, int $items_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => ['data_hora DESC', 'id DESC'],
        ]) as $r) {
            $lista[] = $r;
        }
        return $lista;
    }

    public static function mostrarNoItem(CommonDBTM $item): void
    {
        global $CFG_GLPI;
        $e = [PluginViagensfieldConfig::class, 'e'];
        $itemtype = $item::class;
        $items_id = (int) $item->getID();

        echo '<link rel="stylesheet" href="' . $e(PluginViagensfieldConfig::urlAsset('public/css/viagensfield.css')) . '">';
        echo '<script src="' . $e(PluginViagensfieldConfig::urlAsset('public/js/viagem.js')) . '"></script>';

        $viagens = self::viagensDoItem($itemtype, $items_id);
        $total = 0.0;
        $km = 0.0;
        foreach ($viagens as $v) {
            $total += self::debito((string) $v['status'], $v['custo']);
            if ($v['status'] !== 'cancelada') {
                $km += (float) $v['km'];
            }
        }
        $docs = PluginViagensfieldComprovante::documentosPorViagem(array_column($viagens, 'id'));

        echo '<div class="viagensfield-aba">';

        // Resumo
        echo '<div class="viagensfield-resumo">';
        echo '<div class="viagensfield-resumo-item"><span class="viagensfield-resumo-rotulo">Viagens</span><span class="viagensfield-resumo-valor">' . count($viagens) . '</span></div>';
        echo '<div class="viagensfield-resumo-item"><span class="viagensfield-resumo-rotulo">Custo total</span><span class="viagensfield-resumo-valor">' . $e(PluginViagensfieldConfig::moeda($total)) . '</span></div>';
        echo '<div class="viagensfield-resumo-item"><span class="viagensfield-resumo-rotulo">Distância</span><span class="viagensfield-resumo-valor">' . $e(PluginViagensfieldConfig::km($km)) . ' km</span></div>';
        if (PluginViagensfieldConfig::pode('registrar') || PluginViagensfieldConfig::pode('caixa')) {
            $saldo = PluginViagensfieldCaixa::saldo();
            $baixo = PluginViagensfieldConfig::valorMinimoAlerta() > 0 && $saldo < PluginViagensfieldConfig::valorMinimoAlerta();
            echo '<div class="viagensfield-resumo-item"><span class="viagensfield-resumo-rotulo">Saldo do caixa</span><span class="viagensfield-resumo-valor' . ($saldo < 0 || $baixo ? ' viagensfield-negativo' : '') . '">'
                . $e(PluginViagensfieldConfig::moeda($saldo)) . '</span></div>';
        }
        echo '<div class="viagensfield-resumo-acoes">';
        if (self::canCreate() && $item->canViewItem()) {
            echo '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#viagensfield-nova" aria-expanded="false">'
                . '<i class="ti ti-plus me-1"></i>Registrar viagem</button>';
        }
        if (PluginViagensfieldConfig::podeAlgumPainel()) {
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e($CFG_GLPI['root_doc'] . '/plugins/viagensfield/front/painel.php') . '"><i class="ti ti-chart-bar me-1"></i>Painel</a>';
        }
        echo '</div></div>';

        // Formulário de nova viagem (recolhido)
        if (self::canCreate() && $item->canViewItem()) {
            echo '<div class="collapse viagensfield-nova" id="viagensfield-nova">';
            $viagem = new self();
            $viagem->showForm(-1, ['itemtype' => $itemtype, 'items_id' => $items_id]);
            echo '</div>';
        }

        // Lista
        if (!$viagens) {
            echo '<div class="viagensfield-vazio"><i class="ti ti-car"></i><span>Nenhuma viagem registrada para este '
                . $e(mb_strtolower(PluginViagensfieldConfig::nomeItemtype($itemtype))) . '.</span></div>';
            echo '</div>';
            return;
        }

        echo '<div class="table-responsive viagensfield-tabela-wrap">';
        echo '<table class="table table-hover table-sm viagensfield-tabela">';
        echo '<thead><tr><th>Viagem</th><th>Data</th><th>Técnico</th><th>Trajeto</th><th>Meio</th><th class="text-end">Km</th><th class="text-end">Custo</th><th>Status</th><th class="text-center">Comprovantes</th></tr></thead><tbody>';
        foreach ($viagens as $v) {
            $id = (int) $v['id'];
            $url = self::getFormURLWithID($id);
            echo '<tr' . ($v['status'] === 'cancelada' ? ' class="viagensfield-linha-cancelada"' : '') . '>';
            echo '<td><a href="' . $e($url) . '">#' . $id . '</a></td>';
            echo '<td class="text-nowrap">' . $e(Html::convDateTime($v['data_hora'])) . '</td>';
            echo '<td>' . $e(PluginViagensfieldConfig::nomeUsuario((int) $v['users_id_tecnico'])) . '</td>';
            echo '<td>' . self::trajetoHtml((int) $v['entities_id_origem'], (int) $v['entities_id_destino']) . '</td>';
            echo '<td>' . $e($v['meio']) . '</td>';
            echo '<td class="text-end">' . $e(PluginViagensfieldConfig::km($v['km'])) . '</td>';
            echo '<td class="text-end text-nowrap">' . $e(PluginViagensfieldConfig::moeda($v['custo'])) . '</td>';
            echo '<td>' . PluginViagensfieldConfig::seloStatus((string) $v['status']) . '</td>';
            echo '<td class="text-center">' . PluginViagensfieldComprovante::linksHtml($docs[$id] ?? []) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '<tfoot><tr><td colspan="5">Total (sem as canceladas)</td><td class="text-end">' . $e(PluginViagensfieldConfig::km($km)) . '</td><td class="text-end text-nowrap">'
            . $e(PluginViagensfieldConfig::moeda($total)) . '</td><td colspan="2"></td></tr></tfoot>';
        echo '</table></div>';
        echo '</div>';
    }

    public static function trajetoHtml(int $origem, int $destino): string
    {
        $e = [PluginViagensfieldConfig::class, 'e'];
        $parte = fn(int $id) => '<span title="' . $e(PluginViagensfieldConfig::nomeEntidade($id)) . '">' . $e(PluginViagensfieldConfig::nomeEntidadeCurto($id)) . '</span>';
        return '<span class="viagensfield-trajeto">' . $parte($origem) . '<i class="ti ti-arrow-right"></i>' . $parte($destino) . '</span>';
    }

    // =====================================================================
    // Formulário nativo
    // =====================================================================

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $nova = $this->isNewItem();
        $e = [PluginViagensfieldConfig::class, 'e'];

        if ($nova) {
            $this->fields['itemtype'] = (string) ($options['itemtype'] ?? '');
            $this->fields['items_id'] = (int) ($options['items_id'] ?? 0);
            $this->fields['data_hora'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
            $this->fields['status'] = 'efetuada';
            $tecnicos = PluginViagensfieldConfig::tecnicosDoItem($this->fields['itemtype'], $this->fields['items_id']);
            $eu = (int) Session::getLoginUserID();
            $this->fields['users_id_tecnico'] = in_array($eu, $tecnicos, true) || !$tecnicos ? $eu : $tecnicos[0];
        }
        $itemtype = (string) $this->fields['itemtype'];
        $items_id = (int) $this->fields['items_id'];
        $rand = mt_rand();

        if (!$nova) {
            echo '<link rel="stylesheet" href="' . $e(PluginViagensfieldConfig::urlAsset('public/css/viagensfield.css')) . '">';
            echo '<script src="' . $e(PluginViagensfieldConfig::urlAsset('public/js/viagem.js')) . '"></script>';
        }

        $options['colspan'] = 2;
        $this->showFormHeader($options);

        if ($nova) {
            echo '<input type="hidden" name="itemtype" value="' . $e($itemtype) . '">';
            echo '<input type="hidden" name="items_id" value="' . $items_id . '">';
        } else {
            $item = self::itemVinculado($itemtype, $items_id);
            echo '<tr class="tab_bg_1"><td>Vinculada a</td><td colspan="3">'
                . PluginViagensfieldConfig::linkItem($itemtype, $items_id, $item ? (string) $item->fields['name'] : (string) $this->fields['item_titulo'])
                . ($item === null ? ' <span class="text-muted">(item excluído)</span>' : '') . '</td></tr>';
        }

        // Data e hora | Status
        echo '<tr class="tab_bg_1">';
        echo '<td><label for="showdate' . $rand . '">Data e hora</label> <span class="required">*</span></td><td class="viagensfield-campo-data">';
        Html::showDateTimeField('data_hora', ['value' => $this->fields['data_hora'], 'maybeempty' => false, 'rand' => $rand]);
        echo '</td>';
        echo '<td>Status</td><td>';
        Dropdown::showFromArray('status', PluginViagensfieldConfig::STATUS, ['value' => $this->fields['status'] ?: 'efetuada', 'width' => '100%']);
        echo '</td></tr>';

        // Técnico | Requerente
        echo '<tr class="tab_bg_1">';
        echo '<td>Técnico <span class="required">*</span></td><td>';
        User::dropdown([
            'name'   => 'users_id_tecnico',
            'value'  => (int) $this->fields['users_id_tecnico'],
            'right'  => 'all',
            'entity' => $nova ? ($_SESSION['glpiactiveentities'] ?? []) : (int) $this->fields['entities_id'],
            'width'  => '100%',
        ]);
        echo '</td>';
        echo '<td>Requerente</td><td>';
        $requerentes = PluginViagensfieldConfig::requerentesDoItem($itemtype, $items_id);
        $atual = '';
        if ((int) ($this->fields['users_id_requerente'] ?? 0) > 0) {
            $atual = 'u:' . (int) $this->fields['users_id_requerente'];
            $requerentes[$atual] ??= PluginViagensfieldConfig::nomeUsuario((int) $this->fields['users_id_requerente']);
        } elseif (($this->fields['requerente_email'] ?? '') !== '') {
            $atual = 'e:' . $this->fields['requerente_email'];
            $requerentes[$atual] ??= (string) $this->fields['requerente_email'];
        } elseif ($nova && $requerentes) {
            $atual = (string) array_key_first($requerentes);
        }
        Dropdown::showFromArray('_requerente', $requerentes, [
            'value'               => $atual,
            'display_emptychoice' => true,
            'emptylabel'          => $requerentes ? Dropdown::EMPTY_VALUE : 'Nenhum requerente no item',
            'width'               => '100%',
        ]);
        echo '</td></tr>';

        // Origem | Destino
        $origem = $nova ? -1 : (int) $this->fields['entities_id_origem'];
        $destino = $nova ? -1 : (int) $this->fields['entities_id_destino'];
        $entidades = [-1 => Dropdown::EMPTY_VALUE] + PluginViagensfieldConfig::entidadesOrigemDestino([$origem, $destino]);
        echo '<tr class="tab_bg_1">';
        echo '<td>Origem <span class="required">*</span></td><td>';
        Dropdown::showFromArray('entities_id_origem', $entidades, ['value' => $origem, 'width' => '100%']);
        echo '</td>';
        echo '<td>Destino <span class="required">*</span></td><td>';
        Dropdown::showFromArray('entities_id_destino', $entidades, ['value' => $destino, 'width' => '100%']);
        echo '</td></tr>';

        // Meio | Distância
        $meios = PluginViagensfieldConfig::meios();
        $meios = array_combine($meios, $meios);
        if (($this->fields['meio'] ?? '') !== '' && !isset($meios[$this->fields['meio']])) {
            $meios[$this->fields['meio']] = $this->fields['meio'];
        }
        echo '<tr class="tab_bg_1">';
        echo '<td>Meio de transporte</td><td>';
        Dropdown::showFromArray('meio', $meios, ['value' => (string) ($this->fields['meio'] ?? ''), 'display_emptychoice' => true, 'width' => '100%']);
        echo '</td>';
        echo '<td><label for="viagensfield-km' . $rand . '">Distância (km)</label></td><td>';
        echo '<input type="text" inputmode="decimal" class="form-control viagensfield-input-curto" id="viagensfield-km' . $rand . '" name="km" data-viagensfield-km value="'
            . $e(((float) ($this->fields['km'] ?? 0)) > 0 ? PluginViagensfieldConfig::km($this->fields['km']) : '') . '" placeholder="0,0">';
        echo '</td></tr>';

        // Custo
        echo '<tr class="tab_bg_1">';
        echo '<td><label for="viagensfield-custo' . $rand . '">Custo</label> <span class="required">*</span></td><td>';
        echo '<div class="input-group viagensfield-input-curto"><span class="input-group-text">R$</span>'
            . '<input type="text" inputmode="decimal" class="form-control" id="viagensfield-custo' . $rand . '" name="custo" data-viagensfield-moeda value="'
            . $e($nova ? '' : PluginViagensfieldConfig::moeda($this->fields['custo'], false)) . '" placeholder="0,00" required></div>';
        echo '</td>';
        echo '<td></td><td></td></tr>';

        // Observações (texto rico)
        echo '<tr class="tab_bg_1"><td>Observações</td><td colspan="3">';
        Html::textarea([
            'name'            => 'comment',
            'value'           => (string) ($this->fields['comment'] ?? ''),
            'enable_richtext' => true,
            'cols'            => 100,
            'rows'            => 5,
            'rand'            => $rand,
        ]);
        echo '</td></tr>';

        // Comprovantes: fotos ou documentos, enviados em partes pelo ajax.php
        global $CFG_GLPI;
        $existentes = $nova ? [] : (PluginViagensfieldComprovante::documentosPorViagem([(int) $this->fields['id']])[(int) $this->fields['id']] ?? []);
        echo '<tr class="tab_bg_1"><td>Comprovantes</td><td colspan="3">';
        echo '<div class="viagensfield-comprovantes" data-viagensfield-comprovantes'
            . ' data-ajax="' . $e($CFG_GLPI['root_doc'] . '/plugins/viagensfield/front/ajax.php') . '"'
            . ' data-token="' . $e(PluginViagensfieldConfig::tokenCsrf()) . '"'
            . ' data-parte="' . PluginViagensfieldComprovante::TAMANHO_PARTE . '"'
            . ' data-maximo="' . PluginViagensfieldComprovante::TAMANHO_MAXIMO . '">';
        echo '<div class="viagensfield-comprovantes-lista">' . PluginViagensfieldComprovante::galeriaHtml($existentes) . '</div>';
        echo '<label class="btn btn-sm btn-outline-secondary viagensfield-comprovantes-botao"><i class="ti ti-paperclip me-1"></i>Adicionar fotos ou documentos'
            . '<input type="file" multiple hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.txt,.csv,.zip,.rar" data-viagensfield-comprovantes-arquivo></label>';
        echo '<div class="viagensfield-dica"><i class="ti ti-info-circle"></i>Fotos do recibo, notas fiscais, PDFs ou prints da corrida (até 20 MB cada). '
            . 'Fotos grandes são reduzidas automaticamente. Os arquivos ficam na viagem ao ' . ($nova ? 'adicionar' : 'salvar') . '.</div>';
        echo '</div>';
        echo '</td></tr>';
        if (!$nova) {
            echo '<tr class="tab_bg_1"><td>Registrada por</td><td>' . $e(PluginViagensfieldConfig::nomeUsuario((int) $this->fields['users_id']))
                . '</td><td>Em</td><td>' . $e(Html::convDateTime($this->fields['date_creation'])) . '</td></tr>';
        }

        $this->showFormButtons($options);
        return true;
    }

    // =====================================================================
    // Regras de gravação
    // =====================================================================

    /** Normaliza os campos que chegam do formulário */
    private function normalizar(array $input): array
    {
        if (array_key_exists('custo', $input)) {
            $input['custo'] = PluginViagensfieldConfig::paraNumero($input['custo']);
        }
        if (array_key_exists('km', $input)) {
            $input['km'] = round(max(0, PluginViagensfieldConfig::paraNumero($input['km'])), 1);
        }
        if (array_key_exists('status', $input) && !isset(PluginViagensfieldConfig::STATUS[$input['status']])) {
            $input['status'] = 'efetuada';
        }
        if (array_key_exists('meio', $input)) {
            $input['meio'] = mb_substr(trim((string) $input['meio']), 0, 100);
        }
        if (array_key_exists('users_id_tecnico', $input)) {
            $input['users_id_tecnico'] = max(0, (int) $input['users_id_tecnico']);
        }
        foreach (['entities_id_origem', 'entities_id_destino'] as $campo) {
            if (array_key_exists($campo, $input)) {
                $input[$campo] = (int) $input[$campo];
            }
        }
        if (array_key_exists('_requerente', $input)) {
            $valor = (string) $input['_requerente'];
            $input['users_id_requerente'] = 0;
            $input['requerente_email'] = '';
            if (str_starts_with($valor, 'u:')) {
                $input['users_id_requerente'] = max(0, (int) substr($valor, 2));
            } elseif (str_starts_with($valor, 'e:') && filter_var(substr($valor, 2), FILTER_VALIDATE_EMAIL)) {
                $input['requerente_email'] = substr($valor, 2);
            }
        }
        if (array_key_exists('data_hora', $input) && trim((string) $input['data_hora']) === '') {
            $input['data_hora'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }
        return $input;
    }

    /** Valida os campos obrigatórios; devolve a lista de erros */
    private static function validar(array $dados): array
    {
        $erros = [];
        if ((int) ($dados['users_id_tecnico'] ?? 0) <= 0) {
            $erros[] = 'Informe o técnico que fez a viagem.';
        }
        // A entidade raiz tem id 0; "vazio" no seletor é -1
        if (!isset($dados['entities_id_origem']) || (int) $dados['entities_id_origem'] < 0) {
            $erros[] = 'Informe a origem.';
        }
        if (!isset($dados['entities_id_destino']) || (int) $dados['entities_id_destino'] < 0) {
            $erros[] = 'Informe o destino.';
        }
        if ((float) ($dados['custo'] ?? 0) < 0) {
            $erros[] = 'O custo não pode ser negativo.';
        }
        if ((float) ($dados['custo'] ?? 0) > 999999999) {
            $erros[] = 'Custo inválido.';
        }
        return array_values(array_unique($erros));
    }

    private static function recusar(array $erros): bool
    {
        foreach ($erros as $erro) {
            Session::addMessageAfterRedirect($erro, false, ERROR);
        }
        return false;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->normalizar($input);
        $itemtype = (string) ($input['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? 0);
        $item = self::itemVinculado($itemtype, $items_id);
        if ($item === null || !$item->canViewItem()) {
            return self::recusar(['Item vinculado inválido ou sem acesso.']);
        }

        $input['entities_id'] = (int) $item->getEntityID();
        $input['item_titulo'] = mb_substr((string) ($item->fields['name'] ?? ''), 0, 255);
        $input['users_id'] = (int) Session::getLoginUserID();
        $input['status'] = $input['status'] ?? 'efetuada';
        $input['custo'] = $input['custo'] ?? 0.0;
        $input['data_hora'] = $input['data_hora'] ?? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));

        $erros = self::validar($input);
        $debito = self::debito((string) $input['status'], $input['custo']);
        if (!$erros && $debito > 0 && PluginViagensfieldConfig::bloquearSemSaldo()) {
            $saldo = PluginViagensfieldCaixa::saldo();
            if ($debito > $saldo) {
                $erros[] = 'Saldo insuficiente no caixa: disponível ' . PluginViagensfieldConfig::moeda($saldo)
                    . ', viagem de ' . PluginViagensfieldConfig::moeda($debito) . '.';
            }
        }
        return $erros ? self::recusar($erros) : $input;
    }

    public function prepareInputForUpdate($input)
    {
        // O vínculo e a entidade não mudam depois de criada
        unset($input['itemtype'], $input['items_id'], $input['entities_id'], $input['users_id'], $input['item_titulo']);
        $input = $this->normalizar($input);

        $final = array_merge($this->fields, $input);
        $erros = self::validar($final);

        $novo = self::debito((string) $final['status'], $final['custo']);
        $atual = self::debito((string) $this->fields['status'], $this->fields['custo']);
        $diferenca = round($novo - $atual, 2);
        if (!$erros && $diferenca > 0 && PluginViagensfieldConfig::bloquearSemSaldo()) {
            $saldo = PluginViagensfieldCaixa::saldo();
            if ($diferenca > $saldo) {
                $erros[] = 'Saldo insuficiente no caixa para o aumento de ' . PluginViagensfieldConfig::moeda($diferenca)
                    . ' (disponível ' . PluginViagensfieldConfig::moeda($saldo) . ').';
            }
        }
        return $erros ? self::recusar($erros) : $input;
    }

    private function dadosLancamento(string $observacao): array
    {
        return [
            'viagens_id'  => (int) $this->fields['id'],
            'entities_id' => (int) $this->fields['entities_id'],
            'itemtype'    => (string) $this->fields['itemtype'],
            'items_id'    => (int) $this->fields['items_id'],
            'observacao'  => $observacao,
        ];
    }

    public function post_addItem()
    {
        $debito = self::debito((string) $this->fields['status'], $this->fields['custo']);
        if ($debito > 0) {
            PluginViagensfieldCaixa::movimentar('despesa', $debito, $this->dadosLancamento('Viagem #' . $this->fields['id']));
        }
        // Comprovantes enviados no formulário e imagens coladas nas observações
        $this->input = $this->addFiles($this->input, ['name' => 'filename', 'content_field' => 'comment']);
        $this->input = $this->addFiles($this->input, ['name' => 'comment', 'content_field' => 'comment', 'force_update' => true]);
        PluginViagensfieldComprovante::anexar($this, (array) ($this->input['_viagensfield_comprovantes'] ?? []));
        parent::post_addItem();
    }

    public function post_updateItem($history = true)
    {
        $statusAntes = (string) ($this->oldvalues['status'] ?? $this->fields['status']);
        $custoAntes = $this->oldvalues['custo'] ?? $this->fields['custo'];
        $antes = self::debito($statusAntes, $custoAntes);
        $depois = self::debito((string) $this->fields['status'], $this->fields['custo']);
        $diferenca = round($depois - $antes, 2);

        if ($diferenca > 0) {
            $motivo = $statusAntes === 'cancelada' ? 'Viagem #' . $this->fields['id'] . ' reativada' : 'Ajuste da viagem #' . $this->fields['id'];
            PluginViagensfieldCaixa::movimentar('despesa', $diferenca, $this->dadosLancamento($motivo));
        } elseif ($diferenca < 0) {
            $motivo = $this->fields['status'] === 'cancelada' ? 'Cancelamento da viagem #' . $this->fields['id'] : 'Ajuste da viagem #' . $this->fields['id'];
            PluginViagensfieldCaixa::movimentar('estorno', -$diferenca, $this->dadosLancamento($motivo));
        }

        if (isset($this->input['_comment'])) {
            $this->input = $this->addFiles($this->input, ['name' => 'comment', 'content_field' => 'comment', 'force_update' => true]);
        }
        PluginViagensfieldComprovante::anexar($this, (array) ($this->input['_viagensfield_comprovantes'] ?? []));
        parent::post_updateItem($history);
    }

    public function post_purgeItem()
    {
        $debito = self::debito((string) $this->fields['status'], $this->fields['custo']);
        if ($debito > 0) {
            PluginViagensfieldCaixa::movimentar('estorno', $debito, $this->dadosLancamento('Exclusão da viagem #' . $this->fields['id']));
        }
        parent::post_purgeItem();
    }

    // =====================================================================
    // Busca nativa (histórico, documentos e filtros)
    // =====================================================================

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];
        $tab[] = ['id' => '2', 'table' => self::getTable(), 'field' => 'id', 'name' => 'ID', 'datatype' => 'itemlink', 'massiveaction' => false];
        $tab[] = ['id' => '3', 'table' => self::getTable(), 'field' => 'data_hora', 'name' => 'Data e hora', 'datatype' => 'datetime'];
        $tab[] = ['id' => '4', 'table' => self::getTable(), 'field' => 'status', 'name' => 'Status', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']];
        $tab[] = ['id' => '5', 'table' => self::getTable(), 'field' => 'custo', 'name' => 'Custo', 'datatype' => 'decimal'];
        $tab[] = ['id' => '6', 'table' => self::getTable(), 'field' => 'km', 'name' => 'Distância (km)', 'datatype' => 'decimal'];
        $tab[] = ['id' => '7', 'table' => self::getTable(), 'field' => 'meio', 'name' => 'Meio de transporte', 'datatype' => 'string'];
        $tab[] = ['id' => '8', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tecnico', 'name' => 'Técnico', 'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => '9', 'table' => 'glpi_entities', 'field' => 'completename', 'linkfield' => 'entities_id_origem', 'name' => 'Origem', 'datatype' => 'dropdown'];
        $tab[] = ['id' => '10', 'table' => 'glpi_entities', 'field' => 'completename', 'linkfield' => 'entities_id_destino', 'name' => 'Destino', 'datatype' => 'dropdown'];
        $tab[] = ['id' => '11', 'table' => self::getTable(), 'field' => 'itemtype', 'name' => 'Tipo do item', 'datatype' => 'itemtypename', 'itemtype_list' => PluginViagensfieldConfig::ITEMTYPES];
        $tab[] = ['id' => '12', 'table' => self::getTable(), 'field' => 'items_id', 'name' => 'ID do item', 'datatype' => 'number'];
        $tab[] = ['id' => '13', 'table' => self::getTable(), 'field' => 'item_titulo', 'name' => 'Título do item', 'datatype' => 'string'];
        $tab[] = ['id' => '14', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_requerente', 'name' => 'Requerente', 'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => '15', 'table' => self::getTable(), 'field' => 'requerente_email', 'name' => 'E-mail do requerente', 'datatype' => 'email'];
        $tab[] = ['id' => '16', 'table' => self::getTable(), 'field' => 'comment', 'name' => 'Observações', 'datatype' => 'text', 'htmltext' => true];
        $tab[] = ['id' => '19', 'table' => self::getTable(), 'field' => 'date_mod', 'name' => 'Última atualização', 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '121', 'table' => self::getTable(), 'field' => 'date_creation', 'name' => 'Data de criação', 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => 'Entidade', 'datatype' => 'dropdown', 'massiveaction' => false];
        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return PluginViagensfieldConfig::seloStatus((string) $values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value'] = $values[$field];
            return Dropdown::showFromArray($name, PluginViagensfieldConfig::STATUS, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
