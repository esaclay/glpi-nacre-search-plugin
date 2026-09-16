(function () {
    'use strict';

    var state = {
        minLength: 2,
        resultLimit: 10,
        debounceMs: 250,
        searchUrl: '/plugins/nacresearch/front/observer-search.php',
        fieldHint: 'observateur',
        placeholder: 'Rechercher par nom, prénom ou e-mail…',
        emptyHint: "Aucun résultat — vous pouvez saisir l'adresse e-mail directement."
    };

    // Séparateur entre adresses dans un même champ (plusieurs observateurs
    // possibles) — correspond à la sémantique standard d'un champ HTML
    // <input type="email" multiple>. À adapter ici si la vérification en
    // prod du champ « Observateurs (adresses mail) » révèle un autre format
    // (ex. bloc de plusieurs inputs répétables plutôt qu'un champ délimité).
    var VALUE_DELIMITER = ', ';

    var fieldStates = new WeakMap();

    function readMeta(name, fallback) {
        var tag = document.querySelector('meta[name="' + name + '"]');
        return tag && tag.content ? tag.content : fallback;
    }

    function loadRuntimeConfig() {
        state.minLength = parseInt(readMeta('nacresearch:observer-search-min-length', String(state.minLength)), 10) || state.minLength;
        state.resultLimit = parseInt(readMeta('nacresearch:observer-search-result-limit', String(state.resultLimit)), 10) || state.resultLimit;
        state.debounceMs = parseInt(readMeta('nacresearch:observer-search-debounce-ms', String(state.debounceMs)), 10) || state.debounceMs;
        state.searchUrl = readMeta('nacresearch:observer-search-url', state.searchUrl);
        state.fieldHint = readMeta('nacresearch:observer-search-field-hint', state.fieldHint);
        state.placeholder = readMeta('nacresearch:observer-search-placeholder', state.placeholder) || state.placeholder;
        state.emptyHint = readMeta('nacresearch:observer-search-empty-hint', state.emptyHint) || state.emptyHint;
    }

    function normalize(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .trim();
    }

    // Garde-fous repris à l'identique de nacre-search.js : GLPI 11 rend la
    // création de ticket via le même moteur Form Renderer que le formulaire
    // catalogue, l'URL seule ne suffit donc pas à les distinguer.
    function isInFormCatalog() {
        return window.location.pathname.includes('/Form/Render/');
    }

    function isTicketForm() {
        var pathname = window.location.pathname;
        if (pathname.includes('/Ticket/')) {
            return true;
        }
        if (pathname.includes('/ticket.form.php')) {
            return true;
        }
        return false;
    }

    function isInsideItilObjectForm(field) {
        return !!(
            field.closest('.itil-object-fields')
            || field.closest('#itil-data')
            || field.closest('[id^="plugin_fields_container_"]')
        );
    }

    function getFormRendererQuestion(field) {
        return field.closest('[data-glpi-form-renderer-question]');
    }

    function fieldMatches(field) {
        if (field.type !== 'email') {
            return false;
        }

        if (isTicketForm()) {
            return false;
        }

        if (isInsideItilObjectForm(field)) {
            return false;
        }

        if (!isInFormCatalog()) {
            return false;
        }

        var question = getFormRendererQuestion(field);
        if (!question) {
            return false;
        }

        var labels = Array.prototype.map.call(field.labels || [], function (label) {
            return label.textContent;
        });
        labels.push(question.getAttribute('aria-label'));
        var candidates = [field.name, field.id, field.placeholder, field.getAttribute('aria-label')].concat(labels);
        var hint = normalize(state.fieldHint);
        return candidates.some(function (value) {
            return normalize(value).indexOf(hint) !== -1;
        });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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

    function createDropdown(field) {
        var dropdown = document.createElement('ul');
        dropdown.className = 'observersearch-dropdown';
        dropdown.hidden = true;
        document.body.appendChild(dropdown);

        var fieldState = {
            dropdown: dropdown,
            controller: null
        };
        fieldStates.set(field, fieldState);
        return fieldState;
    }

    function positionDropdown(field, dropdown) {
        var rect = field.getBoundingClientRect();
        dropdown.style.position = 'absolute';
        dropdown.style.left = (window.scrollX + rect.left) + 'px';
        dropdown.style.top = (window.scrollY + rect.bottom) + 'px';
        dropdown.style.width = rect.width + 'px';
    }

    function closeDropdown(fieldState) {
        fieldState.dropdown.hidden = true;
        fieldState.dropdown.innerHTML = '';
    }

    // Fragment en cours de saisie : tout ce qui suit le dernier séparateur
    // confirmé. C'est ce fragment (pas la valeur entière) qui sert de requête
    // et qui est remplacé lors d'une sélection — ce qui permet d'enchaîner
    // plusieurs adresses dans le même champ sans effacer les précédentes.
    function currentFragment(field) {
        var value = field.value || '';
        var marker = VALUE_DELIMITER.trim();
        var delimiterIndex = marker ? value.lastIndexOf(marker) : -1;
        var start = delimiterIndex === -1 ? 0 : delimiterIndex + marker.length;
        while (start < value.length && /\s/.test(value.charAt(start))) {
            start += 1;
        }
        return { start: start, text: value.slice(start) };
    }

    // Le champ « Observateurs » n'a, à ce jour, pas l'attribut HTML
    // `multiple` (validation native : une seule adresse à la fois). S'il
    // est un jour rendu avec `multiple` (plusieurs réponses activées côté
    // GLPI sur une valeur unique délimitée), on retombe automatiquement sur
    // la logique d'ajout par virgule ; sinon on remplace simplement la
    // valeur du champ ciblé — correct aussi bien pour le champ simple actuel
    // que pour un futur bloc répétable (chaque ligne ne porte qu'une adresse).
    function applySelection(field, fieldState, email) {
        if (field.multiple) {
            var value = field.value || '';
            var fragment = currentFragment(field);
            var prefix = value.slice(0, fragment.start);
            field.value = prefix + email + VALUE_DELIMITER;
        } else {
            field.value = email;
        }
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        closeDropdown(fieldState);
        field.focus();
    }

    function renderResults(field, fieldState, results) {
        var dropdown = fieldState.dropdown;
        dropdown.innerHTML = '';

        if (results.length === 0) {
            var hint = document.createElement('li');
            hint.className = 'observersearch-dropdown__hint';
            hint.textContent = state.emptyHint;
            dropdown.appendChild(hint);
        } else {
            results.forEach(function (candidate) {
                var item = document.createElement('li');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'observersearch-dropdown__item';
                var name = (String(candidate.firstname || '') + ' ' + String(candidate.lastname || '')).trim() || candidate.login;
                button.innerHTML = '<strong>' + escapeHtml(name) + '</strong>'
                    + '<span>' + escapeHtml(candidate.email) + '</span>';
                // mousedown (pas click) pour agir avant le blur du champ.
                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    applySelection(field, fieldState, candidate.email);
                });
                item.appendChild(button);
                dropdown.appendChild(item);
            });
        }

        positionDropdown(field, dropdown);
        dropdown.hidden = false;
    }

    function search(field, fieldState, query) {
        if (fieldState.controller) {
            fieldState.controller.abort();
        }

        if (query.length < state.minLength) {
            closeDropdown(fieldState);
            return;
        }

        var controller = new AbortController();
        fieldState.controller = controller;

        window.fetch(state.searchUrl + '?q=' + encodeURIComponent(query), {
            credentials: 'same-origin',
            signal: controller.signal
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                fieldState.controller = null;
                renderResults(field, fieldState, Array.isArray(payload.results) ? payload.results : []);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                fieldState.controller = null;
                closeDropdown(fieldState);
            });
    }

    function attachField(field) {
        if (field.dataset.observersearchEnhanced === '1' || !fieldMatches(field)) {
            return;
        }
        field.dataset.observersearchEnhanced = '1';
        if (state.placeholder && !field.placeholder) {
            field.placeholder = state.placeholder;
        }

        var fieldState = createDropdown(field);

        field.addEventListener('input', debounce(function () {
            var query = field.multiple ? currentFragment(field).text.trim() : (field.value || '').trim();
            search(field, fieldState, query);
        }, state.debounceMs));

        field.addEventListener('blur', function () {
            // Délai pour laisser le mousedown sur une suggestion s'exécuter
            // avant que le blur ne ferme la liste.
            window.setTimeout(function () {
                closeDropdown(fieldState);
            }, 150);
        });

        field.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDropdown(fieldState);
            }
        });

        window.addEventListener('resize', function () {
            if (!fieldState.dropdown.hidden) {
                positionDropdown(field, fieldState.dropdown);
            }
        });
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('input[type="email"]').forEach(attachField);
    }

    function initObserver() {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.matches && node.matches('input[type="email"]')) {
                            attachField(node);
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
