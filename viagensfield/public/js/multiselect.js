/**
 * Plugin Viagens Field - multiselect com pesquisa (perfis e usuários da configuração).
 * Selecionados primeiro, marcar/desmarcar todos respeitando a pesquisa,
 * busca limpa após cada seleção e fechamento ao clicar fora.
 */
(function () {
    'use strict';

    function opcoes(container) {
        return Array.prototype.slice.call(container.querySelectorAll('.viagensfield-ms-opcao'));
    }

    function viagensfieldUpdateSelectAll(container) {
        var todos = container.querySelector('[data-viagensfield-ms-todos]');
        if (!todos) { return; }
        var visiveis = opcoes(container).filter(function (o) { return o.style.display !== 'none'; });
        var marcados = visiveis.filter(function (o) { return o.querySelector('input').checked; });
        todos.checked = visiveis.length > 0 && marcados.length === visiveis.length;
        todos.indeterminate = marcados.length > 0 && marcados.length < visiveis.length;
    }

    function viagensfieldUpdateMultiselectCount(container) {
        var marcados = opcoes(container).filter(function (o) { return o.querySelector('input').checked; });
        var texto = container.querySelector('.viagensfield-ms-texto');
        if (marcados.length === 0) {
            texto.textContent = container.getAttribute('data-placeholder') || 'Selecione...';
            texto.classList.add('viagensfield-ms-vazio');
        } else if (marcados.length <= 2) {
            texto.textContent = marcados.map(function (o) { return o.querySelector('span').textContent; }).join(', ');
            texto.classList.remove('viagensfield-ms-vazio');
        } else {
            texto.textContent = marcados.length + ' selecionados';
            texto.classList.remove('viagensfield-ms-vazio');
        }
        container.querySelector('.viagensfield-ms-contador').textContent = marcados.length + ' de ' + opcoes(container).length + ' selecionado(s)';
        viagensfieldUpdateSelectAll(container);
    }

    function viagensfieldReorderMultiselectOptions(container) {
        var lista = container.querySelector('.viagensfield-ms-opcoes');
        opcoes(container).sort(function (a, b) {
            var ca = a.querySelector('input').checked, cb = b.querySelector('input').checked;
            if (ca !== cb) { return ca ? -1 : 1; }
            return a.getAttribute('data-label').localeCompare(b.getAttribute('data-label'), 'pt-BR');
        }).forEach(function (o) { lista.appendChild(o); });
    }

    function viagensfieldFilterMultiselect(container, termo) {
        termo = (termo || '').toLowerCase().trim();
        opcoes(container).forEach(function (o) {
            o.style.display = !termo || o.getAttribute('data-label').indexOf(termo) !== -1 ? 'flex' : 'none';
        });
        viagensfieldUpdateSelectAll(container);
    }

    function fechar(container) {
        container.querySelector('.viagensfield-ms-dropdown').hidden = true;
        container.classList.remove('viagensfield-ms-aberto');
    }

    function viagensfieldToggleMultiselect(container) {
        var dropdown = container.querySelector('.viagensfield-ms-dropdown');
        var abrir = dropdown.hidden;
        document.querySelectorAll('[data-viagensfield-ms]').forEach(function (c) {
            if (c !== container) { fechar(c); }
        });
        dropdown.hidden = !abrir;
        container.classList.toggle('viagensfield-ms-aberto', abrir);
        if (abrir) {
            container.querySelector('.viagensfield-ms-busca').focus();
        }
    }

    function viagensfieldToggleAllMultiselect(container, marcar) {
        opcoes(container).forEach(function (o) {
            if (o.style.display === 'none') { return; }
            o.querySelector('input').checked = marcar;
            o.classList.toggle('selected', marcar);
        });
        viagensfieldReorderMultiselectOptions(container);
        viagensfieldUpdateMultiselectCount(container);
    }

    function viagensfieldHandleMultiselectChange(container, opcao) {
        opcao.classList.toggle('selected', opcao.querySelector('input').checked);
        var busca = container.querySelector('.viagensfield-ms-busca');
        if (busca.value !== '') {
            busca.value = '';
            viagensfieldFilterMultiselect(container, '');
            busca.focus();
        }
        viagensfieldReorderMultiselectOptions(container);
        viagensfieldUpdateMultiselectCount(container);
    }

    function viagensfieldGetMultiselectValues(container) {
        return opcoes(container).filter(function (o) { return o.querySelector('input').checked; })
            .map(function (o) { return o.querySelector('input').value; });
    }

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-viagensfield-ms-abrir]');
        if (abrir) {
            viagensfieldToggleMultiselect(abrir.closest('[data-viagensfield-ms]'));
            return;
        }
        document.querySelectorAll('[data-viagensfield-ms]').forEach(function (c) {
            if (!c.contains(e.target)) { fechar(c); }
        });
    });

    document.addEventListener('change', function (e) {
        var container = e.target.closest('[data-viagensfield-ms]');
        if (!container) { return; }
        if (e.target.matches('[data-viagensfield-ms-todos]')) {
            viagensfieldToggleAllMultiselect(container, e.target.checked);
            return;
        }
        var opcao = e.target.closest('.viagensfield-ms-opcao');
        if (opcao) {
            viagensfieldHandleMultiselectChange(container, opcao);
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.viagensfield-ms-busca')) {
            viagensfieldFilterMultiselect(e.target.closest('[data-viagensfield-ms]'), e.target.value);
        }
    });

    // Enter na busca não envia o formulário
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.viagensfield-ms-busca')) {
            e.preventDefault();
        }
    });

    function iniciar() {
        document.querySelectorAll('[data-viagensfield-ms]').forEach(viagensfieldUpdateMultiselectCount);
    }

    window.viagensfieldMultiselect = { iniciar: iniciar, valores: viagensfieldGetMultiselectValues };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
