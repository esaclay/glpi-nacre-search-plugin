(function () {
    'use strict';

    // Éditeur de lignes de commande NACRES (code + désignation + montant HT).
    //
    // Se monte sur :
    //  - la question texte court « Lignes NACRES » du catalogue de formulaires
    //    (libellé contenant l'indice `nacresearch:orderlines-hint`) — montant et
    //    désignation obligatoires ;
    //  - le textarea `[data-nacresearch-orderlines-target]` de l'onglet ticket
    //    « Lignes NACRES » — montant facultatif (lignes reprises sans montant).
    //
    // Le champ d'origine est masqué et reçoit le format texte d'échange relu par
    // le serveur (OrderLines::parse()) :
    //     NA.01 | Pipettes | 120,00 € HT ; NB.12 | Gants | 45,50 € HT
    // Tant qu'une ligne est incomplète, le champ est vidé : la question
    // obligatoire bloque alors l'envoi, aucune ligne n'est perdue en silence.

    var AMOUNT_RE = /^\d+(?:\.\d{1,2})?$/;
    var money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

    function trim(value) {
        return String(value || '').trim();
    }

    function readHint() {
        // Meta présente mais vide = éditeur désactivé.
        var tag = document.querySelector('meta[name="nacresearch:orderlines-hint"]');
        return tag ? tag.content : 'lignes nacre';
    }

    // Miroir de OrderLines::parseAmount() : null si vide, NaN si illisible ou ≤ 0.
    function parseAmount(value) {
        var clean = String(value || '')
            .replace(/€|eur(os?)?|ht/gi, '')
            .replace(/[\s  ]+/g, '');
        if (clean === '') {
            return null;
        }
        if (clean.indexOf(',') !== -1) {
            clean = clean.replace(/\./g, '').replace(',', '.');
        }
        if (!AMOUNT_RE.test(clean)) {
            return NaN;
        }
        var amount = parseFloat(clean);
        return amount > 0 ? amount : NaN;
    }

    function cleanDesignation(value) {
        return trim(String(value || '').replace(/\s*[|;\r\n]+\s*/g, ', '));
    }

    function parseText(text) {
        return String(text || '')
            .split(/[;\r\n]+/)
            .map(trim)
            .filter(Boolean)
            .map(function (fragment) {
                var parts = fragment.split('|').map(trim);
                return {
                    code: (parts[0] || '').replace(/\s+/g, '').toUpperCase(),
                    label: '',
                    designation: parts[1] || '',
                    amount: (parts[2] || '').replace(/\s*(€|eur)?\s*(ht)?\s*$/i, '')
                };
            });
    }

    function serialize(rows) {
        return rows.map(function (row) {
            var chunk = row.code + ' | ' + cleanDesignation(row.designation);
            var amount = parseAmount(row.amount);
            if (amount !== null) {
                chunk += ' | ' + amount.toFixed(2).replace('.', ',') + ' € HT';
            }
            return chunk;
        }).join(' ; ');
    }

    function isBlank(row) {
        return !row.code && !trim(row.designation) && !trim(row.amount);
    }

    function validate(row, strict) {
        var errors = [];
        if (!/^[A-Z]{2}\.\d{2}$/.test(row.code)) {
            errors.push('code NACRE à choisir');
        }
        if (strict && !trim(row.designation)) {
            errors.push('désignation manquante');
        }
        var amount = parseAmount(row.amount);
        if (amount === null) {
            if (strict) {
                errors.push('montant HT manquant');
            }
        } else if (isNaN(amount)) {
            errors.push('montant HT illisible (ex. 120,50)');
        }
        return errors;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function mount(field, strict) {
        var api = window.NacreSearch;
        if (!api || field.dataset.nacresearchOrderlines === '1') {
            return;
        }
        field.dataset.nacresearchOrderlines = '1';
        // Empêche nacre-search.js d'y ajouter le bouton simple.
        field.dataset.nacresearchEnhanced = '1';

        var rows = parseText(field.value);
        var showErrors = false;

        var root = el('div', 'nacresearch-lines');
        var table = el('table', 'table table-sm nacresearch-lines__table');
        var thead = el('thead');
        var headRow = el('tr');
        ['Code NACRE', 'Désignation', 'Montant HT (€)', ''].forEach(function (title, index) {
            var th = el('th', index === 2 ? 'text-end' : '', title);
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        var tbody = el('tbody');
        var tfoot = el('tfoot');
        var footRow = el('tr');
        var totalLabel = el('th', '', 'Total HT');
        totalLabel.colSpan = 2;
        var totalCell = el('th', 'text-end text-nowrap');
        footRow.appendChild(totalLabel);
        footRow.appendChild(totalCell);
        footRow.appendChild(el('th'));
        tfoot.appendChild(footRow);
        table.appendChild(thead);
        table.appendChild(tbody);
        table.appendChild(tfoot);

        var addButton = el('button', 'btn btn-sm btn-outline-primary', '+ Ajouter une ligne');
        addButton.type = 'button';
        var errorBox = el('div', 'nacresearch-lines__errors');
        errorBox.hidden = true;

        root.appendChild(table);
        root.appendChild(addButton);
        root.appendChild(errorBox);

        field.classList.add('nacresearch-lines__source');
        field.setAttribute('tabindex', '-1');
        field.insertAdjacentElement('afterend', root);

        function setCode(row, record) {
            row.code = record.code;
            row.label = record.label || '';
            row.codeButton.textContent = row.code;
            row.codeButton.classList.remove('btn-outline-primary');
            row.codeButton.classList.add('btn-outline-secondary');
            row.labelEl.textContent = row.label;
        }

        function buildRow(row) {
            var tr = el('tr');

            var codeCell = el('td', 'nacresearch-lines__code');
            row.codeButton = el('button', 'btn btn-sm ' + (row.code ? 'btn-outline-secondary' : 'btn-outline-primary'), row.code || 'Choisir…');
            row.codeButton.type = 'button';
            row.codeButton.title = 'Rechercher un code NACRE';
            row.labelEl = el('div', 'nacresearch-lines__label', row.label);
            row.codeButton.addEventListener('click', function () {
                api.pickCode({
                    query: row.code,
                    returnFocus: row.codeButton,
                    onSelect: function (record) {
                        setCode(row, record);
                        sync();
                    }
                });
            });
            codeCell.appendChild(row.codeButton);
            codeCell.appendChild(row.labelEl);

            var designationCell = el('td');
            var designation = el('input', 'form-control form-control-sm');
            designation.type = 'text';
            designation.placeholder = 'Article du devis';
            designation.value = row.designation;
            designation.setAttribute('aria-label', 'Désignation');
            designation.addEventListener('input', function () {
                row.designation = designation.value;
                sync();
            });
            designationCell.appendChild(designation);

            var amountCell = el('td');
            var amount = el('input', 'form-control form-control-sm text-end nacresearch-lines__amount');
            amount.type = 'text';
            amount.inputMode = 'decimal';
            amount.placeholder = strict ? '0,00' : 'à compléter';
            amount.value = row.amount;
            amount.setAttribute('aria-label', 'Montant HT en euros');
            amount.addEventListener('input', function () {
                row.amount = amount.value;
                sync();
            });
            amountCell.appendChild(amount);

            var removeCell = el('td', 'text-end');
            var remove = el('button', 'btn btn-sm btn-link text-danger nacresearch-lines__remove', '×');
            remove.type = 'button';
            remove.title = 'Retirer la ligne';
            remove.setAttribute('aria-label', 'Retirer la ligne');
            remove.addEventListener('click', function () {
                rows.splice(rows.indexOf(row), 1);
                tr.remove();
                if (!rows.length) {
                    addRow();
                }
                sync();
            });
            removeCell.appendChild(remove);

            tr.appendChild(codeCell);
            tr.appendChild(designationCell);
            tr.appendChild(amountCell);
            tr.appendChild(removeCell);
            row.tr = tr;
            return tr;
        }

        function addRow() {
            var row = { code: '', label: '', designation: '', amount: '' };
            rows.push(row);
            tbody.appendChild(buildRow(row));
            return row;
        }

        function collect() {
            var errors = [];
            var complete = [];
            var total = 0;
            rows.forEach(function (row, index) {
                if (isBlank(row)) {
                    row.tr.classList.remove('nacresearch-lines__row--invalid');
                    return;
                }
                var rowErrors = validate(row, strict);
                row.tr.classList.toggle('nacresearch-lines__row--invalid', showErrors && rowErrors.length > 0);
                if (rowErrors.length) {
                    errors.push('Ligne ' + (index + 1) + ' : ' + rowErrors.join(', ') + '.');
                    return;
                }
                complete.push(row);
                var value = parseAmount(row.amount);
                if (value) {
                    total += value;
                }
            });
            return { errors: errors, complete: complete, total: total };
        }

        function sync() {
            var state = collect();
            totalCell.textContent = money.format(state.total);

            errorBox.hidden = !(showErrors && state.errors.length);
            errorBox.textContent = '';
            state.errors.forEach(function (message) {
                errorBox.appendChild(el('div', '', message));
            });

            var value = state.errors.length ? '' : serialize(state.complete);
            if (field.value !== value) {
                api.setFieldValue(field, value);
            }
            return state;
        }

        rows.forEach(function (row) {
            tbody.appendChild(buildRow(row));
        });
        if (!rows.length) {
            addRow();
        }

        addButton.addEventListener('click', function () {
            var row = addRow();
            sync();
            row.codeButton.focus();
        });

        // Erreurs affichées dès que l'utilisateur quitte l'éditeur, pas à la
        // première frappe.
        root.addEventListener('focusout', function (event) {
            var target = event.relatedTarget;
            // Ouverture de la modale de recherche : l'utilisateur n'a pas fini.
            if (target && target.closest && target.closest('.nacresearch-modal')) {
                return;
            }
            if (!root.contains(target)) {
                showErrors = true;
                sync();
            }
        });

        // Envoi bloqué par la validation native (question obligatoire vidée
        // car une ligne est incomplète) : l'événement submit n'a pas lieu,
        // on affiche quand même la raison.
        field.addEventListener('invalid', function () {
            showErrors = true;
            sync();
            root.scrollIntoView({ block: 'center' });
        });

        if (field.form) {
            field.form.addEventListener('submit', function (event) {
                showErrors = true;
                var state = sync();
                // Onglet ticket : un champ vidé effacerait toutes les lignes.
                if (!strict && state.errors.length) {
                    event.preventDefault();
                    event.stopPropagation();
                    errorBox.scrollIntoView({ block: 'center' });
                }
            }, true);
        }

        // Libellés NACRE des lignes déjà renseignées (rechargement, onglet ticket).
        api.fetchData().then(function (records) {
            var labels = {};
            records.forEach(function (record) {
                labels[record.code] = record.label;
            });
            rows.forEach(function (row) {
                if (row.code && !row.label && labels[row.code]) {
                    row.label = labels[row.code];
                    row.labelEl.textContent = row.label;
                }
            });
        }).catch(function () {
            // Sans données NACRE, l'éditeur reste utilisable sans libellés.
        });

        sync();
    }

    function consider(node, hint) {
        // Champs internes de l'éditeur (dans la même question du formulaire).
        if (node.closest('.nacresearch-lines')) {
            return;
        }
        if (node.matches('[data-nacresearch-orderlines-target]')) {
            mount(node, node.dataset.nacresearchOrderlinesAmountRequired !== '0');
            return;
        }
        if (
            hint
            && node.matches('input[type="text"]')
            && window.NacreSearch
            && window.NacreSearch.isCatalogQuestionField(node, hint)
        ) {
            mount(node, true);
        }
    }

    function scan(root, hint) {
        if (root.matches && root.matches('input, textarea')) {
            consider(root, hint);
        }
        if (root.querySelectorAll) {
            root.querySelectorAll('input, textarea').forEach(function (node) {
                consider(node, hint);
            });
        }
    }

    function bootstrap() {
        if (!window.NacreSearch) {
            return;
        }
        var hint = window.NacreSearch.normalize(readHint());
        scan(document, hint);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        scan(node, hint);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
