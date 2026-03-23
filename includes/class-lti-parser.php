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
		$normalized = $this->normalize_input( (string) $raw_text );
		$normalized = trim( $normalized );

		if ( '' === $normalized ) {
			return array();
		}

		$chunks = preg_split( '/\n\s*\n+/u', $normalized );
		$items  = array();

		foreach ( $chunks as $index => $chunk ) {
			$lines = array_values(
				array_filter(
					array_map( array( $this, 'normalize_line' ), explode( "\n", (string) $chunk ) ),
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

				if ( preg_match( '/^Comentario\s*:/iu', $line ) ) {
					$item['comment'] = trim( preg_replace( '/^Comentario\s*:/iu', '', $line ) );
					continue;
				}

				$is_correct = 0 === strpos( ltrim( $line ), '*' );
				$text       = $is_correct ? ltrim( ltrim( $line ), '* ' ) : $line;

				$item['answers'][] = array(
					'text'       => $text,
					'is_correct' => $is_correct,
				);
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Normaliza texto pegado desde distintas fuentes (Word, saltos inconsistentes, espacios especiales).
	 *
	 * @param string $text
	 * @return string
	 */
	private function normalize_input( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF" ), ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return (string) $text;
	}

	/**
	 * @param string $line
	 * @return string
	 */
	private function normalize_line( $line ) {
		$line = trim( (string) $line );
		$line = preg_replace( '/[ \t]+/u', ' ', $line );
		return (string) $line;
	}
}
