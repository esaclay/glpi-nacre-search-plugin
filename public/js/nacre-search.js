(function () {
    'use strict';

    var state = {
        data: null,
        loading: null,
        activeField: null,
        triggerClass: 'nacresearch-trigger',
        modal: null,
        refs: null,
        resultLimit: 100,
        debounceMs: 120,
        dataUrl: '/plugins/nacresearch/data/nacre.json',
        buttonLabel: 'NACRE',
        modalTitle: 'Recherche de code NACRE'
    };

    function readMeta(name, fallback) {
        var tag = document.querySelector('meta[name="' + name + '"]');
        return tag && tag.content ? tag.content : fallback;
    }

    function loadRuntimeConfig() {
        state.resultLimit = parseInt(readMeta('nacresearch:result-limit', String(state.resultLimit)), 10) || state.resultLimit;
        state.debounceMs = parseInt(readMeta('nacresearch:debounce-ms', String(state.debounceMs)), 10) || state.debounceMs;
        state.dataUrl = readMeta('nacresearch:data-url', state.dataUrl);
        state.buttonLabel = readMeta('nacresearch:button-label', state.buttonLabel);
        state.modalTitle = readMeta('nacresearch:modal-title', state.modalTitle);
    }

    function fetchData() {
        if (state.data) {
            return Promise.resolve(state.data);
        }

        if (state.loading) {
            return state.loading;
        }

        state.loading = window.fetch(state.dataUrl, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Impossible de charger les données NACRE.');
                }
                return response.json();
            })
            .then(function (payload) {
                state.data = Array.isArray(payload) ? payload : [];
                return state.data;
            })
            .finally(function () {
                state.loading = null;
            });

        return state.loading;
    }

    function normalize(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function fieldMatches(field) {
        var candidates = [field.name, field.id, field.placeholder, field.getAttribute('aria-label')];
        return candidates.some(function (value) {
            return normalize(value).indexOf('nacre') !== -1;
        });
    }

    function createModal() {
        if (state.modal) {
            return state.modal;
        }

        var modal = document.createElement('div');
        modal.className = 'nacresearch-modal';
        modal.hidden = true;
        modal.innerHTML = [
            '<div class="nacresearch-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nacresearch-modal-title">',
            '  <div class="nacresearch-modal__header">',
            '    <h2 id="nacresearch-modal-title">' + escapeHtml(state.modalTitle) + '</h2>',
            '    <button type="button" class="btn btn-outline-secondary" data-nacresearch-close>Fermer</button>',
            '  </div>',
            '  <div class="nacresearch-modal__body">',
            '    <input type="search" class="form-control nacresearch-modal__search" placeholder="Rechercher par code, libellé ou mot-clé" data-nacresearch-search>',
            '    <div class="nacresearch-modal__status" data-nacresearch-status>Chargement des données…</div>',
            '    <div class="nacresearch-modal__results" data-nacresearch-results></div>',
            '  </div>',
            '  <div class="nacresearch-modal__footer">',
            '    <span class="nacresearch-modal__empty" data-nacresearch-empty hidden>Aucun résultat pour cette recherche.</span>',
            '    <button type="button" class="btn btn-secondary" data-nacresearch-close>Annuler</button>',
            '  </div>',
            '</div>'
        ].join('');

        document.body.appendChild(modal);

        var refs = {
            search: modal.querySelector('[data-nacresearch-search]'),
            status: modal.querySelector('[data-nacresearch-status]'),
            results: modal.querySelector('[data-nacresearch-results]'),
            empty: modal.querySelector('[data-nacresearch-empty]')
        };

        modal.querySelectorAll('[data-nacresearch-close]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        refs.search.addEventListener('input', debounce(function () {
            renderResults(refs.search.value);
        }, state.debounceMs));

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && state.modal && !state.modal.hidden) {
                closeModal();
            }
        });

        state.modal = modal;
        state.refs = refs;
        return modal;
    }

    function debounce(callback, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                callback.apply(null, args);
            }, wait);
        };
    }

    function openModal(field) {
        state.activeField = field;
        var modal = createModal();
        modal.hidden = false;
        state.refs.status.textContent = 'Chargement des données…';
        state.refs.results.innerHTML = '';
        state.refs.empty.hidden = true;
        state.refs.search.value = field.value || '';

        fetchData()
            .then(function () {
                state.refs.status.textContent = state.data.length + ' codes disponibles.';
                renderResults(state.refs.search.value || field.value || '');
                state.refs.search.focus();
            })
            .catch(function (error) {
                state.refs.status.textContent = error.message;
            });
    }

    function closeModal() {
        if (!state.modal) {
            return;
        }

        state.modal.hidden = true;
        state.refs.results.innerHTML = '';
        state.refs.empty.hidden = true;
        if (state.activeField) {
            state.activeField.focus();
        }
    }

    function renderResults(query) {
        var records = state.data || [];
        var normalizedTerms = normalize(query).split(/\s+/).filter(Boolean);
        var matches = records.filter(function (record) {
            var haystack = normalize(record.search || [record.code, record.label].join(' '));
            return normalizedTerms.every(function (term) {
                return haystack.indexOf(term) !== -1;
            });
        }).slice(0, state.resultLimit);

        state.refs.results.innerHTML = '';
        state.refs.empty.hidden = matches.length !== 0;
        state.refs.status.textContent = matches.length
            ? matches.length + ' résultat(s) affiché(s) sur ' + records.length + '.'
            : '0 résultat affiché.';

        matches.forEach(function (record) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'nacresearch-modal__result';
            button.innerHTML = [
                '<span class="nacresearch-modal__result-code">' + escapeHtml(record.code) + '</span>',
                '<span>' + escapeHtml(record.label) + '</span>',
                '<span class="nacresearch-modal__result-meta">Section ' + escapeHtml(record.section || '-') + ' · Division ' + escapeHtml(record.division || '-') + '</span>'
            ].join('');
            button.addEventListener('click', function () {
                applySelection(record.code);
            });
            state.refs.results.appendChild(button);
        });
    }

    function applySelection(code) {
        if (!state.activeField) {
            return;
        }

        state.activeField.value = code;
        state.activeField.dispatchEvent(new Event('input', { bubbles: true }));
        state.activeField.dispatchEvent(new Event('change', { bubbles: true }));
        closeModal();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function injectButton(field) {
        if (field.dataset.nacresearchEnhanced === '1' || !fieldMatches(field)) {
            return;
        }

        field.dataset.nacresearchEnhanced = '1';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-primary btn-sm ' + state.triggerClass;
        button.textContent = state.buttonLabel;
        button.addEventListener('click', function () {
            openModal(field);
        });

        field.insertAdjacentElement('afterend', button);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('input, textarea').forEach(injectButton);
    }

    function initObserver() {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.matches && node.matches('input, textarea')) {
                            injectButton(node);
                        }
                        scan(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    function bootstrap() {
        loadRuntimeConfig();
        scan(document);
        initObserver();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
