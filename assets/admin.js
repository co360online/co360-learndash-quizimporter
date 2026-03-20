(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var textarea = document.getElementById('lti_content');
		if (textarea && !textarea.value) {
			textarea.placeholder = 'Título de la pregunta\nEnunciado\n*Respuesta correcta\nRespuesta incorrecta\nComentario: Feedback general';
		}
	});
})();
