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

    // Felder, die nur zu bestimmten Werten eines anderen Feldes gehoeren, blenden sich
    // aus, sobald dort etwas anderes steht. Genutzt fuer die Mindestaehnlichkeit: beim
    // reinen Volltext hat sie keine Wirkung, und eine Zahl ohne Wirkung verwirrt.
    //
    // Deklarativ ueber zwei Angaben am Feld selbst -- data-search-shown-by nennt die Id
    // des Feldes, das entscheidet, data-search-shown-for dessen Werte (durch Leerzeichen
    // getrennt). Versteckt wird die ganze Formularzeile, nicht nur das Eingabefeld, sonst
    // bliebe die Beschriftung allein stehen.
    function syncConditionalFields() {
        var fields = document.querySelectorAll('[data-search-shown-by]');
        for (var i = 0; i < fields.length; ++i) {
            var field = fields[i];
            var control = document.getElementById(field.getAttribute('data-search-shown-by'));
            if (!control) {
                continue;
            }
            var values = (field.getAttribute('data-search-shown-for') || '').split(' ');
            var group = field.closest('.rex-form-group') || field;
            group.classList.toggle('search-hidden', -1 === values.indexOf(control.value));
        }
    }

    document.addEventListener('change', function (event) {
        // Jede Aenderung kann die Bedingung eines Feldes betreffen; welche, entscheidet
        // der Abgleich selbst. Billiger als ein Verzeichnis der Abhaengigkeiten.
        if (event.target && 'SELECT' === event.target.tagName) {
            syncConditionalFields();
        }
    });

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', syncConditionalFields);
    } else {
        syncConditionalFields();
    }
})();
