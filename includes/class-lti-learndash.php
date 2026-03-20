<?php
/**
 * Servicio de integración con LearnDash.
 */
class LTI_LearnDash_Service {
	/**
	 * Comprueba disponibilidad básica de LearnDash.
	 *
	 * @return bool
	 */
	public function is_available() {
		return post_type_exists( 'sfwd-quiz' ) && class_exists( 'WpProQuiz_Model_Quiz' );
	}

	/**
	 * Obtiene quizzes existentes para selector en la UI.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_existing_quizzes() {
		$posts = get_posts(
			array(
				'post_type'      => 'sfwd-quiz',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$quizzes = array();
		foreach ( $posts as $post ) {
			$quizzes[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
			);
		}

		return $quizzes;
	}

	/**
	 * Importa preguntas en modo "nuevo" o "existente".
	 *
	 * @param string                         $mode create_new|add_existing.
	 * @param string                         $quiz_title
	 * @param string                         $quiz_description
	 * @param array<int,array<string,mixed>> $items
	 * @param int                            $existing_quiz_id
	 * @return LTI_Import_Result
	 */
	public function import_questions( $mode, $quiz_title, $quiz_description, $items, $existing_quiz_id = 0 ) {
		if ( 'add_existing' === $mode ) {
			return $this->add_questions_to_existing_quiz( (int) $existing_quiz_id, $items );
		}

		return $this->create_quiz_with_questions( $quiz_title, $quiz_description, $items );
	}

	/**
	 * Crea un quiz nuevo con sus preguntas.
	 *
	 * @param string                         $quiz_title Título del quiz.
	 * @param string                         $quiz_description Descripción opcional.
	 * @param array<int,array<string,mixed>> $items Preguntas validadas.
	 * @return LTI_Import_Result
	 */
	public function create_quiz_with_questions( $quiz_title, $quiz_description, $items ) {
		$result = new LTI_Import_Result();

		if ( ! $this->is_available() ) {
			$result->add_error( 'LearnDash no está disponible o no expone las clases requeridas de quiz.' );
			return $result;
		}

		$quiz_post_id = $this->create_quiz_post( $quiz_title, $quiz_description );
		if ( is_wp_error( $quiz_post_id ) || ! $quiz_post_id ) {
			$result->add_error( 'No se pudo crear el post del quiz.' );
			return $result;
		}

		$pro_quiz_id = $this->create_pro_quiz( $quiz_title, $quiz_description );
		if ( is_wp_error( $pro_quiz_id ) || ! $pro_quiz_id ) {
			wp_delete_post( (int) $quiz_post_id, true );
			$result->add_error( 'No se pudo crear el quiz interno de LearnDash.' );
			return $result;
		}

		$this->link_quiz_post_to_pro_quiz( (int) $quiz_post_id, (int) $pro_quiz_id );

		$created_questions = $this->create_and_attach_questions( (int) $quiz_post_id, (int) $pro_quiz_id, $items, true );
		if ( is_wp_error( $created_questions ) ) {
			wp_delete_post( (int) $quiz_post_id, true );
			$result->add_error( $created_questions->get_error_message() );
			return $result;
		}

		return $this->build_success_result(
			'create_new',
			(int) $quiz_post_id,
			$created_questions,
			sprintf( 'Se creó un nuevo test "%s".', $quiz_title )
		);
	}

	/**
	 * Añade preguntas a un quiz existente.
	 *
	 * @param int                            $quiz_post_id
	 * @param array<int,array<string,mixed>> $items
	 * @return LTI_Import_Result
	 */
	public function add_questions_to_existing_quiz( $quiz_post_id, $items ) {
		$result = new LTI_Import_Result();

		if ( $quiz_post_id <= 0 || 'sfwd-quiz' !== get_post_type( $quiz_post_id ) ) {
			$result->add_error( 'Debes seleccionar un quiz existente válido.' );
			return $result;
		}

		$pro_quiz_id = $this->get_pro_quiz_id_for_post( (int) $quiz_post_id );
		$this->debug_log( sprintf( 'Modo quiz existente. quiz_post_id=%d | quiz_pro_id_resuelto=%d', (int) $quiz_post_id, (int) $pro_quiz_id ) );
		if ( $pro_quiz_id <= 0 ) {
			$result->add_error( 'No se pudo localizar el ID interno de LearnDash para el quiz seleccionado.' );
			return $result;
		}

		$created_questions = $this->create_and_attach_questions( (int) $quiz_post_id, (int) $pro_quiz_id, $items, false );
		if ( is_wp_error( $created_questions ) ) {
			$result->add_error( $created_questions->get_error_message() );
			return $result;
		}

		return $this->build_success_result(
			'add_existing',
			(int) $quiz_post_id,
			$created_questions,
			sprintf( 'Se añadieron preguntas al test existente "%s".', get_the_title( $quiz_post_id ) )
		);
	}

