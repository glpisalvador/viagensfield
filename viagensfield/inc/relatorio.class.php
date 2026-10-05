<?php

/**
 * Plugin Viagens Field - consultas e agregações do painel.
 * Sempre restrito às entidades ativas do usuário.
 */
class PluginViagensfieldRelatorio extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Relatórios de viagens';
    }

    public static function canView(): bool
    {
        return PluginViagensfieldConfig::pode('relatorio');
    }

    /** Filtros aceitos, já saneados */
    public static function lerFiltros(array $origem): array
    {
        $data = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) ? substr((string) $v, 0, 10) : '';
        $status = (string) ($origem['status'] ?? '');
        $itemtype = (string) ($origem['itemtype'] ?? '');
        return [
            'de'        => $data($origem['de'] ?? ''),
            'ate'       => $data($origem['ate'] ?? ''),
            'tecnico'   => max(0, (int) ($origem['tecnico'] ?? 0)),
            'entidade'  => (int) ($origem['entidade'] ?? -1),
            'status'    => isset(PluginViagensfieldConfig::STATUS[$status]) ? $status : '',
            'itemtype'  => in_array($itemtype, PluginViagensfieldConfig::ITEMTYPES, true) ? $itemtype : '',
            'busca'     => mb_substr(trim((string) ($origem['busca'] ?? '')), 0, 100),
        ];
    }

    private static function criterios(array $f): array
    {
        $t = PluginViagensfieldViagem::getTable();
        $where = (new DbUtils())->getEntitiesRestrictCriteria($t, 'entities_id', '', false);
        $where = $where ? [$where] : [];
        $where = array_merge($where, PluginViagensfieldCaixa::criterioPeriodo($f['de'], $f['ate'], $t . '.data_hora'));
        if ($f['tecnico'] > 0) {
            $where[] = [$t . '.users_id_tecnico' => $f['tecnico']];
        }
        if ($f['entidade'] >= 0) {
            // Entidade do item vinculado (cliente atendido), incluindo as filhas
            $where[] = [$t . '.entities_id' => array_values(array_map('intval', (new DbUtils())->getSonsOf('glpi_entities', $f['entidade'])))];
        }
        if ($f['status'] !== '') {
            $where[] = [$t . '.status' => $f['status']];
        }
        if ($f['itemtype'] !== '') {
            $where[] = [$t . '.itemtype' => $f['itemtype']];
        }
        if ($f['busca'] !== '') {
            $like = '%' . $f['busca'] . '%';
            $ou = [[$t . '.item_titulo' => ['LIKE', $like]], [$t . '.meio' => ['LIKE', $like]]];
            if (ctype_digit(ltrim($f['busca'], '#'))) {
                $numero = (int) ltrim($f['busca'], '#');
                $ou[] = [$t . '.id' => $numero];
                $ou[] = [$t . '.items_id' => $numero];
            }
            $where[] = ['OR' => $ou];
        }
        return $where;
    }

    /** Viagens filtradas + indicadores e séries dos gráficos */
    public static function consultar(array $filtros, int $limite = 5000): array
    {
        global $DB;
        $f = self::lerFiltros($filtros);
        $t = PluginViagensfieldViagem::getTable();

        $linhas = [];
        $kpi = ['quantidade' => 0, 'efetuadas' => 0, 'pendentes' => 0, 'canceladas' => 0, 'custo' => 0.0, 'km' => 0.0];
        $porEntidade = [];
        $porTecnico = [];
        $porMes = [];
        $porStatus = array_fill_keys(array_keys(PluginViagensfieldConfig::STATUS), 0);
        $ids = [];

        foreach ($DB->request([
            'FROM'  => $t,
            'WHERE' => self::criterios($f),
            'ORDER' => [$t . '.data_hora DESC', $t . '.id DESC'],
            'LIMIT' => max(1, $limite),
        ]) as $r) {
            $status = (string) $r['status'];
            $debito = PluginViagensfieldViagem::debito($status, $r['custo']);
            $kpi['quantidade']++;
            $porStatus[$status] = ($porStatus[$status] ?? 0) + 1;
            if ($status === 'cancelada') {
                $kpi['canceladas']++;
            } else {
                $kpi[$status === 'pendente' ? 'pendentes' : 'efetuadas']++;
                $kpi['custo'] += $debito;
                $kpi['km'] += (float) $r['km'];
                $porEntidade[(int) $r['entities_id']] = ($porEntidade[(int) $r['entities_id']] ?? 0) + $debito;
                $tecnico = PluginViagensfieldConfig::nomeUsuario((int) $r['users_id_tecnico']) ?: '—';
                $porTecnico[$tecnico] = ($porTecnico[$tecnico] ?? 0) + $debito;
                $mes = substr((string) $r['data_hora'], 0, 7);
                $porMes[$mes] = ($porMes[$mes] ?? 0) + $debito;
            }
            $ids[] = (int) $r['id'];
            $linhas[] = [
                'id'          => (int) $r['id'],
                'url'         => PluginViagensfieldViagem::getFormURLWithID((int) $r['id']),
                'data'        => Html::convDateTime($r['data_hora']),
                'data_iso'    => (string) $r['data_hora'],
                'item_html'   => PluginViagensfieldConfig::linkItem((string) $r['itemtype'], (int) $r['items_id'], (string) $r['item_titulo']),
                'item_texto'  => PluginViagensfieldConfig::nomeItemtype((string) $r['itemtype']) . ' #' . $r['items_id'] . ' - ' . $r['item_titulo'],
                'entidade'    => PluginViagensfieldConfig::nomeEntidade((int) $r['entities_id']),
                'entidade_curta' => PluginViagensfieldConfig::nomeEntidadeCurto((int) $r['entities_id']),
                'origem_curta'   => PluginViagensfieldConfig::nomeEntidadeCurto((int) $r['entities_id_origem']),
                'destino_curta'  => PluginViagensfieldConfig::nomeEntidadeCurto((int) $r['entities_id_destino']),
                'tecnico'     => PluginViagensfieldConfig::nomeUsuario((int) $r['users_id_tecnico']),
                'requerente'  => (int) $r['users_id_requerente'] > 0
                    ? PluginViagensfieldConfig::nomeUsuario((int) $r['users_id_requerente'])
                    : (string) $r['requerente_email'],
                'origem'      => PluginViagensfieldConfig::nomeEntidade((int) $r['entities_id_origem']),
                'destino'     => PluginViagensfieldConfig::nomeEntidade((int) $r['entities_id_destino']),
                'meio'        => (string) $r['meio'],
                'km'          => (float) $r['km'],
                'km_fmt'      => PluginViagensfieldConfig::km($r['km']),
                'custo'       => (float) $r['custo'],
                'custo_fmt'   => PluginViagensfieldConfig::moeda($r['custo']),
                'status'      => $status,
                'status_nome' => PluginViagensfieldConfig::nomeStatus($status),
                'status_html' => PluginViagensfieldConfig::seloStatus($status),
                'docs'        => [],
            ];
        }

        $documentos = PluginViagensfieldComprovante::documentosPorViagem($ids);
        foreach ($linhas as &$linha) {
            $linha['docs'] = $documentos[$linha['id']] ?? [];
        }
        unset($linha);

        arsort($porEntidade);
        arsort($porTecnico);
        ksort($porMes);
        $ativas = $kpi['quantidade'] - $kpi['canceladas'];

        return [
            'filtros' => $f,
            'linhas'  => $linhas,
            'limitado' => count($linhas) >= $limite,
            'kpi'     => [
                'quantidade' => $kpi['quantidade'],
                'efetuadas'  => $kpi['efetuadas'],
                'pendentes'  => $kpi['pendentes'],
                'canceladas' => $kpi['canceladas'],
                'custo'      => PluginViagensfieldConfig::moeda($kpi['custo']),
                'km'         => PluginViagensfieldConfig::km($kpi['km']) . ' km',
                'media'      => PluginViagensfieldConfig::moeda($ativas > 0 ? $kpi['custo'] / $ativas : 0),
                'por_km'     => $kpi['km'] > 0 ? PluginViagensfieldConfig::moeda($kpi['custo'] / $kpi['km']) : '—',
            ],
            'graficos' => [
                'entidades' => self::serie(array_slice($porEntidade, 0, 10, true), fn($id) => PluginViagensfieldConfig::nomeEntidadeCurto((int) $id)),
                'tecnicos'  => self::serie(array_slice($porTecnico, 0, 10, true)),
                'meses'     => self::serie($porMes, fn($m) => self::rotuloMes((string) $m)),
                'status'    => array_map(
                    fn($s, $n) => ['nome' => PluginViagensfieldConfig::nomeStatus($s), 'chave' => $s, 'valor' => $n],
                    array_keys($porStatus),
                    array_values($porStatus)
                ),
            ],
        ];
    }

    private static function serie(array $mapa, ?callable $rotulo = null): array
    {
        $lista = [];
        foreach ($mapa as $nome => $valor) {
            $lista[] = ['nome' => $rotulo ? $rotulo($nome) : (string) $nome, 'valor' => round((float) $valor, 2)];
        }
        return $lista;
    }

    private static function rotuloMes(string $ym): string
    {
        $meses = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        [$a, $m] = array_pad(explode('-', $ym), 2, '');
        return ($meses[(int) $m] ?? $m) . '/' . substr($a, 2);
    }

    /** Opções dos filtros: só técnicos e entidades que aparecem em viagens visíveis */
    public static function opcoesFiltros(): array
    {
        global $DB;
        $t = PluginViagensfieldViagem::getTable();
        $restricao = (new DbUtils())->getEntitiesRestrictCriteria($t, 'entities_id', '', false);
        $where = $restricao ? [$restricao] : [];

        $tecnicos = [];
        foreach ($DB->request(['SELECT' => ['users_id_tecnico'], 'DISTINCT' => true, 'FROM' => $t, 'WHERE' => $where]) as $r) {
            $id = (int) $r['users_id_tecnico'];
            if ($id > 0) {
                $tecnicos[$id] = PluginViagensfieldConfig::nomeUsuario($id);
            }
        }
        asort($tecnicos, SORT_NATURAL | SORT_FLAG_CASE);

        // Entidades filhas ficam escondidas: aparece a entidade pai (o filtro inclui as filhas)
        $filhas = array_flip(PluginViagensfieldConfig::idsEntidadesFilhas());
        $dbu = new DbUtils();
        $entidades = [];
        foreach ($DB->request(['SELECT' => ['entities_id'], 'DISTINCT' => true, 'FROM' => $t, 'WHERE' => $where]) as $r) {
            $id = (int) $r['entities_id'];
            $candidatas = isset($filhas[$id]) ? array_map('intval', $dbu->getAncestorsOf('glpi_entities', $id)) : [$id];
            foreach ($candidatas as $c) {
                if (!isset($filhas[$c]) && Session::haveAccessToEntity($c)) {
                    $entidades[$c] = PluginViagensfieldConfig::nomeEntidade($c);
                }
            }
        }
        asort($entidades, SORT_NATURAL | SORT_FLAG_CASE);

        return ['tecnicos' => $tecnicos, 'entidades' => $entidades];
    }
}
