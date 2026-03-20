<?php
/**
 * Parser de bloques de texto para preguntas.
 */
class LTI_Parser {
	/**
	 * Convierte el texto crudo en bloques de pregunta estructurados.
	 *
	 * @param string $raw_text Texto pegado por el usuario.
	 * @return array<int,array<string,mixed>>
	 */
	public function parse( $raw_text ) {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw_text );
		$normalized = trim( $normalized );

		if ( '' === $normalized ) {
			return array();
		}

		$chunks = preg_split( '/\n\s*\n/', $normalized );
		$items  = array();

		foreach ( $chunks as $index => $chunk ) {
			$lines = array_values(
				array_filter(
					array_map( 'trim', explode( "\n", (string) $chunk ) ),
					static function ( $line ) {
						return '' !== $line;
					}
				)
			);

			$item = array(
				'block_index' => $index + 1,
				'title'       => '',
				'question'    => '',
				'answers'     => array(),
				'comment'     => '',
			);

			if ( isset( $lines[0] ) ) {
				$item['title'] = $lines[0];
			}

			if ( isset( $lines[1] ) ) {
				$item['question'] = $lines[1];
			}

			foreach ( $lines as $line_number => $line ) {
				if ( $line_number < 2 ) {
					continue;
				}

				if ( 0 === stripos( $line, 'Comentario:' ) ) {
					$item['comment'] = trim( substr( $line, strlen( 'Comentario:' ) ) );
					continue;
				}

				$is_correct = 0 === strpos( $line, '*' );
				$text       = $is_correct ? ltrim( substr( $line, 1 ) ) : $line;

				$item['answers'][] = array(
					'text'       => $text,
					'is_correct' => $is_correct,
				);
			}

			$items[] = $item;
		}

		return $items;
	}
}