	/**
	 * @param int                            $quiz_post_id
	 * @param int                            $pro_quiz_id
	 * @param array<int,array<string,mixed>> $items
	 * @param bool                           $is_new_quiz
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function create_and_attach_questions( $quiz_post_id, $pro_quiz_id, $items, $is_new_quiz ) {
		$created_question_ids = array();
		$created              = array();

		foreach ( $items as $item ) {
			$question_post_id = $this->create_question_post( $item );
			if ( is_wp_error( $question_post_id ) || ! $question_post_id ) {
				if ( ! empty( $created_question_ids ) ) {
					$this->rollback_created_questions( $created_question_ids );
				}
				return new WP_Error( 'lti_create_question_post_error', sprintf( 'Error al crear la pregunta del bloque %d.', (int) $item['block_index'] ) );
			}

			$pro_question_id = $this->create_pro_question( $item, (int) $pro_quiz_id );
			if ( is_wp_error( $pro_question_id ) || ! $pro_question_id ) {
				$this->rollback_created_questions( array_merge( $created_question_ids, array( (int) $question_post_id ) ) );
				return new WP_Error( 'lti_create_pro_question_error', sprintf( 'Error al crear la pregunta interna de LearnDash para el bloque %d.', (int) $item['block_index'] ) );
			}

			$this->link_question_post_to_pro_question( (int) $question_post_id, (int) $quiz_post_id, (int) $pro_question_id );
			$created_question_ids[] = (int) $question_post_id;
			$created[]              = array(
				'post_id'         => (int) $question_post_id,
				'pro_question_id' => (int) $pro_question_id,
				'title'           => sanitize_text_field( (string) $item['title'] ),
				'edit_link'       => get_edit_post_link( (int) $question_post_id, '' ),
			);

			$this->debug_log(
				sprintf(
					'Pregunta vinculada. question_post_id=%d | question_pro_id=%d | quiz_post_id=%d | quiz_pro_id=%d',
					(int) $question_post_id,
					(int) $pro_question_id,
					(int) $quiz_post_id,
					(int) $pro_quiz_id
				)
			);
		}

		if ( $is_new_quiz ) {
			$this->debug_log( sprintf( 'Quiz nuevo completado. quiz_post_id=%d', (int) $quiz_post_id ) );
		} else {
			$this->debug_log( sprintf( 'Quiz existente actualizado. quiz_post_id=%d', (int) $quiz_post_id ) );
		}

		return $created;
	}

	/**
	 * @param string $title
	 * @param string $description
	 * @return int|WP_Error
	 */
	private function create_quiz_post( $title, $description ) {
		$quiz_postarr = array(
			'post_type'    => 'sfwd-quiz',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $description,
		);

		return wp_insert_post( $quiz_postarr, true );
	}

	/**
	 * @param string $title
	 * @param string $description
	 * @return int|WP_Error
	 */
	private function create_pro_quiz( $title, $description ) {
		try {
			$quiz_model = new WpProQuiz_Model_Quiz();
			$quiz_model->setId( 0 );
			$quiz_model->setName( $title );
			$quiz_model->setText( $description );
			$quiz_model->setResultText( '' );
			$quiz_model->setTitleHidden( false );

			$mapper      = new WpProQuiz_Model_QuizMapper();
			$save_result = $mapper->save( $quiz_model );
			$quiz_id     = $this->extract_model_id( $quiz_model, $save_result );

			if ( $quiz_id <= 0 ) {
				return new WP_Error( 'lti_pro_quiz_id_invalid', 'No se pudo determinar el ID interno del quiz creado.' );
			}

			return $quiz_id;
		} catch ( Exception $e ) {
			return new WP_Error( 'lti_pro_quiz_error', $e->getMessage() );
		}
	}

