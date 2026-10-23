// Vorschlaege fuer das Suchfeld (rex_api_search_suggest)
//
// Eingebunden ueber ein Attribut am Eingabefeld, mehr braucht es nicht:
//
//   <input type="search" name="q" id="q" data-search-suggest="<?= rex_escape(rex_api_search_suggest::getUrl($sucheId)) ?>">
//
// Umgesetzt als Combobox mit Listbox nach dem WAI-ARIA-Muster "List autocomplete with manual
// selection": Der Fokus bleibt im Eingabefeld, die Pfeiltasten waehlen einen Vorschlag aus
// (aria-activedescendant), Enter sucht danach, Escape schliesst die Liste. Was im Feld steht,
// aendert sich erst mit der Auswahl.
//
// Die Liste steht direkt hinter dem Feld, oder hinter dem naechsten umgebenden Element mit
// data-search-suggest-anchor (bzw. .input-group). Gestaltet wird sie ueber die Klassen
// .search-suggest, .search-suggest-item und .is-active; search-suggest.css bringt eine
// schlichte Vorlage mit.
(function () {
    'use strict';

    var MIN_LENGTH = 2;
    var DELAY = 150;

    // Ueberschreibbar je Feld mit data-search-suggest-label, -one und -many ({count})
    var TEXTS = {
        de: {
            label: 'Suchvorschläge',
            one: 'Ein Vorschlag, mit Pfeiltaste nach unten auswählen.',
            many: '{count} Vorschläge, mit Pfeiltasten auswählen.'
        },
        en: {
            label: 'Search suggestions',
            one: 'One suggestion, press the down arrow to select it.',
            many: '{count} suggestions, use the arrow keys to select one.'
        }
    };

    // Sichtbar nur fuer Screenreader, ohne dass die Seite dafuer eine Klasse bereitstellen muss
    var VISUALLY_HIDDEN = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0';

    var counter = 0;

    function texts(input) {
        var lang = ((input.closest('[lang]') || document.documentElement).getAttribute('lang') || '').slice(0, 2).toLowerCase();
        var base = TEXTS[lang] || TEXTS.en;
        return {
            label: input.getAttribute('data-search-suggest-label') || base.label,
            one: input.getAttribute('data-search-suggest-one') || base.one,
            many: input.getAttribute('data-search-suggest-many') || base.many
        };
    }

    function SearchSuggest(input) {
        var self = this;

        this.input = input;
        this.form = input.form;
        this.url = input.getAttribute('data-search-suggest');
        this.texts = texts(input);
        this.id = (input.id || 'search-suggest-' + (++counter)) + '-suggestions';
        this.suggestions = [];
        this.active = -1;
        this.cache = {};
        this.timer = null;
        this.current = null;
        this.pointerDown = false;

        this.list = document.createElement('ul');
        this.list.id = this.id;
        this.list.className = 'search-suggest';
        this.list.setAttribute('role', 'listbox');
        this.list.setAttribute('aria-label', this.texts.label);
        this.list.hidden = true;

        this.status = document.createElement('div');
        this.status.setAttribute('role', 'status');
        this.status.style.cssText = VISUALLY_HIDDEN;

        var anchor = input.closest('[data-search-suggest-anchor], .input-group') || input;
        anchor.parentNode.insertBefore(this.list, anchor.nextSibling);
        anchor.parentNode.insertBefore(this.status, this.list.nextSibling);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', this.id);
        input.setAttribute('autocomplete', 'off');

        input.addEventListener('input', function () { self.schedule(); });
        input.addEventListener('keydown', function (event) { self.onKeydown(event); });
        input.addEventListener('blur', function () { self.onBlur(); });

        document.addEventListener('pointerdown', function () { self.pointerDown = true; }, true);
        ['pointerup', 'pointercancel'].forEach(function (type) {
            document.addEventListener(type, function () { self.pointerDown = false; }, true);
        });

        // Der Klick soll den Fokus nicht aus dem Feld nehmen, sonst schliesst blur die Liste vorher
        this.list.addEventListener('mousedown', function (event) { event.preventDefault(); });
        this.list.addEventListener('click', function (event) {
            var option = event.target.closest('[role="option"]');
            if (option) {
                self.choose(parseInt(option.getAttribute('data-index'), 10));
            }
        });
    }

    SearchSuggest.prototype.schedule = function () {
        var self = this;
        var value = this.input.value;

        clearTimeout(this.timer);
        if (value.trim().length < MIN_LENGTH) {
            this.current = null;
            this.render([]);
            return;
        }
        this.timer = setTimeout(function () { self.load(value); }, DELAY);
    };

    SearchSuggest.prototype.load = function (value) {
        var self = this;

        this.current = value;
        if (this.cache[value]) {
            this.render(this.cache[value]);
            return;
        }

        var separator = this.url.indexOf('?') === -1 ? '?' : '&';
        fetch(this.url + separator + 'q=' + encodeURIComponent(value), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        })
            .then(function (response) { return response.ok ? response.json() : {suggestions: []}; })
            .then(function (data) {
                self.cache[value] = data.suggestions || [];
                // Inzwischen weitergetippt: die Antwort gehoert zu einer alten Eingabe
                if (self.current === value && self.input.value === value) {
                    self.render(self.cache[value]);
                }
            })
            .catch(function () {});
    };

    SearchSuggest.prototype.render = function (suggestions) {
        var self = this;

        this.suggestions = suggestions;
        this.active = -1;
        this.input.removeAttribute('aria-activedescendant');
        this.list.textContent = '';

        suggestions.forEach(function (suggestion, index) {
            var option = document.createElement('li');
            option.id = self.id + '-' + index;
            option.className = 'search-suggest-item';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.setAttribute('data-index', index);
            // Ergaenztes hervorheben, Getipptes nicht
            suggestion.parts.forEach(function (part) {
                if (part.typed) {
                    option.appendChild(document.createTextNode(part.text));
                } else {
                    var strong = document.createElement('strong');
                    strong.textContent = part.text;
                    option.appendChild(strong);
                }
            });
            self.list.appendChild(option);
        });

        if (suggestions.length && document.activeElement === this.input) {
            this.open();
            this.status.textContent = suggestions.length === 1
                ? this.texts.one
                : this.texts.many.replace('{count}', suggestions.length);
        } else {
            this.close();
            this.status.textContent = '';
        }
    };

    SearchSuggest.prototype.open = function () {
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
    };

    SearchSuggest.prototype.close = function () {
        this.list.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
        this.select(-1);
    };

    // Steht die Liste im Fluss, rutscht beim Schliessen alles darunter nach oben, und das
    // Loslassen der Maus trifft nicht mehr, was gedrueckt wurde (ein Schliessen-Knopf, ein
    // Treffer). Nach einem Mausdruck wird deshalb erst nach dem Klick geschlossen.
    SearchSuggest.prototype.onBlur = function () {
        var self = this;

        if (!this.pointerDown) {
            this.close();
            return;
        }
        var later = function () {
            document.removeEventListener('pointerup', later);
            document.removeEventListener('pointercancel', later);
            setTimeout(function () {
                if (document.activeElement !== self.input) {
                    self.close();
                }
            }, 0);
        };
        document.addEventListener('pointerup', later);
        document.addEventListener('pointercancel', later);
    };

    SearchSuggest.prototype.isOpen = function () {
        return !this.list.hidden;
    };

    SearchSuggest.prototype.select = function (index) {
        var options = this.list.children;

        if (this.active > -1 && options[this.active]) {
            options[this.active].setAttribute('aria-selected', 'false');
            options[this.active].classList.remove('is-active');
        }
        this.active = index;
        if (index > -1 && options[index]) {
            options[index].setAttribute('aria-selected', 'true');
            options[index].classList.add('is-active');
            this.input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({block: 'nearest'});
        } else {
            this.input.removeAttribute('aria-activedescendant');
        }
    };

    SearchSuggest.prototype.move = function (step) {
        if (!this.suggestions.length) {
            return;
        }
        if (!this.isOpen()) {
            this.open();
        }
        var count = this.suggestions.length;
        // Ueber das Ende hinaus geht es zurueck ins Feld (-1), von dort wieder in die Liste
        var index = this.active + step;
        if (index < -1) {
            index = count - 1;
        } else if (index >= count) {
            index = -1;
        }
        this.select(index);
    };

    SearchSuggest.prototype.choose = function (index) {
        var suggestion = this.suggestions[index];
        if (!suggestion) {
            return;
        }
        this.input.value = suggestion.value;
        this.close();
        if (this.form) {
            // requestSubmit loest das submit-Ereignis aus, auf das die Seite vielleicht hoert
            if (this.form.requestSubmit) {
                this.form.requestSubmit();
            } else {
                this.form.submit();
            }
        }
    };

    SearchSuggest.prototype.onKeydown = function (event) {
        switch (event.key) {
            case 'ArrowDown':
            case 'Down':
                event.preventDefault();
                this.move(1);
                break;
            case 'ArrowUp':
            case 'Up':
                event.preventDefault();
                this.move(-1);
                break;
            case 'Enter':
                if (this.isOpen() && this.active > -1) {
                    event.preventDefault();
                    this.choose(this.active);
                }
                break;
            case 'Escape':
            case 'Esc':
                if (this.isOpen()) {
                    event.preventDefault();
                    this.close();
                }
                break;
            case 'Tab':
                this.close();
                break;
        }
    };

    function init() {
        if (!window.fetch || !Element.prototype.closest) {
            return;
        }
        var inputs = document.querySelectorAll('[data-search-suggest]');
        for (var i = 0; i < inputs.length; ++i) {
            // Zweimal eingebunden waeren es zwei Listen am selben Feld
            if (!inputs[i].searchSuggest) {
                inputs[i].searchSuggest = new SearchSuggest(inputs[i]);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
