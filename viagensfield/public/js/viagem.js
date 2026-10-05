/**
 * Plugin Viagens Field - formulário da viagem e caixa:
 * máscaras de valor (1.234,56) e km (12,5) e envio de comprovantes (fotos ou documentos).
 * Por delegação: vale para formulários que aparecem depois (abas carregadas por AJAX).
 */
(function () {
    'use strict';

    if (window.viagensfieldViagemJs) { return; }
    window.viagensfieldViagemJs = true;

    // ------------------------------------------------------------------ máscaras
    function formatarMoeda(digitos) {
        digitos = String(digitos || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, 11);
        if (digitos === '') { return ''; }
        while (digitos.length < 3) { digitos = '0' + digitos; }
        var inteiro = digitos.slice(0, -2).replace(/^0+(?=\d)/, '');
        var centavos = digitos.slice(-2);
        return inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + centavos;
    }

    function formatarKm(texto) {
        texto = String(texto || '').replace(/\./g, ',').replace(/[^\d,]/g, '');
        var partes = texto.split(',');
        var inteiro = partes.shift().slice(0, 7);
        if (partes.length === 0) { return inteiro; }
        return (inteiro || '0') + ',' + partes.join('').slice(0, 1);
    }

    document.addEventListener('input', function (ev) {
        var campo = ev.target;
        if (!(campo instanceof HTMLInputElement)) { return; }
        if (campo.hasAttribute('data-viagensfield-moeda')) {
            var antes = campo.value.length - campo.selectionStart;
            campo.value = formatarMoeda(campo.value);
            var pos = Math.max(0, campo.value.length - antes);
            campo.setSelectionRange(pos, pos);
        } else if (campo.hasAttribute('data-viagensfield-km')) {
            campo.value = formatarKm(campo.value);
        }
    });

    // Valor já gravado (ex.: "12,5") chega sem as duas casas: completa ao sair do campo
    document.addEventListener('focusout', function (ev) {
        var campo = ev.target;
        if (campo instanceof HTMLInputElement && campo.hasAttribute('data-viagensfield-moeda') && campo.value !== '' && !/,\d{2}$/.test(campo.value)) {
            var partes = campo.value.replace(/\./g, '').split(',');
            campo.value = formatarMoeda(partes[0] + ((partes[1] || '') + '00').slice(0, 2));
        }
    });

    // ------------------------------------------------------------------ comprovantes
    var LADO_MAXIMO = 1920;
    var REDUZIR_ACIMA = 1.5 * 1024 * 1024;

    function esc(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function aviso(mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') { fn(mensagem); }
    }

    function tamanhoTexto(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB'; }
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function icone(nome, imagem) {
        if (imagem) { return 'ti-photo'; }
        var ext = String(nome).split('.').pop().toLowerCase();
        if (ext === 'pdf') { return 'ti-file-type-pdf'; }
        if (['doc', 'docx', 'odt'].indexOf(ext) !== -1) { return 'ti-file-type-doc'; }
        if (['xls', 'xlsx', 'ods', 'csv'].indexOf(ext) !== -1) { return 'ti-file-spreadsheet'; }
        if (['zip', 'rar'].indexOf(ext) !== -1) { return 'ti-file-zip'; }
        return 'ti-file';
    }

    /** Lê a resposta como texto e tolera avisos do PHP antes do JSON */
    function lerJson(resposta) {
        return resposta.text().then(function (texto) {
            try { return JSON.parse(texto); } catch (e) {
                var m = texto.match(/\{[\s\S]*\}\s*$/);
                if (m) { try { return JSON.parse(m[0]); } catch (e2) { /* segue */ } }
                return { success: false, mensagem: 'Resposta inválida do servidor.' };
            }
        });
    }

    function postar(caixa, acao, dados) {
        var fd = new FormData();
        fd.append('action', acao);
        Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        var token = caixa.getAttribute('data-token') || '';
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        if (token) {
            fd.append('_glpi_csrf_token', token);
            cab['X-Glpi-Csrf-Token'] = token;
        }
        return fetch(caixa.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', headers: cab, body: fd })
            .then(lerJson)
            .then(function (r) {
                if (r && r.new_token) { caixa.setAttribute('data-token', r.new_token); }
                if (!r || !r.success) { throw new Error((r && r.mensagem) || 'Falha no envio.'); }
                return r;
            });
    }

    /** Fotos grandes viram JPEG de até 1920 px (cabe no limite do servidor e economiza espaço) */
    function prepararArquivo(arquivo) {
        var reduzivel = /^image\/(jpeg|png|webp|bmp)$/.test(arquivo.type);
        if (!reduzivel || arquivo.size <= REDUZIR_ACIMA) {
            return Promise.resolve(arquivo);
        }
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(arquivo);
            var img = new Image();
            img.onload = function () {
                var escala = Math.min(1, LADO_MAXIMO / Math.max(img.naturalWidth, img.naturalHeight));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(img.naturalWidth * escala);
                canvas.height = Math.round(img.naturalHeight * escala);
                var ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                canvas.toBlob(function (blob) {
                    if (!blob || blob.size >= arquivo.size) { resolve(arquivo); return; }
                    var nome = arquivo.name.replace(/\.[^.]+$/, '') + '.jpg';
                    try {
                        resolve(new File([blob], nome, { type: 'image/jpeg' }));
                    } catch (e) {
                        blob.name = nome;
                        resolve(blob);
                    }
                }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(arquivo); };
            img.src = url;
        });
    }

    function pendentes(caixa, delta) {
        var n = Math.max(0, (parseInt(caixa.getAttribute('data-pendentes') || '0', 10) || 0) + delta);
        caixa.setAttribute('data-pendentes', String(n));
        var form = caixa.closest('form');
        if (form) {
            form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) {
                if (n > 0) { b.setAttribute('data-viagensfield-bloqueado', '1'); } else { b.removeAttribute('data-viagensfield-bloqueado'); }
            });
        }
    }

    function enviarArquivo(caixa, original) {
        var lista = caixa.querySelector('.viagensfield-comprovantes-lista');
        var item = document.createElement('div');
        item.className = 'viagensfield-comprovante viagensfield-comprovante-enviando';
        item.innerHTML = '<i class="ti ' + icone(original.name, /^image\//.test(original.type)) + '"></i>'
            + '<span>' + esc(original.name) + '</span>'
            + '<div class="viagensfield-progresso"><div></div></div>'
            + '<button type="button" class="viagensfield-comprovante-remover" title="Remover"><i class="ti ti-x"></i></button>';
        lista.appendChild(item);
        var barra = item.querySelector('.viagensfield-progresso > div');
        var maximo = parseInt(caixa.getAttribute('data-maximo'), 10) || 20971520;
        var tamanhoParte = parseInt(caixa.getAttribute('data-parte'), 10) || 1048576;
        var cancelado = false;
        var idEnvio = '';
        pendentes(caixa, 1);

        item.querySelector('.viagensfield-comprovante-remover').addEventListener('click', function () {
            cancelado = true;
            var oculto = idEnvio ? caixa.closest('form').querySelector('input[name="_viagensfield_comprovantes[]"][value="' + idEnvio + '"]') : null;
            if (oculto) { oculto.remove(); }
            if (idEnvio) { postar(caixa, 'comprovante_cancelar', { id: idEnvio }).catch(function () { /* nada a fazer */ }); }
            if (item.classList.contains('viagensfield-comprovante-enviando')) { pendentes(caixa, -1); }
            item.remove();
        });

        return prepararArquivo(original).then(function (arquivo) {
            if (cancelado) { return null; }
            var nome = arquivo.name || original.name;
            if (arquivo.size > maximo) { throw new Error('O arquivo ' + nome + ' passa de 20 MB.'); }
            var imagem = /^image\//.test(arquivo.type);
            if (imagem) {
                var miniatura = document.createElement('img');
                miniatura.src = URL.createObjectURL(arquivo);
                miniatura.alt = nome;
                item.replaceChild(miniatura, item.querySelector('i'));
            }
            item.querySelector('span').textContent = nome + ' (' + tamanhoTexto(arquivo.size) + ')';
            return postar(caixa, 'comprovante_iniciar', { nome: nome, tamanho: arquivo.size }).then(function (r) {
                idEnvio = r.id;
                var total = Math.max(1, Math.ceil(arquivo.size / tamanhoParte));
                var indice = 0;
                function proxima() {
                    if (cancelado) { return Promise.resolve(null); }
                    if (indice >= total) { return postar(caixa, 'comprovante_concluir', { id: idEnvio }); }
                    var parte = arquivo.slice(indice * tamanhoParte, Math.min(arquivo.size, (indice + 1) * tamanhoParte));
                    return postar(caixa, 'comprovante_parte', { id: idEnvio, indice: indice, parte: parte }).then(function () {
                        indice++;
                        barra.style.width = Math.round(indice / total * 100) + '%';
                        return proxima();
                    });
                }
                return proxima();
            });
        }).then(function (r) {
            if (!r || cancelado) { return; }
            var oculto = document.createElement('input');
            oculto.type = 'hidden';
            oculto.name = '_viagensfield_comprovantes[]';
            oculto.value = idEnvio;
            caixa.appendChild(oculto);
            item.classList.remove('viagensfield-comprovante-enviando');
            item.classList.add('viagensfield-comprovante-pronto');
            item.title = 'Enviado. Será anexado ao salvar a viagem.';
            pendentes(caixa, -1);
        }).catch(function (erro) {
            if (cancelado) { return; }
            item.classList.remove('viagensfield-comprovante-enviando');
            item.classList.add('viagensfield-comprovante-erro');
            item.title = erro.message;
            pendentes(caixa, -1);
            aviso(erro.message, true);
        });
    }

    document.addEventListener('change', function (ev) {
        var campo = ev.target;
        if (!(campo instanceof HTMLInputElement) || !campo.hasAttribute('data-viagensfield-comprovantes-arquivo')) { return; }
        var caixa = campo.closest('[data-viagensfield-comprovantes]');
        var arquivos = Array.prototype.slice.call(campo.files || []);
        campo.value = '';
        // Um de cada vez: mantém a ordem e o token CSRF sincronizado
        arquivos.reduce(function (fila, arquivo) {
            return fila.then(function () { return enviarArquivo(caixa, arquivo); });
        }, Promise.resolve());
    });

    // Não deixa salvar a viagem com comprovante ainda subindo
    document.addEventListener('click', function (ev) {
        var botao = ev.target.closest('[data-viagensfield-bloqueado]');
        if (botao) {
            ev.preventDefault();
            ev.stopPropagation();
            aviso('Aguarde o envio dos comprovantes terminar.', true);
        }
    }, true);
})();
