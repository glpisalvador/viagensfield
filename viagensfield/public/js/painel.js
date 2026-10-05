/**
 * Plugin Viagens Field - painel (Viagens | Relatórios | Caixa).
 * Dados via front/ajax.php; gráficos com o ECharts que já vem no GLPI.
 */
(function () {
    'use strict';

    var raiz, ajaxUrl, token;
    var dados = { linhas: [], graficos: null };
    var visao = { termo: '', pagina: 1, porPagina: 25, sort: { column: 'data_iso', direction: 'desc' } };
    var graficos = {};
    var caixaLinhas = [];
    var debounce = null;

    // ------------------------------------------------------------------ utilidades
    function esc(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function escapeRegex(texto) {
        return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function aviso(mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    }

    /** Lê a resposta como texto e tolera avisos do PHP antes do JSON */
    function lerJson(resposta) {
        return resposta.text().then(function (texto) {
            try {
                return JSON.parse(texto);
            } catch (e) {
                var m = texto.match(/\{[\s\S]*\}\s*$/);
                if (m) {
                    try { return JSON.parse(m[0]); } catch (e2) { /* segue */ }
                }
                return { success: false, mensagem: 'Resposta inválida do servidor.' };
            }
        });
    }

    function obter(acao, params) {
        var q = new URLSearchParams(params || {});
        q.set('action', acao);
        return fetch(ajaxUrl + '?' + q.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(lerJson);
    }

    function enviar(acao, formData) {
        formData.set('action', acao);
        if (token) { formData.set('_glpi_csrf_token', token); }
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        if (token) { cab['X-Glpi-Csrf-Token'] = token; }
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: cab, body: formData })
            .then(lerJson)
            .then(function (r) {
                if (r && r.new_token) { token = r.new_token; }
                return r;
            });
    }

    function valorCampo(form, nome) {
        var campo = form.querySelector('[name="' + nome + '"]');
        return campo ? String(campo.value || '') : '';
    }

    function definirCampo(form, nome, valor) {
        var campo = form.querySelector('[name="' + nome + '"]');
        if (!campo) { return; }
        if (campo._flatpickr) {
            campo._flatpickr.setDate(valor || null, true);
        } else if (window.jQuery && jQuery(campo).hasClass('select2-hidden-accessible')) {
            jQuery(campo).val(valor).trigger('change');
        } else {
            campo.value = valor;
        }
    }

    // ------------------------------------------------------------------ abas
    function mostrarAba(aba) {
        raiz.querySelectorAll('[data-viagensfield-aba]').forEach(function (l) {
            l.classList.toggle('active', l.getAttribute('data-viagensfield-aba') === aba);
        });
        raiz.querySelectorAll('[data-viagensfield-painel]').forEach(function (p) {
            p.hidden = p.getAttribute('data-viagensfield-painel') !== aba;
        });
        var filtros = document.getElementById('viagensfield-filtros');
        var kpis = document.getElementById('viagensfield-kpis');
        if (filtros) { filtros.hidden = aba === 'caixa'; }
        if (kpis) { kpis.hidden = aba === 'caixa'; }

        var url = new URL(window.location.href);
        url.searchParams.set('aba', aba);
        history.replaceState(null, '', url.toString());

        if (aba === 'relatorios') {
            desenharGraficos();
        }
        if (aba === 'caixa' && !raiz.getAttribute('data-caixa-carregado')) {
            carregarCaixa();
        }
    }

    // ------------------------------------------------------------------ Viagens
    function paramsFiltros() {
        var form = document.getElementById('viagensfield-filtros');
        var p = {};
        ['de', 'ate', 'tecnico', 'entidade', 'status', 'itemtype'].forEach(function (n) {
            var v = valorCampo(form, n);
            if (v !== '' && !(n === 'tecnico' && v === '0') && !(n === 'entidade' && v === '-1')) {
                p[n] = v;
            }
        });
        return p;
    }

    function carregarViagens() {
        var form = document.getElementById('viagensfield-filtros');
        if (!form) { return; }
        var params = paramsFiltros();

        var url = new URL(window.location.href);
        ['de', 'ate', 'tecnico', 'entidade', 'status', 'itemtype'].forEach(function (n) { url.searchParams.delete(n); });
        Object.keys(params).forEach(function (n) { url.searchParams.set(n, params[n]); });
        if (!params.de) { url.searchParams.set('de', ''); }
        history.replaceState(null, '', url.toString());

        var corpo = document.querySelector('#viagensfield-tabela-viagens tbody');
        corpo.innerHTML = '<tr><td colspan="11" class="viagensfield-carregando"><i class="ti ti-loader"></i>Carregando...</td></tr>';

        obter('relatorio', params).then(function (r) {
            if (!r || !r.success) {
                corpo.innerHTML = '<tr><td colspan="11" class="viagensfield-carregando">' + esc((r && r.mensagem) || 'Falha ao carregar.') + '</td></tr>';
                return;
            }
            dados.linhas = r.linhas || [];
            dados.graficos = r.graficos || null;
            dados.limitado = !!r.limitado;
            Object.keys(r.kpi || {}).forEach(function (k) {
                var alvo = raiz.querySelector('[data-kpi="' + k + '"]');
                if (alvo) { alvo.textContent = r.kpi[k]; }
            });
            visao.pagina = 1;
            renderizarTabela();
            if (!raiz.querySelector('[data-viagensfield-painel="relatorios"]').hidden) {
                desenharGraficos();
            } else {
                raiz.setAttribute('data-graficos-pendentes', '1');
            }
        }).catch(function () {
            corpo.innerHTML = '<tr><td colspan="11" class="viagensfield-carregando">Falha de comunicação com o servidor.</td></tr>';
        });
    }

    function linhasFiltradas() {
        var termo = visao.termo.toLowerCase();
        var lista = dados.linhas.filter(function (l) {
            if (!termo) { return true; }
            return [l.id, l.data, l.item_texto, l.entidade, l.tecnico, l.requerente, l.origem, l.destino, l.meio, l.km_fmt, l.custo_fmt, l.status_nome]
                .join(' ').toLowerCase().indexOf(termo) !== -1;
        });
        var col = visao.sort.column;
        var dir = visao.sort.direction === 'asc' ? 1 : -1;
        var th = raiz.querySelector('#viagensfield-tabela-viagens th[data-sort="' + col + '"]');
        var numero = th && th.getAttribute('data-tipo') === 'numero';
        lista.sort(function (a, b) {
            var va = a[col], vb = b[col];
            if (numero || (!isNaN(va) && !isNaN(vb) && va !== '' && vb !== '')) {
                return (Number(va) - Number(vb)) * dir;
            }
            return String(va || '').toLowerCase().localeCompare(String(vb || '').toLowerCase(), 'pt-BR') * dir;
        });
        return lista;
    }

    /** Ícone por tipo de arquivo (igual ao PHP) */
    function iconeDoc(d) {
        if (d.imagem) { return 'ti-photo'; }
        var ext = String(d.nome || '').split('.').pop().toLowerCase();
        if (ext === 'pdf') { return 'ti-file-type-pdf'; }
        if (['doc', 'docx', 'odt'].indexOf(ext) !== -1) { return 'ti-file-type-doc'; }
        if (['xls', 'xlsx', 'ods', 'csv'].indexOf(ext) !== -1) { return 'ti-file-spreadsheet'; }
        if (['zip', 'rar'].indexOf(ext) !== -1) { return 'ti-file-zip'; }
        return 'ti-file';
    }

    function linksDocs(docs) {
        return (docs || []).map(function (d) {
            return '<a href="' + esc(d.url) + '" target="_blank" rel="noopener" title="' + esc(d.nome) + '"><i class="ti ' + iconeDoc(d) + '"></i></a>';
        }).join('');
    }

    function renderizarTabela() {
        var lista = linhasFiltradas();
        var corpo = document.querySelector('#viagensfield-tabela-viagens tbody');
        var total = lista.length;
        var paginas = Math.max(1, Math.ceil(total / visao.porPagina));
        if (visao.pagina > paginas) { visao.pagina = paginas; }
        var inicio = (visao.pagina - 1) * visao.porPagina;
        var fim = Math.min(total, inicio + visao.porPagina);

        var contador = document.getElementById('viagensfield-contador');
        contador.textContent = total + (total === 1 ? ' viagem' : ' viagens') + (dados.limitado ? ' (limite atingido, refine os filtros)' : '');

        raiz.querySelectorAll('#viagensfield-tabela-viagens th[data-sort]').forEach(function (th) {
            th.classList.remove('sorted-asc', 'sorted-desc');
            if (th.getAttribute('data-sort') === visao.sort.column) {
                th.classList.add(visao.sort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
            }
        });

        if (total === 0) {
            corpo.innerHTML = '<tr><td colspan="11"><div class="viagensfield-vazio"><i class="ti ti-mood-empty"></i><span>Nenhuma viagem encontrada com esses filtros.</span></div></td></tr>';
            document.getElementById('viagensfield-paginacao').innerHTML = '';
            return;
        }

        var html = '';
        for (var i = inicio; i < fim; i++) {
            var l = lista[i];
            var docs = linksDocs(l.docs);
            html += '<tr' + (l.status === 'cancelada' ? ' class="viagensfield-linha-cancelada"' : '') + '>'
                + '<td><a href="' + esc(l.url) + '">#' + l.id + '</a></td>'
                + '<td class="text-nowrap">' + esc(l.data) + '</td>'
                + '<td data-interativo>' + l.item_html + '</td>'
                + '<td title="' + esc(l.entidade) + '">' + esc(l.entidade_curta) + '</td>'
                + '<td>' + esc(l.tecnico) + '</td>'
                + '<td><span class="viagensfield-trajeto"><span title="' + esc(l.origem) + '">' + esc(l.origem_curta) + '</span><i class="ti ti-arrow-right"></i>'
                + '<span title="' + esc(l.destino) + '">' + esc(l.destino_curta) + '</span></span></td>'
                + '<td>' + esc(l.meio) + '</td>'
                + '<td class="text-end">' + esc(l.km_fmt) + '</td>'
                + '<td class="text-end text-nowrap">' + esc(l.custo_fmt) + '</td>'
                + '<td data-interativo>' + l.status_html + '</td>'
                + '<td class="text-center viagensfield-docs" data-interativo>' + (docs || '<span class="text-muted">—</span>') + '</td>'
                + '</tr>';
        }
        corpo.innerHTML = html;
        destacar(corpo);
        renderizarPaginacao(paginas);
    }

    /** Destaca o termo pesquisado, sem mexer em links e botões */
    function destacar(corpo) {
        if (!visao.termo) { return; }
        var re = new RegExp('(' + escapeRegex(visao.termo) + ')', 'gi');
        corpo.querySelectorAll('td').forEach(function (td) {
            if (td.hasAttribute('data-interativo') || td.querySelector('a, button, input')) { return; }
            td.querySelectorAll('mark.highlight').forEach(function (m) { m.replaceWith(document.createTextNode(m.textContent)); });
            var caminhante = document.createTreeWalker(td, NodeFilter.SHOW_TEXT);
            var nos = [];
            while (caminhante.nextNode()) { nos.push(caminhante.currentNode); }
            nos.forEach(function (no) {
                if (!re.test(no.nodeValue)) { return; }
                re.lastIndex = 0;
                var span = document.createElement('span');
                span.innerHTML = esc(no.nodeValue).replace(re, '<mark class="highlight">$1</mark>');
                no.replaceWith.apply(no, Array.prototype.slice.call(span.childNodes));
            });
        });
    }

    function renderizarPaginacao(paginas) {
        var alvo = document.getElementById('viagensfield-paginacao');
        if (paginas <= 1) { alvo.innerHTML = ''; return; }
        var atual = visao.pagina;
        var botoes = [];
        var ini = Math.max(1, atual - 2);
        var fim = Math.min(paginas, ini + 4);
        ini = Math.max(1, fim - 4);
        function botao(p, rotulo, ativo, desabilitado) {
            return '<button type="button" class="btn btn-sm ' + (ativo ? 'btn-primary' : 'btn-outline-secondary') + '"'
                + (desabilitado ? ' disabled' : '') + ' data-pagina="' + p + '">' + rotulo + '</button>';
        }
        botoes.push(botao(atual - 1, '<i class="ti ti-chevron-left"></i>', false, atual === 1));
        if (ini > 1) {
            botoes.push(botao(1, '1', false, false));
            if (ini > 2) { botoes.push('<span class="viagensfield-reticencias">…</span>'); }
        }
        for (var p = ini; p <= fim; p++) { botoes.push(botao(p, String(p), p === atual, false)); }
        if (fim < paginas) {
            if (fim < paginas - 1) { botoes.push('<span class="viagensfield-reticencias">…</span>'); }
            botoes.push(botao(paginas, String(paginas), false, false));
        }
        botoes.push(botao(atual + 1, '<i class="ti ti-chevron-right"></i>', false, atual === paginas));
        alvo.innerHTML = botoes.join('');
    }

    // ------------------------------------------------------------------ exportação
    function csv(linhas) {
        return '\uFEFF' + linhas.map(function (l) {
            return l.map(function (c) { return '"' + String(c === null || c === undefined ? '' : c).replace(/"/g, '""') + '"'; }).join(';');
        }).join('\r\n');
    }

    function baixar(conteudo, nome) {
        var blob = new Blob([conteudo], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    function hoje() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function exportarViagens() {
        var linhas = [['Viagem', 'Data', 'Vínculo', 'Entidade', 'Técnico', 'Requerente', 'Origem', 'Destino', 'Meio', 'Km', 'Custo (R$)', 'Status', 'Comprovantes']];
        linhasFiltradas().forEach(function (l) {
            linhas.push([l.id, l.data, l.item_texto, l.entidade, l.tecnico, l.requerente, l.origem, l.destino, l.meio,
                l.km_fmt, Number(l.custo).toFixed(2).replace('.', ','), l.status_nome, (l.docs || []).length]);
        });
        baixar(csv(linhas), 'viagens_' + hoje() + '.csv');
    }

    function imprimirViagens() {
        var tabela = document.getElementById('viagensfield-tabela-viagens').cloneNode(true);
        tabela.querySelectorAll('.viagensfield-no-export').forEach(function (th) {
            var indice = Array.prototype.indexOf.call(th.parentNode.children, th);
            tabela.querySelectorAll('tr').forEach(function (tr) { if (tr.children[indice]) { tr.children[indice].remove(); } });
        });
        var corpo = tabela.querySelector('tbody');
        corpo.innerHTML = '';
        linhasFiltradas().forEach(function (l) {
            corpo.insertAdjacentHTML('beforeend', '<tr><td>#' + l.id + '</td><td>' + esc(l.data) + '</td><td>' + esc(l.item_texto) + '</td><td>'
                + esc(l.entidade) + '</td><td>' + esc(l.tecnico) + '</td><td>' + esc(l.origem) + ' → ' + esc(l.destino) + '</td><td>' + esc(l.meio)
                + '</td><td style="text-align:right">' + esc(l.km_fmt) + '</td><td style="text-align:right">' + esc(l.custo_fmt) + '</td><td>' + esc(l.status_nome) + '</td></tr>');
        });
        var resumo = Array.prototype.map.call(raiz.querySelectorAll('#viagensfield-kpis .viagensfield-kpi'), function (k) {
            return '<span><b>' + esc(k.querySelector('.viagensfield-kpi-rotulo').textContent) + ':</b> ' + esc(k.querySelector('.viagensfield-kpi-valor').textContent) + '</span>';
        }).join('');
        var janela = window.open('', '_blank');
        if (!janela) { aviso('Permita janelas pop-up para imprimir.', true); return; }
        janela.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Viagens</title><style>'
            + 'body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#333;margin:16px}h1{font-size:15px;margin:0 0 6px}'
            + '.r{display:flex;flex-wrap:wrap;gap:14px;margin:0 0 10px;color:#555}table{width:100%;border-collapse:collapse}'
            + 'th,td{border-bottom:1px solid #ddd;padding:4px 6px;text-align:left}th{background:#f8f9fa;font-size:10px;text-transform:uppercase;color:#6c757d}'
            + '</style></head><body><h1>Viagens</h1><div class="r">' + resumo + '</div>' + tabela.outerHTML + '</body></html>');
        janela.document.close();
        janela.focus();
        janela.print();
    }

    function exportarCaixa() {
        var linhas = [['Data', 'Tipo', 'Motivo', 'Usuário', 'Comprovantes', 'Valor (R$)', 'Saldo anterior', 'Saldo após']];
        caixaLinhas.forEach(function (l) {
            linhas.push([l.data, l.tipo_nome, l.observacao, l.usuario, (l.docs || []).length, (l.credito ? '' : '-') + Number(l.valor).toFixed(2).replace('.', ','),
                l.saldo_anterior, l.saldo_posterior]);
        });
        baixar(csv(linhas), 'caixa_viagens_' + hoje() + '.csv');
    }

    // ------------------------------------------------------------------ gráficos
    function cores() {
        var estilo = getComputedStyle(document.documentElement);
        var primaria = (estilo.getPropertyValue('--tblr-primary') || '').trim() || '#206bc4';
        return {
            primaria: primaria,
            texto: (estilo.getPropertyValue('--tblr-body-color') || '').trim() || '#495057',
            linha: 'rgba(98, 105, 118, 0.16)',
            status: { efetuada: '#2fb344', pendente: '#f59f00', cancelada: '#9aa0ac' }
        };
    }

    function moeda(v) {
        var partes = Number(v || 0).toFixed(2).split('.');
        return 'R$ ' + partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + partes[1];
    }

    function desenharGraficos() {
        if (!window.echarts || !dados.graficos) { return; }
        raiz.removeAttribute('data-graficos-pendentes');
        var c = cores();
        var g = dados.graficos;
        var base = {
            textStyle: { fontFamily: 'inherit', fontSize: 11, color: c.texto },
            grid: { left: 8, right: 16, top: 16, bottom: 8, containLabel: true },
            tooltip: { trigger: 'axis', valueFormatter: moeda }
        };

        function grafico(chave, opcoes, vazio) {
            var el = raiz.querySelector('[data-grafico="' + chave + '"]');
            if (!el) { return; }
            if (vazio) {
                if (graficos[chave]) { graficos[chave].dispose(); delete graficos[chave]; }
                el.innerHTML = '<div class="viagensfield-vazio"><i class="ti ti-chart-bar"></i><span>Sem dados no período.</span></div>';
                return;
            }
            if (!graficos[chave] || graficos[chave].isDisposed()) {
                el.innerHTML = '';
                graficos[chave] = echarts.init(el, null, { renderer: 'svg' });
            }
            graficos[chave].setOption(Object.assign({}, base, opcoes), true);
            graficos[chave].resize();
        }

        function barras(lista, horizontal) {
            var nomes = lista.map(function (i) { return i.nome; });
            var valores = lista.map(function (i) { return i.valor; });
            var eixoCat = { type: 'category', data: nomes, axisTick: { show: false }, axisLine: { lineStyle: { color: c.linha } },
                axisLabel: { color: c.texto, width: 160, overflow: 'truncate' } };
            var eixoVal = { type: 'value', splitLine: { lineStyle: { color: c.linha } },
                axisLabel: { color: c.texto, formatter: function (v) { return v >= 1000 ? (v / 1000).toFixed(1).replace('.', ',') + ' mil' : v; } } };
            if (horizontal) {
                eixoCat.inverse = true;
            }
            return {
                xAxis: horizontal ? eixoVal : eixoCat,
                yAxis: horizontal ? eixoCat : eixoVal,
                series: [{ type: 'bar', data: valores, barMaxWidth: 26,
                    itemStyle: { color: c.primaria, opacity: 0.75, borderRadius: horizontal ? [0, 3, 3, 0] : [3, 3, 0, 0] },
                    emphasis: { itemStyle: { opacity: 1 } } }]
            };
        }

        grafico('meses', {
            xAxis: { type: 'category', data: g.meses.map(function (i) { return i.nome; }), boundaryGap: false,
                axisLine: { lineStyle: { color: c.linha } }, axisLabel: { color: c.texto } },
            yAxis: { type: 'value', splitLine: { lineStyle: { color: c.linha } }, axisLabel: { color: c.texto } },
            series: [{ type: 'line', smooth: true, symbolSize: 6, data: g.meses.map(function (i) { return i.valor; }),
                lineStyle: { color: c.primaria, width: 2 }, itemStyle: { color: c.primaria },
                areaStyle: { color: c.primaria, opacity: 0.08 } }]
        }, g.meses.length === 0);

        var totalStatus = g.status.reduce(function (s, i) { return s + i.valor; }, 0);
        grafico('status', {
            tooltip: { trigger: 'item', formatter: function (p) { return esc(p.name) + ': ' + p.value + ' (' + p.percent + '%)'; } },
            legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: c.texto, fontSize: 11 } },
            series: [{ type: 'pie', radius: ['48%', '72%'], center: ['50%', '45%'], avoidLabelOverlap: true,
                label: { show: false }, itemStyle: { borderColor: '#fff', borderWidth: 2 },
                data: g.status.map(function (i) { return { name: i.nome, value: i.valor, itemStyle: { color: c.status[i.chave] || c.primaria } }; }) }]
        }, totalStatus === 0);

        grafico('entidades', barras(g.entidades, true), g.entidades.length === 0);
        grafico('tecnicos', barras(g.tecnicos, true), g.tecnicos.length === 0);
    }

    // ------------------------------------------------------------------ Caixa
    function paramsCaixa() {
        var form = document.getElementById('viagensfield-filtros-caixa');
        var p = {};
        var de = valorCampo(form, 'caixa_de'), ate = valorCampo(form, 'caixa_ate'), tipo = valorCampo(form, 'caixa_tipo');
        if (de) { p.de = de; }
        if (ate) { p.ate = ate; }
        if (tipo) { p.tipo = tipo; }
        return p;
    }

    function carregarCaixa() {
        var corpo = document.querySelector('#viagensfield-tabela-caixa tbody');
        if (!corpo) { return; }
        raiz.setAttribute('data-caixa-carregado', '1');
        corpo.innerHTML = '<tr><td colspan="7" class="viagensfield-carregando"><i class="ti ti-loader"></i>Carregando...</td></tr>';
        obter('caixa', paramsCaixa()).then(function (r) {
            if (!r || !r.success) {
                corpo.innerHTML = '<tr><td colspan="7" class="viagensfield-carregando">' + esc((r && r.mensagem) || 'Falha ao carregar.') + '</td></tr>';
                return;
            }
            var saldo = raiz.querySelector('[data-caixa-kpi="saldo"]');
            saldo.textContent = r.saldo;
            saldo.classList.toggle('viagensfield-negativo', !!r.saldo_baixo);
            Object.keys(r.totais || {}).forEach(function (k) {
                var alvo = raiz.querySelector('[data-caixa-kpi="' + k + '"]');
                if (alvo) { alvo.textContent = r.totais[k]; }
            });
            var alerta = document.getElementById('viagensfield-saldo-baixo');
            alerta.hidden = !r.saldo_baixo;
            alerta.querySelector('span').textContent = r.saldo_baixo
                ? 'O saldo está ' + (r.minimo ? 'abaixo do mínimo configurado (' + r.minimo + ')' : 'negativo') + '. Registre uma entrada para repor o caixa.'
                : '';

            caixaLinhas = r.lancamentos || [];
            if (!caixaLinhas.length) {
                corpo.innerHTML = '<tr><td colspan="7"><div class="viagensfield-vazio"><i class="ti ti-mood-empty"></i><span>Nenhum lançamento no período.</span></div></td></tr>';
                return;
            }
            corpo.innerHTML = caixaLinhas.map(function (l) {
                var detalhe = esc(l.observacao);
                if (l.viagens_id) {
                    detalhe += (detalhe ? '<br>' : '') + '<small>' + l.item + '</small>';
                }
                return '<tr>'
                    + '<td class="text-nowrap">' + esc(l.data) + '</td>'
                    + '<td><span class="viagensfield-tipo viagensfield-tipo-' + esc(l.tipo) + '">' + esc(l.tipo_nome) + '</span></td>'
                    + '<td>' + detalhe + '</td>'
                    + '<td>' + esc(l.usuario) + '</td>'
                    + '<td class="text-center viagensfield-docs">' + (linksDocs(l.docs) || '<span class="text-muted">—</span>') + '</td>'
                    + '<td class="text-end text-nowrap ' + (l.credito ? 'viagensfield-credito' : 'viagensfield-debito') + '">' + esc(l.valor_fmt) + '</td>'
                    + '<td class="text-end text-nowrap">' + esc(l.saldo_posterior) + '</td>'
                    + '</tr>';
            }).join('');
        }).catch(function () {
            corpo.innerHTML = '<tr><td colspan="7" class="viagensfield-carregando">Falha de comunicação com o servidor.</td></tr>';
        });
    }

    function registrarLancamento(form) {
        var botao = form.querySelector('button[type="submit"]');
        botao.disabled = true;
        enviar('caixa_movimentar', new FormData(form)).then(function (r) {
            botao.disabled = false;
            if (!r || !r.success) {
                aviso((r && r.mensagem) || 'Não foi possível registrar.', true);
                return;
            }
            aviso(r.mensagem, false);
            form.querySelector('[name="valor"]').value = '';
            form.querySelector('[name="observacao"]').value = '';
            carregarCaixa();
        }).catch(function () {
            botao.disabled = false;
            aviso('Falha de comunicação com o servidor.', true);
        });
    }

    // ------------------------------------------------------------------ início
    function iniciar() {
        raiz = document.getElementById('viagensfield-painel');
        if (!raiz) { return; }
        ajaxUrl = raiz.getAttribute('data-ajax');
        token = raiz.getAttribute('data-token') || '';

        raiz.querySelectorAll('[data-viagensfield-aba]').forEach(function (l) {
            l.addEventListener('click', function (ev) {
                ev.preventDefault();
                mostrarAba(l.getAttribute('data-viagensfield-aba'));
            });
        });

        var filtros = document.getElementById('viagensfield-filtros');
        if (filtros) {
            filtros.addEventListener('submit', function (ev) { ev.preventDefault(); carregarViagens(); });
            filtros.querySelector('[data-viagensfield-limpar]').addEventListener('click', function () {
                ['de', 'ate'].forEach(function (n) { definirCampo(filtros, n, ''); });
                definirCampo(filtros, 'tecnico', '0');
                definirCampo(filtros, 'entidade', '-1');
                definirCampo(filtros, 'status', '');
                definirCampo(filtros, 'itemtype', '');
                carregarViagens();
            });

            var busca = document.getElementById('viagensfield-busca');
            busca.addEventListener('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(function () {
                    visao.termo = busca.value.trim();
                    visao.pagina = 1;
                    renderizarTabela();
                }, 300);
            });
            document.getElementById('viagensfield-por-pagina').addEventListener('change', function (ev) {
                visao.porPagina = parseInt(ev.target.value, 10) || 25;
                visao.pagina = 1;
                renderizarTabela();
            });
            raiz.querySelectorAll('#viagensfield-tabela-viagens th[data-sort]').forEach(function (th) {
                th.addEventListener('click', function () {
                    var col = th.getAttribute('data-sort');
                    visao.sort = { column: col, direction: visao.sort.column === col && visao.sort.direction === 'asc' ? 'desc' : 'asc' };
                    renderizarTabela();
                });
            });
            document.getElementById('viagensfield-paginacao').addEventListener('click', function (ev) {
                var b = ev.target.closest('[data-pagina]');
                if (b && !b.disabled) {
                    visao.pagina = parseInt(b.getAttribute('data-pagina'), 10) || 1;
                    renderizarTabela();
                }
            });
            carregarViagens();
        }

        raiz.addEventListener('click', function (ev) {
            var exp = ev.target.closest('[data-viagensfield-exportar]');
            if (!exp) { return; }
            var tipo = exp.getAttribute('data-viagensfield-exportar');
            if (tipo === 'csv') { exportarViagens(); }
            if (tipo === 'imprimir') { imprimirViagens(); }
            if (tipo === 'caixa') { exportarCaixa(); }
        });

        var formCaixa = document.getElementById('viagensfield-form-caixa');
        if (formCaixa) {
            formCaixa.addEventListener('submit', function (ev) { ev.preventDefault(); registrarLancamento(formCaixa); });
            document.getElementById('viagensfield-filtros-caixa').addEventListener('submit', function (ev) { ev.preventDefault(); carregarCaixa(); });
        }

        var aba = raiz.getAttribute('data-aba');
        if (aba === 'caixa') { carregarCaixa(); }

        window.addEventListener('resize', function () {
            Object.keys(graficos).forEach(function (k) { if (graficos[k] && !graficos[k].isDisposed()) { graficos[k].resize(); } });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
