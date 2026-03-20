<?php
/**
 * Validador de preguntas importadas.
 */
class LTI_Validator {
	/**
	 * Valida la estructura de todos los bloques.
	 *
	 * @param array<int,array<string,mixed>> $items Bloques parseados.
	 * @return LTI_Import_Result
	 */
	public function validate( $items ) {
		$result = new LTI_Import_Result();

		if ( empty( $items ) ) {
			$result->add_error( 'No se detectaron bloques de preguntas en el texto.' );
			return $result;
		}

		foreach ( $items as $item ) {
			$block_index = isset( $item['block_index'] ) ? (int) $item['block_index'] : 0;
			$title       = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
			$question    = isset( $item['question'] ) ? trim( (string) $item['question'] ) : '';
			$comment     = isset( $item['comment'] ) ? trim( (string) $item['comment'] ) : '';
			$answers     = isset( $item['answers'] ) && is_array( $item['answers'] ) ? $item['answers'] : array();

			if ( '' === $title ) {
				$result->add_error( sprintf( 'Bloque %d: falta el título de la pregunta.', $block_index ) );
			}

			if ( '' === $question ) {
				$result->add_error( sprintf( 'Bloque %d: falta el enunciado de la pregunta.', $block_index ) );
			}

			if ( count( $answers ) < 2 ) {
				$result->add_error( sprintf( 'Bloque %d: debe incluir al menos 2 respuestas.', $block_index ) );
			}

			$correct_answers = 0;
			foreach ( $answers as $answer_index => $answer ) {
				$answer_text = isset( $answer['text'] ) ? trim( (string) $answer['text'] ) : '';
				$is_correct  = ! empty( $answer['is_correct'] );

				if ( '' === $answer_text ) {
					$result->add_error( sprintf( 'Bloque %d: la respuesta %d está vacía.', $block_index, $answer_index + 1 ) );
				}

				if ( $is_correct ) {
					++$correct_answers;
				}
			}

			if ( 1 !== $correct_answers ) {
				$result->add_error( sprintf( 'Bloque %d: debe haber exactamente 1 respuesta correcta marcada con *.', $block_index ) );
			}

			if ( '' === $comment ) {
				$result->add_error( sprintf( 'Bloque %d: falta la línea Comentario:.', $block_index ) );
			}
		}

		if ( ! $result->has_errors() ) {
			$result->set_success( true );
		}

		return $result;
	}
}
