(function () {
    'use strict';

    // Config-Felder mit data-search-reload laden das Formular neu, damit abhaengige
    // Felder (z. B. die Feldliste einer YForm-Tabelle) zur Auswahl passen.
    document.addEventListener('change', function (event) {
        var element = event.target;
        if (!element || !element.matches('[data-search-reload]') || !element.form) {
            return;
        }
        var button = element.form.querySelector('button[name="reload"]');
        if (button) {
            button.click();
        } else {
            element.form.submit();
        }
    });
})();