	/**
	 * @param int $quiz_post_id
	 * @param int $pro_quiz_id
	 * @return void
	 */
	private function link_quiz_post_to_pro_quiz( $quiz_post_id, $pro_quiz_id ) {
		update_post_meta( $quiz_post_id, 'quiz_pro_id', (int) $pro_quiz_id );

		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( $quiz_post_id, 'quiz_pro', (int) $pro_quiz_id );
		}
	}

	/**
	 * Crea exclusivamente el post de la pregunta (sin vínculo aún).
	 *
	 * @param array<string,mixed> $item
	 * @return int|WP_Error
	 */
	private function create_question_post( $item ) {
		$postarr = array(
			'post_type'    => 'sfwd-question',
			'post_status'  => 'publish',
			'post_title'   => sanitize_text_field( (string) $item['title'] ),
			'post_content' => wp_kses_post( (string) $item['question'] ),
		);

		$question_post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $question_post_id ) ) {
			return $question_post_id;
		}

		update_post_meta( (int) $question_post_id, 'lti_general_feedback', sanitize_textarea_field( (string) $item['comment'] ) );

		$this->debug_log( sprintf( 'Post de pregunta creado. question_post_id=%d', (int) $question_post_id ) );

		return (int) $question_post_id;
	}

	/**
	 * @param array<string,mixed> $item
	 * @param int                 $pro_quiz_id
	 * @return int|WP_Error
	 */
	private function create_pro_question( $item, $pro_quiz_id ) {
		try {
			$answers = array();

			foreach ( $item['answers'] as $answer ) {
				$answer_model = new WpProQuiz_Model_AnswerTypes();
				$answer_model->setAnswer( wp_kses_post( (string) $answer['text'] ) );
				$answer_model->setCorrect( ! empty( $answer['is_correct'] ) );
				$answer_model->setPoints( ! empty( $answer['is_correct'] ) ? 1 : 0 );
				$answer_model->setSortString( '' );
				$answers[] = $answer_model;
			}

			$question_model = new WpProQuiz_Model_Question();
			$question_model->setId( 0 );
			$question_model->setQuizId( (int) $pro_quiz_id );
			$question_model->setCategoryId( 0 );
			$question_model->setTitle( sanitize_text_field( (string) $item['title'] ) );
			$question_model->setQuestion( wp_kses_post( (string) $item['question'] ) );
			$question_model->setAnswerData( $answers );
			$question_model->setAnswerType( 'single' );
			$question_model->setCorrectSameText( true );

			$feedback_message = sanitize_textarea_field( (string) $item['comment'] );
			$question_model->setCorrectMsg( $feedback_message );
			$question_model->setIncorrectMsg( $feedback_message );
			$question_model->setTipMsg( $feedback_message );

			$mapper      = new WpProQuiz_Model_QuestionMapper();
			$save_result = $mapper->save( $question_model );
			$question_id = $this->extract_model_id( $question_model, $save_result );

			if ( $question_id <= 0 ) {
				return new WP_Error( 'lti_pro_question_id_invalid', 'No se pudo determinar el ID interno de la pregunta creada.' );
			}

			$this->debug_log(
				sprintf(
					'Pregunta interna creada. question_pro_id=%d | quiz_pro_id=%d',
					(int) $question_id,
					(int) $pro_quiz_id
				)
			);

			return (int) $question_id;
		} catch ( Exception $e ) {
			return new WP_Error( 'lti_pro_question_error', $e->getMessage() );
		}
	}

	/**
	 * Vincula post de pregunta de WP con su pregunta interna de WpProQuiz y con el quiz.
	 *
	 * @param int $question_post_id
	 * @param int $quiz_post_id
	 * @param int $pro_question_id
	 * @return void
	 */
	private function link_question_post_to_pro_question( $question_post_id, $quiz_post_id, $pro_question_id ) {
		update_post_meta( $question_post_id, 'question_pro_id', (int) $pro_question_id );

		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( $question_post_id, 'quiz', (int) $quiz_post_id );
			learndash_update_setting( $question_post_id, 'question_pro_id', (int) $pro_question_id );
			$quiz_pro_id = $this->get_pro_quiz_id_for_post( (int) $quiz_post_id );
			if ( $quiz_pro_id > 0 ) {
				learndash_update_setting( $question_post_id, 'quiz_pro_id', (int) $quiz_pro_id );
			}
		}

		update_post_meta( $question_post_id, 'quiz_id', (int) $quiz_post_id );

		if ( function_exists( 'learndash_proquiz_sync_question_fields' ) ) {
			learndash_proquiz_sync_question_fields( (int) $question_post_id, (int) $pro_question_id );
		}

		$this->update_quiz_builder_questions( (int) $quiz_post_id, (int) $question_post_id );
	}

	/**
	 * Actualiza el builder de preguntas del quiz preservando la estructura real usada por LearnDash.
	 *
	 * @param int $quiz_post_id
	 * @param int $question_post_id
	 * @return void
	 */
	private function update_quiz_builder_questions( $quiz_post_id, $question_post_id ) {
		if ( ! function_exists( 'learndash_get_quiz_questions' ) || ! function_exists( 'learndash_set_quiz_questions' ) ) {
			return;
		}

		$questions_before = learndash_get_quiz_questions( (int) $quiz_post_id );
		$this->debug_log( sprintf( 'Builder antes update. quiz_post_id=%d | data=%s', (int) $quiz_post_id, wp_json_encode( $questions_before ) ) );

		$questions_after = $this->append_question_preserving_structure( $questions_before, (int) $question_post_id );
		learndash_set_quiz_questions( (int) $quiz_post_id, $questions_after );

		if ( function_exists( 'learndash_update_quiz_questions' ) ) {
			learndash_update_quiz_questions( (int) $quiz_post_id );
		}

		/**
		 * Hook de compatibilidad para permitir a LearnDash/terceros rehacer estructuras si lo necesitan.
		 */
		do_action( 'learndash_quiz_questions_updated', (int) $quiz_post_id, $questions_after );

		$questions_final = learndash_get_quiz_questions( (int) $quiz_post_id );
		$this->debug_log( sprintf( 'Builder despues update. quiz_post_id=%d | added_question_post_id=%d | data=%s', (int) $quiz_post_id, (int) $question_post_id, wp_json_encode( $questions_final ) ) );
	}

	/**
	 * Inserta una pregunta nueva preservando la forma exacta del array original.
	 *
	 * @param mixed $questions_data
	 * @param int   $question_post_id
	 * @return array<mixed>
	 */
	private function append_question_preserving_structure( $questions_data, $question_post_id ) {
		if ( ! is_array( $questions_data ) || empty( $questions_data ) ) {
			return array( (int) $question_post_id => 1 );
		}

		$questions = $questions_data;

		if ( isset( $questions['questions'] ) && is_array( $questions['questions'] ) ) {
			$questions['questions'] = $this->append_question_preserving_structure( $questions['questions'], (int) $question_post_id );
			return $questions;
		}

		$first = reset( $questions );

		// Estructura tipo [question_id => order].
		if ( $this->is_assoc_array( $questions ) && $this->all_keys_numeric( $questions ) ) {
			if ( ! isset( $questions[ (int) $question_post_id ] ) ) {
				$next_order = empty( $questions ) ? 1 : ( max( array_map( 'intval', array_values( $questions ) ) ) + 1 );
				$questions[ (int) $question_post_id ] = $next_order;
			}
			return $questions;
		}

		// Estructura lista de IDs: [12, 18, 34].
		if ( ! $this->is_assoc_array( $questions ) && is_numeric( $first ) ) {
			if ( ! in_array( (int) $question_post_id, array_map( 'intval', $questions ), true ) ) {
				$questions[] = (int) $question_post_id;
			}
			return $questions;
		}

		// Estructura lista de arrays con identificador interno (id/question_id/post_id).
		if ( ! $this->is_assoc_array( $questions ) && is_array( $first ) ) {
			$exists = false;
			foreach ( $questions as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry_id = isset( $entry['question_id'] ) ? (int) $entry['question_id'] : ( isset( $entry['post_id'] ) ? (int) $entry['post_id'] : ( isset( $entry['id'] ) ? (int) $entry['id'] : 0 ) );
				if ( $entry_id === (int) $question_post_id ) {
					$exists = true;
					break;
				}
			}

			if ( ! $exists ) {
				$order = count( $questions ) + 1;
				$questions[] = array(
					'id'          => (int) $question_post_id,
					'question_id' => (int) $question_post_id,
					'post_id'     => (int) $question_post_id,
					'sort'        => $order,
					'order'       => $order,
				);
			}

			return $questions;
		}

		// Fallback defensivo.
		$questions[ (int) $question_post_id ] = count( $questions ) + 1;
		return $questions;
	}

	/**
	 * @param array<mixed> $array
	 * @return bool
	 */
	private function is_assoc_array( $array ) {
		if ( array() === $array ) {
			return false;
		}

		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	/**
	 * @param array<mixed> $array
	 * @return bool
	 */
	private function all_keys_numeric( $array ) {
		foreach ( array_keys( $array ) as $key ) {
			if ( ! is_numeric( $key ) ) {
				return false;
			}
		}

		return true;
	}

	private function extract_model_id( $model, $save_result ) {
		if ( is_object( $model ) && method_exists( $model, 'getId' ) ) {
			$model_id = (int) $model->getId();
			if ( $model_id > 0 ) {
				return $model_id;
			}
		}

		if ( is_numeric( $save_result ) ) {
			$save_id = (int) $save_result;
			if ( $save_id > 0 ) {
				return $save_id;
			}
		}

		return 0;
	}

	/**
	 * @param int $quiz_post_id
	 * @return int
	 */
	private function get_pro_quiz_id_for_post( $quiz_post_id ) {
		$resolved_ids = array();

		if ( function_exists( 'learndash_get_setting' ) ) {
			$setting = learndash_get_setting( $quiz_post_id, 'quiz_pro' );
			if ( is_numeric( $setting ) && (int) $setting > 0 ) {
				$resolved_ids[] = (int) $setting;
			}
		}

		if ( function_exists( 'learndash_get_quiz_pro' ) ) {
			$quiz_pro = learndash_get_quiz_pro( $quiz_post_id );
			if ( is_object( $quiz_pro ) && method_exists( $quiz_pro, 'getId' ) ) {
				$quiz_pro_id = (int) $quiz_pro->getId();
				if ( $quiz_pro_id > 0 ) {
					$resolved_ids[] = $quiz_pro_id;
				}
			}
		}

		$meta_pro_id = get_post_meta( $quiz_post_id, 'quiz_pro_id', true );
		if ( is_numeric( $meta_pro_id ) && (int) $meta_pro_id > 0 ) {
			$resolved_ids[] = (int) $meta_pro_id;
		}

		$sfwd_quiz_meta = get_post_meta( $quiz_post_id, '_sfwd-quiz', true );
		if ( is_array( $sfwd_quiz_meta ) ) {
			$keys = array( 'sfwd-quiz_quiz_pro', 'quiz_pro', 'quiz_pro_id' );
			foreach ( $keys as $key ) {
				if ( isset( $sfwd_quiz_meta[ $key ] ) && is_numeric( $sfwd_quiz_meta[ $key ] ) && (int) $sfwd_quiz_meta[ $key ] > 0 ) {
					$resolved_ids[] = (int) $sfwd_quiz_meta[ $key ];
				}
			}
		}

		$resolved_ids = array_values( array_unique( array_filter( $resolved_ids ) ) );
		if ( empty( $resolved_ids ) ) {
			return 0;
		}

		$resolved = (int) $resolved_ids[0];
		$this->debug_log( sprintf( 'Resolución quiz_pro_id. quiz_post_id=%d | candidatos=%s | usado=%d', (int) $quiz_post_id, wp_json_encode( $resolved_ids ), $resolved ) );
		return $resolved;
	}

	/**
	 * @param int[] $question_post_ids
	 * @return void
	 */
	private function rollback_created_questions( $question_post_ids ) {
		foreach ( $question_post_ids as $question_post_id ) {
			wp_delete_post( (int) $question_post_id, true );
		}
	}

	/**
	 * @param string                         $mode
	 * @param int                            $quiz_post_id
	 * @param array<int,array<string,mixed>> $created_questions
	 * @param string                         $message
	 * @return LTI_Import_Result
	 */
	private function build_success_result( $mode, $quiz_post_id, $created_questions, $message ) {
		$result = new LTI_Import_Result();
		$result->set_success( true );
		$result->add_message( $message );

		$result->set_data( 'mode', $mode );
		$result->set_data( 'quiz_post_id', (int) $quiz_post_id );
		$result->set_data( 'quiz_title', get_the_title( $quiz_post_id ) );
		$result->set_data( 'quiz_edit_link', get_edit_post_link( $quiz_post_id, '' ) );
		$result->set_data( 'questions_created', count( $created_questions ) );
		$result->set_data( 'created_questions', $created_questions );

		return $result;
	}

	/**
	 * Logging temporal de trazabilidad para depuración de IDs y vinculaciones.
	 *
	 * @param string $message
	 * @return void
	 */
	private function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[LTI LearnDash] ' . (string) $message );
		}
	}
}
