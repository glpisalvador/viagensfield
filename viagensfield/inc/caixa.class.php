<?php

use Symfony\Component\Mime\Address;

/**
 * Plugin Viagens Field - livro-caixa global.
 * Entradas e estornos somam; retiradas e despesas de viagem subtraem.
 * O saldo é sempre recalculado pela soma dos lançamentos.
 */
class PluginViagensfieldCaixa extends CommonDBTM
{
    public const TIPOS = [
        'entrada'  => 'Entrada',
        'retirada' => 'Retirada',
        'despesa'  => 'Despesa de viagem',
        'estorno'  => 'Estorno',
    ];

    public const CREDITO = ['entrada', 'estorno'];

    public static function getTypeName($nb = 0): string
    {
        return 'Caixa de viagens';
    }

    public static function canView(): bool
    {
        return PluginViagensfieldConfig::pode('caixa');
    }

    public static function canCreate(): bool
    {
        return PluginViagensfieldConfig::pode('caixa');
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function saldo(): float
    {
        global $DB;
        $tabela = self::getTable();
        $credito = "'" . implode("','", self::CREDITO) . "'";
        foreach ($DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression(
                'COALESCE(SUM(CASE WHEN ' . $DB->quoteName('tipo') . ' IN (' . $credito . ') THEN ' . $DB->quoteName('valor')
                . ' ELSE -' . $DB->quoteName('valor') . ' END), 0) AS ' . $DB->quoteName('saldo')
            )],
            'FROM'   => $tabela,
        ]) as $r) {
            return round((float) $r['saldo'], 2);
        }
        return 0.0;
    }

    /** Totais por tipo no período (datas no formato Y-m-d, opcionais) */
    public static function totais(string $de = '', string $ate = ''): array
    {
        global $DB;
        $totais = array_fill_keys(array_keys(self::TIPOS), 0.0);
        foreach ($DB->request([
            'SELECT'  => ['tipo', new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('valor') . ') AS ' . $DB->quoteName('total'))],
            'FROM'    => self::getTable(),
            'WHERE'   => self::criterioPeriodo($de, $ate),
            'GROUPBY' => 'tipo',
        ]) as $r) {
            $totais[$r['tipo']] = round((float) $r['total'], 2);
        }
        return $totais;
    }

    public static function criterioPeriodo(string $de, string $ate, string $campo = 'data_hora'): array
    {
        $where = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
            $where[] = [$campo => ['>=', $de . ' 00:00:00']];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
            $where[] = [$campo => ['<=', $ate . ' 23:59:59']];
        }
        return $where;
    }

    /**
     * Registra um lançamento e devolve o id (0 em caso de falha).
     * $dados: viagens_id, entities_id, itemtype, items_id, observacao
     */
    public static function movimentar(string $tipo, float $valor, array $dados = []): int
    {
        global $DB;
        $valor = round($valor, 2);
        if (!isset(self::TIPOS[$tipo]) || $valor <= 0) {
            return 0;
        }

        $anterior = self::saldo();
        $posterior = round(in_array($tipo, self::CREDITO, true) ? $anterior + $valor : $anterior - $valor, 2);

        $ok = $DB->insert(self::getTable(), [
            'tipo'                           => $tipo,
            'valor'                          => $valor,
            'saldo_anterior'                 => $anterior,
            'saldo_posterior'                => $posterior,
            'plugin_viagensfield_viagens_id' => (int) ($dados['viagens_id'] ?? 0),
            'entities_id'                    => (int) ($dados['entities_id'] ?? 0),
            'itemtype'                       => (string) ($dados['itemtype'] ?? ''),
            'items_id'                       => (int) ($dados['items_id'] ?? 0),
            'users_id'                       => (int) Session::getLoginUserID(),
            'observacao'                     => mb_substr(trim((string) ($dados['observacao'] ?? '')), 0, 1000),
            'data_hora'                      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return 0;
        }
        $id = (int) $DB->insertId();

        $minimo = PluginViagensfieldConfig::valorMinimoAlerta();
        if ($minimo > 0 && $anterior >= $minimo && $posterior < $minimo) {
            self::enviarAlertaSaldo($posterior, $minimo, $tipo, $valor);
        }
        return $id;
    }

    /** Lançamentos mais recentes (com filtros opcionais) */
    public static function lancamentos(array $filtros = [], int $limite = 200): array
    {
        global $DB;
        $where = self::criterioPeriodo((string) ($filtros['de'] ?? ''), (string) ($filtros['ate'] ?? ''));
        if (!empty($filtros['tipo']) && isset(self::TIPOS[$filtros['tipo']])) {
            $where['tipo'] = $filtros['tipo'];
        }
        $linhas = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => $where,
            'ORDER' => ['data_hora DESC', 'id DESC'],
            'LIMIT' => max(1, min(2000, $limite)),
        ]), false);
        $docs = PluginViagensfieldComprovante::documentosPorViagem(array_column($linhas, 'plugin_viagensfield_viagens_id'));
        $lista = [];
        foreach ($linhas as $r) {
            $credito = in_array($r['tipo'], self::CREDITO, true);
            $lista[] = [
                'id'              => (int) $r['id'],
                'tipo'            => $r['tipo'],
                'tipo_nome'       => self::TIPOS[$r['tipo']] ?? $r['tipo'],
                'credito'         => $credito,
                'valor'           => (float) $r['valor'],
                'valor_fmt'       => ($credito ? '+ ' : '- ') . PluginViagensfieldConfig::moeda($r['valor']),
                'saldo_anterior'  => PluginViagensfieldConfig::moeda($r['saldo_anterior']),
                'saldo_posterior' => PluginViagensfieldConfig::moeda($r['saldo_posterior']),
                'viagens_id'      => (int) $r['plugin_viagensfield_viagens_id'],
                'docs'            => $docs[(int) $r['plugin_viagensfield_viagens_id']] ?? [],
                'item'            => (int) $r['items_id'] > 0
                    ? PluginViagensfieldConfig::linkItem((string) $r['itemtype'], (int) $r['items_id'])
                    : '',
                'usuario'         => PluginViagensfieldConfig::nomeUsuario((int) $r['users_id']),
                'observacao'      => (string) $r['observacao'],
                'data'            => Html::convDateTime($r['data_hora']),
            ];
        }
        return $lista;
    }

    /** E-mail quando o saldo fica abaixo do mínimo configurado */
    private static function enviarAlertaSaldo(float $saldo, float $minimo, string $tipo, float $valor): void
    {
        global $CFG_GLPI;

        $destinos = PluginViagensfieldConfig::emailsAlerta();
        if (!$destinos || !($CFG_GLPI['use_notifications'] ?? false)) {
            return;
        }
        $e = [PluginViagensfieldConfig::class, 'e'];
        $autor = PluginViagensfieldConfig::nomeUsuario((int) Session::getLoginUserID()) ?: 'GLPI';
        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/viagensfield/front/painel.php?aba=caixa';

        $html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1d273b;max-width:620px;">';
        $html .= '<h2 style="font-size:16px;margin:0 0 10px;">Saldo do caixa de viagens abaixo do mínimo</h2>';
        $html .= '<table style="border-collapse:collapse;font-size:12px;margin-bottom:14px;">';
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Saldo atual</td><td><b style="color:#d63939;">' . $e(PluginViagensfieldConfig::moeda($saldo)) . '</b></td></tr>';
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Mínimo configurado</td><td>' . $e(PluginViagensfieldConfig::moeda($minimo)) . '</td></tr>';
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Último lançamento</td><td>' . $e((self::TIPOS[$tipo] ?? $tipo) . ' de ' . PluginViagensfieldConfig::moeda($valor) . ' por ' . $autor) . '</td></tr>';
        $html .= '</table>';
        $html .= '<a href="' . $e($url) . '" style="display:inline-block;padding:7px 14px;background:#206bc4;color:#fff;text-decoration:none;border-radius:4px;">Abrir o caixa</a>';
        $html .= '<p style="font-size:11px;color:#929dab;margin-top:16px;">Mensagem automática do plugin Viagens Field do GLPI.</p>';
        $html .= '</div>';

        $remetente = (string) ($CFG_GLPI['from_email'] ?: $CFG_GLPI['admin_email']);
        $nomeRemetente = (string) ($CFG_GLPI['from_email_name'] ?: ($CFG_GLPI['admin_email_name'] ?: 'GLPI'));

        try {
            $mailer = new GLPIMailer();
            $mensagem = $mailer->getEmail();
            if ($remetente !== '') {
                $mensagem->from(new Address($remetente, $nomeRemetente));
            }
            foreach ($destinos as $email) {
                $mensagem->addTo(new Address($email));
            }
            $mensagem->subject('[Viagens] Saldo do caixa abaixo de ' . PluginViagensfieldConfig::moeda($minimo));
            $mensagem->html($html);
            $mensagem->text(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>', '</tr>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
            $mensagem->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $mensagem->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            if (!$mailer->send()) {
                error_log('Plugin viagensfield: falha ao enviar alerta de saldo: ' . (string) $mailer->getError());
            }
        } catch (\Throwable $e2) {
            error_log('Plugin viagensfield: falha ao enviar alerta de saldo: ' . $e2->getMessage());
        }
    }
}
