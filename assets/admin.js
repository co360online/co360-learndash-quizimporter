(function () {
	'use strict';

	function toggleImportModeFields() {
		var selected = document.querySelector('input[name="lti_import_mode"]:checked');
		var mode = selected ? selected.value : 'create_new';
		var createRows = document.querySelectorAll('.lti-row-create-new');
		var existingRows = document.querySelectorAll('.lti-row-existing-quiz');

		createRows.forEach(function (row) {
			row.style.display = mode === 'create_new' ? '' : 'none';
		});

		existingRows.forEach(function (row) {
			row.style.display = mode === 'add_existing' ? '' : 'none';
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var textarea = document.getElementById('lti_content');
		if (textarea && !textarea.value) {
			textarea.placeholder = 'Pregunta 1 - Módulo 01\n¿Cuál es la opción correcta?:\n*Respuesta correcta\nRespuesta incorrecta\nComentario: Feedback general';
		}

		document.querySelectorAll('input[name="lti_import_mode"]').forEach(function (radio) {
			radio.addEventListener('change', toggleImportModeFields);
		});

		toggleImportModeFields();
	});
})();
