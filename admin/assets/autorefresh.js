/* FastBite Admin - aggiornamenti leggeri senza ricaricare la pagina. */
(function () {
    'use strict';

    var TABLE_INTERVAL = 10000;
    var CATEGORY_INTERVAL = 15000;

    function isVisible() {
        return document.visibilityState === 'visible';
    }

    function fragmentUrl(name) {
        var url = new URL(window.location.href);
        url.searchParams.set('fragment', name);
        return url.toString();
    }

    function fetchFragment(name) {
        return fetch(fragmentUrl(name), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            return response.ok ? response.text() : null;
        });
    }

    /* Elenco prodotti: sostituisce solo il contenuto della tabella. */
    var listPanel = document.querySelector('[data-auto-refresh="products-table"]');
    var tableScroll = listPanel ? listPanel.querySelector('.table-scroll') : null;
    if (tableScroll) {
        var tableBusy = false;
        var countEl = document.getElementById('product-count');

        var refreshTable = function () {
            if (tableBusy || !isVisible()) return;
            // Non interrompere un'azione in corso sulla tabella.
            if (tableScroll.matches(':hover') || tableScroll.contains(document.activeElement)) return;

            tableBusy = true;
            fetchFragment('table').then(function (html) {
                if (html === null) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('.table-scroll');
                if (!fresh || fresh.innerHTML === tableScroll.innerHTML) return;

                tableScroll.innerHTML = fresh.innerHTML;
                tableScroll.setAttribute('data-count', fresh.getAttribute('data-count') || '0');
                if (countEl) countEl.textContent = tableScroll.getAttribute('data-count');
            }).catch(function () {
                /* rete momentaneamente assente: riprova al prossimo giro */
            }).then(function () {
                tableBusy = false;
            });
        };

        setInterval(refreshTable, TABLE_INTERVAL);
    }

    /* Form prodotto: aggiorna solo le checkbox delle categorie,
       mantenendo quelle già selezionate dall'utente. */
    var categoryBox = document.querySelector('[data-auto-refresh="category-checks"]');
    var categoryOptions = categoryBox ? categoryBox.querySelector('[data-category-options]') : null;
    if (categoryOptions) {
        var categoryBusy = false;

        var selectedValues = function () {
            var values = [];
            categoryOptions.querySelectorAll('input[name="categories[]"]').forEach(function (input) {
                if (input.checked) values.push(input.value);
            });
            return values;
        };

        // Firma = id + nome di ogni categoria, per capire se è cambiato qualcosa.
        var categorySignature = function (root) {
            var entries = [];
            root.querySelectorAll('label.check').forEach(function (label) {
                var input = label.querySelector('input');
                entries.push((input ? input.value : '') + '=' + label.textContent.trim());
            });
            return entries.length > 0 ? entries.join('|') : root.textContent.trim();
        };

        var refreshCategories = function () {
            if (categoryBusy || !isVisible()) return;

            categoryBusy = true;
            var selected = selectedValues();
            fetchFragment('category_checks').then(function (html) {
                if (html === null) return;
                var holder = document.createElement('div');
                holder.innerHTML = html;
                if (categorySignature(holder) === categorySignature(categoryOptions)) return;

                categoryOptions.innerHTML = holder.innerHTML;
                categoryOptions.querySelectorAll('input[name="categories[]"]').forEach(function (input) {
                    input.checked = selected.indexOf(input.value) !== -1;
                });
            }).catch(function () {
                /* rete momentaneamente assente: riprova al prossimo giro */
            }).then(function () {
                categoryBusy = false;
            });
        };

        setInterval(refreshCategories, CATEGORY_INTERVAL);
    }
})();
