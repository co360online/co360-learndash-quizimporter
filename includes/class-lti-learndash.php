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
	 * Añade preguntas a un quiz existente y reconstruye/sincroniza por completo el quiz.
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
		$this->debug_log( sprintf( 'Modo quiz existente. quiz_post_id=%d | quiz_pro_id=%d', (int) $quiz_post_id, (int) $pro_quiz_id ) );
		if ( $pro_quiz_id <= 0 ) {
			$result->add_error( 'No se pudo localizar el ID interno de LearnDash para el quiz seleccionado.' );
			return $result;
		}

		$created_questions = $this->create_and_attach_questions( (int) $quiz_post_id, (int) $pro_quiz_id, $items, false );
		if ( is_wp_error( $created_questions ) ) {
			$result->add_error( $created_questions->get_error_message() );
			return $result;
		}


		$resync_result = $this->resync_quiz_questions( (int) $quiz_post_id, (int) $pro_quiz_id, $created_questions );
		if ( is_wp_error( $resync_result ) ) {
			$result->add_error( $resync_result->get_error_message() );
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

			$this->link_question_post_to_pro_question( (int) $question_post_id, (int) $quiz_post_id, (int) $pro_question_id, $is_new_quiz );
			$created_question_ids[] = (int) $question_post_id;
			$created[]              = array(
				'post_id'         => (int) $question_post_id,
				'pro_question_id' => (int) $pro_question_id,
				'title'           => sanitize_text_field( (string) $item['title'] ),
				'edit_link'       => get_edit_post_link( (int) $question_post_id, '' ),
			);

			$this->debug_log(
				sprintf(
					'Pregunta creada y vinculada. quiz_post_id=%d | quiz_pro_id=%d | question_post_id=%d | question_pro_id=%d',
					(int) $quiz_post_id,
					(int) $pro_quiz_id,
					(int) $question_post_id,
					(int) $pro_question_id
				)
			);
		}

		if ( $is_new_quiz ) {
			$this->debug_log( sprintf( 'Quiz nuevo completado. quiz_post_id=%d', (int) $quiz_post_id ) );
		}

		return $created;
	}

	/**
	 * Reconstruye y resincroniza por completo preguntas de quiz existente.
	 *
	 * @param int   $quiz_post_id
	 * @param int   $quiz_pro_id
	 * @param int[] $new_question_ids
	 * @return void
	 */
	private function resync_quiz_questions( $quiz_post_id, $quiz_pro_id, $new_questions ) {
		$builder_original = function_exists( 'learndash_get_quiz_questions' ) ? learndash_get_quiz_questions( (int) $quiz_post_id ) : array();
		$this->debug_log( sprintf( 'Resync start. quiz_post_id=%d | quiz_pro_id=%d | builder_original=%s', (int) $quiz_post_id, (int) $quiz_pro_id, wp_json_encode( $builder_original ) ) );

		$builder_ids = $this->extract_question_post_ids_from_builder( $builder_original );
		$query_ids   = $this->get_question_ids_linked_to_quiz( (int) $quiz_post_id );
		$current_ids = array_values( array_unique( array_merge( $builder_ids, $query_ids ) ) );

		$this->debug_log( sprintf( 'Resync detected current question IDs. quiz_post_id=%d | ids=%s', (int) $quiz_post_id, wp_json_encode( $current_ids ) ) );

		$valid_ids   = array();
		$invalid_map = array();

		foreach ( $current_ids as $question_id ) {
			$validation = $this->validate_question_for_quiz_resync( (int) $question_id, (int) $quiz_post_id );
			if ( true === $validation['valid'] ) {
				$valid_ids[] = (int) $question_id;
			} else {
				$invalid_map[] = array(
					'question_id' => (int) $question_id,
					'reason'      => $validation['reason'],
				);
			}
		}

		$new_valid_ids  = array();
		$new_valid_pros = array();
		foreach ( (array) $new_questions as $new_question ) {
			$question_id  = isset( $new_question['post_id'] ) ? (int) $new_question['post_id'] : 0;
			$question_pro = isset( $new_question['pro_question_id'] ) ? (int) $new_question['pro_question_id'] : 0;
			$validation   = $this->validate_question_for_quiz_resync( (int) $question_id, (int) $quiz_post_id );
			if ( true === $validation['valid'] ) {
				$new_valid_ids[] = (int) $question_id;
				if ( $question_pro > 0 ) {
					$new_valid_pros[] = (int) $question_pro;
				}
			} else {
				$invalid_map[] = array(
					'question_id' => (int) $question_id,
					'reason'      => 'new_question_invalid: ' . $validation['reason'],
				);
			}
		}

		$final_ids = array_values( array_unique( array_merge( $valid_ids, $new_valid_ids ) ) );
		$final_builder = array();
		foreach ( $final_ids as $index => $question_id ) {
			$final_builder[ (int) $question_id ] = $index + 1;
		}

		$this->debug_log( sprintf( 'Resync valid kept. quiz_post_id=%d | ids=%s', (int) $quiz_post_id, wp_json_encode( $valid_ids ) ) );
		$this->debug_log( sprintf( 'Resync invalid discarded. quiz_post_id=%d | items=%s', (int) $quiz_post_id, wp_json_encode( $invalid_map ) ) );
		$this->debug_log( sprintf( 'Resync new added. quiz_post_id=%d | ids=%s', (int) $quiz_post_id, wp_json_encode( $new_valid_ids ) ) );

		if ( function_exists( 'learndash_set_quiz_questions' ) ) {
			learndash_set_quiz_questions( (int) $quiz_post_id, $final_builder );
		}

		foreach ( $final_ids as $question_id ) {
			$this->sync_question_quiz_relations( (int) $question_id, (int) $quiz_post_id, (int) $quiz_pro_id );
		}

		$proquiz_resync = $this->resync_proquiz_questions_layer( (int) $quiz_post_id, (int) $quiz_pro_id, $final_ids, $new_valid_pros );
		if ( is_wp_error( $proquiz_resync ) ) {
			return $proquiz_resync;
		}

		if ( function_exists( 'learndash_update_quiz_questions' ) ) {
			learndash_update_quiz_questions( (int) $quiz_post_id );
		}

		clean_post_cache( (int) $quiz_post_id );
		wp_cache_delete( (int) $quiz_post_id, 'post_meta' );
		do_action( 'learndash_quiz_questions_updated', (int) $quiz_post_id, $final_builder );

		$builder_after = function_exists( 'learndash_get_quiz_questions' ) ? learndash_get_quiz_questions( (int) $quiz_post_id ) : array();
		$this->debug_log( sprintf( 'Resync end. quiz_post_id=%d | quiz_pro_id=%d | final_builder_saved=%s | final_builder_read=%s', (int) $quiz_post_id, (int) $quiz_pro_id, wp_json_encode( $final_builder ), wp_json_encode( $builder_after ) ) );

		$final_pro_ids = array();
		foreach ( $final_ids as $question_id ) {
			$pid = $this->get_pro_question_id_for_post( (int) $question_id );
			if ( $pid > 0 ) {
				$final_pro_ids[] = $pid;
			}
		}
		$final_pro_ids = array_values( array_unique( $final_pro_ids ) );

		$missing_new = array_values( array_diff( array_values( array_unique( $new_valid_pros ) ), $final_pro_ids ) );
		if ( ! empty( $missing_new ) ) {
			return new WP_Error( 'lti_resync_missing_new_questions', 'Resync incompleto: algunas preguntas nuevas no quedaron en el quiz tras la reconstrucción.' );
		}

		return true;
	}

	/**
	 * Valida si la pregunta puede participar en el builder final del quiz.
	 *
	 * @param int $question_post_id
	 * @param int $quiz_post_id
	 * @return array<string,mixed>
	 */
	private function validate_question_for_quiz_resync( $question_post_id, $quiz_post_id ) {
		if ( $question_post_id <= 0 ) {
			return array( 'valid' => false, 'reason' => 'id_invalido' );
		}

		$post = get_post( (int) $question_post_id );
		if ( ! $post || 'sfwd-question' !== $post->post_type ) {
			return array( 'valid' => false, 'reason' => 'post_no_existe_o_tipo_invalido' );
		}

		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return array( 'valid' => false, 'reason' => 'post_en_papelera_o_borrador_auto' );
		}

		$question_pro_id = (int) get_post_meta( (int) $question_post_id, 'question_pro_id', true );
		if ( $question_pro_id <= 0 ) {
			if ( function_exists( 'learndash_get_setting' ) ) {
				$question_pro_id = (int) learndash_get_setting( (int) $question_post_id, 'question_pro_id' );
			}
		}

		if ( $question_pro_id <= 0 ) {
			return array( 'valid' => false, 'reason' => 'question_pro_id_invalido' );
		}

		$linked_quiz_id = (int) get_post_meta( (int) $question_post_id, 'quiz_id', true );
		if ( $linked_quiz_id > 0 && $linked_quiz_id !== (int) $quiz_post_id ) {
			return array( 'valid' => false, 'reason' => 'quiz_id_inconsistente' );
		}

		return array( 'valid' => true, 'reason' => '' );
	}

	/**
	 * Sincroniza explícitamente las relaciones internas/meta de una pregunta con el quiz.
	 *
	 * @param int $question_post_id
	 * @param int $quiz_post_id
	 * @param int $quiz_pro_id
	 * @return void
	 */
	private function sync_question_quiz_relations( $question_post_id, $quiz_post_id, $quiz_pro_id ) {
		$question_pro_id = (int) get_post_meta( (int) $question_post_id, 'question_pro_id', true );
		if ( $question_pro_id <= 0 && function_exists( 'learndash_get_setting' ) ) {
			$question_pro_id = (int) learndash_get_setting( (int) $question_post_id, 'question_pro_id' );
		}

		if ( $question_pro_id <= 0 ) {
			$this->debug_log( sprintf( 'Sync skipped: question_pro_id invalido. question_post_id=%d', (int) $question_post_id ) );
			return;
		}

		update_post_meta( (int) $question_post_id, 'quiz_id', (int) $quiz_post_id );
		update_post_meta( (int) $question_post_id, 'question_pro_id', (int) $question_pro_id );

		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( (int) $question_post_id, 'quiz', (int) $quiz_post_id );
			learndash_update_setting( (int) $question_post_id, 'quiz_pro_id', (int) $quiz_pro_id );
			learndash_update_setting( (int) $question_post_id, 'question_pro_id', (int) $question_pro_id );
		}

		if ( function_exists( 'learndash_proquiz_sync_question_fields' ) ) {
			learndash_proquiz_sync_question_fields( (int) $question_post_id, (int) $question_pro_id );
		}

		$this->sync_proquiz_question_quiz_relation( (int) $question_pro_id, (int) $quiz_pro_id );

		$this->debug_log(
			sprintf(
				'Sync question relation. quiz_post_id=%d | quiz_pro_id=%d | question_post_id=%d | question_pro_id=%d',
				(int) $quiz_post_id,
				(int) $quiz_pro_id,
				(int) $question_post_id,
				(int) $question_pro_id
			)
		);
	}

	/**
	 * Fuerza relación interna en WpProQuiz (tabla de preguntas) con el quiz_pro_id.
	 *
	 * @param int $question_pro_id
	 * @param int $quiz_pro_id
	 * @return void
	 */
	private function sync_proquiz_question_quiz_relation( $question_pro_id, $quiz_pro_id ) {
		if ( ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) || ! class_exists( 'WpProQuiz_Model_Question' ) ) {
			return;
		}

		try {
			$mapper = new WpProQuiz_Model_QuestionMapper();
			if ( method_exists( $mapper, 'fetch' ) ) {
				$question = $mapper->fetch( (int) $question_pro_id );
				if ( $question && is_object( $question ) && method_exists( $question, 'setQuizId' ) ) {
					$question->setQuizId( (int) $quiz_pro_id );
					$mapper->save( $question );
				}
			}
		} catch ( Exception $e ) {
			$this->debug_log( 'sync_proquiz_question_quiz_relation error: ' . $e->getMessage() );
		}
	}


	/**
	 * Reconstruye capa interna WpProQuiz para el quiz existente.
	 *
	 * @param int   $quiz_post_id
	 * @param int   $quiz_pro_id
	 * @param int[] $final_question_post_ids
	 * @param int[] $new_valid_pro_ids
	 * @return true|WP_Error
	 */
	private function resync_proquiz_questions_layer( $quiz_post_id, $quiz_pro_id, $final_question_post_ids, $new_valid_pro_ids ) {
		if ( ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) ) {
			return true;
		}

		$mapper = new WpProQuiz_Model_QuestionMapper();
		if ( ! method_exists( $mapper, 'fetchAll' ) ) {
			return true;
		}

		try {
			$current_models = $mapper->fetchAll( (int) $quiz_pro_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'lti_proquiz_fetchall_error', $e->getMessage() );
		}

		$current_pro_ids = array();
		if ( is_array( $current_models ) ) {
			foreach ( $current_models as $model ) {
				if ( is_object( $model ) && method_exists( $model, 'getId' ) ) {
					$current_pro_ids[] = (int) $model->getId();
				}
			}
		}
		$this->debug_log( sprintf( 'ProQuiz current internal questions. quiz_post_id=%d | quiz_pro_id=%d | pro_ids=%s', (int) $quiz_post_id, (int) $quiz_pro_id, wp_json_encode( $current_pro_ids ) ) );

		$final_pro_ids = array();
		foreach ( $final_question_post_ids as $question_post_id ) {
			$question_pro_id = $this->get_pro_question_id_for_post( (int) $question_post_id );
			if ( $question_pro_id > 0 ) {
				$final_pro_ids[] = (int) $question_pro_id;
			}
		}
		$final_pro_ids = array_values( array_unique( $final_pro_ids ) );
		$this->debug_log( sprintf( 'ProQuiz final valid internal questions. quiz_post_id=%d | quiz_pro_id=%d | pro_ids=%s', (int) $quiz_post_id, (int) $quiz_pro_id, wp_json_encode( $final_pro_ids ) ) );

		$orphan_pro_ids = array_values( array_diff( $current_pro_ids, $final_pro_ids ) );

		foreach ( $orphan_pro_ids as $orphan_id ) {
			if ( method_exists( $mapper, 'delete' ) ) {
				$mapper->delete( (int) $orphan_id );
			} elseif ( method_exists( $mapper, 'fetch' ) && method_exists( $mapper, 'save' ) ) {
				$model = $mapper->fetch( (int) $orphan_id );
				if ( $model && is_object( $model ) && method_exists( $model, 'setQuizId' ) ) {
					$model->setQuizId( 0 );
					$mapper->save( $model );
				}
			}
		}

		$this->debug_log( sprintf( 'ProQuiz orphan internal questions removed/detached. quiz_post_id=%d | quiz_pro_id=%d | pro_ids=%s', (int) $quiz_post_id, (int) $quiz_pro_id, wp_json_encode( $orphan_pro_ids ) ) );

		$position = 1;
		foreach ( $final_pro_ids as $pro_id ) {
			if ( method_exists( $mapper, 'fetch' ) && method_exists( $mapper, 'save' ) ) {
				$model = $mapper->fetch( (int) $pro_id );
				if ( $model && is_object( $model ) ) {
					if ( method_exists( $model, 'setQuizId' ) ) {
						$model->setQuizId( (int) $quiz_pro_id );
					}
					if ( method_exists( $model, 'setSort' ) ) {
						$model->setSort( (int) $position );
					}
					$mapper->save( $model );
				}
			}
			$position++;
		}

		$new_missing = array_values( array_diff( array_values( array_unique( $new_valid_pro_ids ) ), $final_pro_ids ) );
		if ( ! empty( $new_missing ) ) {
			return new WP_Error( 'lti_proquiz_missing_new', 'Las nuevas preguntas no quedaron en la capa interna final de WpProQuiz.' );
		}

		try {
			$after_models = $mapper->fetchAll( (int) $quiz_pro_id );
			$after_ids    = array();
			if ( is_array( $after_models ) ) {
				foreach ( $after_models as $model ) {
					if ( is_object( $model ) && method_exists( $model, 'getId' ) ) {
						$after_ids[] = (int) $model->getId();
					}
				}
			}
			$this->debug_log( sprintf( 'ProQuiz final internal state. quiz_post_id=%d | quiz_pro_id=%d | pro_ids=%s', (int) $quiz_post_id, (int) $quiz_pro_id, wp_json_encode( $after_ids ) ) );
		} catch ( Exception $e ) {
			$this->debug_log( 'ProQuiz final fetchAll error: ' . $e->getMessage() );
		}

		return true;
	}

	/**
	 * @param int $question_post_id
	 * @return int
	 */
	private function get_pro_question_id_for_post( $question_post_id ) {
		$question_pro_id = (int) get_post_meta( (int) $question_post_id, 'question_pro_id', true );
		if ( $question_pro_id <= 0 && function_exists( 'learndash_get_setting' ) ) {
			$question_pro_id = (int) learndash_get_setting( (int) $question_post_id, 'question_pro_id' );
		}
		return (int) $question_pro_id;
	}

	/**
	 * @param int $quiz_post_id
	 * @return int[]
	 */
	private function get_question_ids_linked_to_quiz( $quiz_post_id ) {
		$query = get_posts(
			array(
				'post_type'      => 'sfwd-question',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'quiz_id',
						'value'   => (int) $quiz_post_id,
						'compare' => '=',
					),
					array(
						'key'     => 'ld_quiz_id',
						'value'   => (int) $quiz_post_id,
						'compare' => '=',
					),
				),
			)
		);

		return array_values( array_unique( array_map( 'intval', $query ) ) );
	}

	/**
	 * Extrae IDs de preguntas del builder sin asumir una sola forma.
	 *
	 * @param mixed $builder_data
	 * @return int[]
	 */
	private function extract_question_post_ids_from_builder( $builder_data ) {
		$ids = array();

		if ( ! is_array( $builder_data ) ) {
			return $ids;
		}

		if ( isset( $builder_data['questions'] ) && is_array( $builder_data['questions'] ) ) {
			return $this->extract_question_post_ids_from_builder( $builder_data['questions'] );
		}

		if ( $this->is_assoc_array( $builder_data ) && $this->all_keys_numeric( $builder_data ) ) {
			$ids = array_map( 'intval', array_keys( $builder_data ) );
			return array_values( array_unique( array_filter( $ids ) ) );
		}

		$first = reset( $builder_data );
		if ( ! $this->is_assoc_array( $builder_data ) && is_numeric( $first ) ) {
			$ids = array_map( 'intval', $builder_data );
			return array_values( array_unique( array_filter( $ids ) ) );
		}

		foreach ( $builder_data as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( isset( $value['question_id'] ) && is_numeric( $value['question_id'] ) ) {
				$ids[] = (int) $value['question_id'];
			}
			if ( isset( $value['post_id'] ) && is_numeric( $value['post_id'] ) ) {
				$ids[] = (int) $value['post_id'];
			}
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
				$ids[] = (int) $value['id'];
			}
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * @param int $question_post_id
	 * @param int $quiz_post_id
	 * @param int $pro_question_id
	 * @param bool $update_builder
	 * @return void
	 */
	private function link_question_post_to_pro_question( $question_post_id, $quiz_post_id, $pro_question_id, $update_builder = true ) {
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

		if ( $update_builder ) {
			$this->update_quiz_builder_questions( (int) $quiz_post_id, (int) $question_post_id );
		}
	}

	/**
	 * Actualiza builder para flujo de quiz nuevo.
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
		if ( ! is_array( $questions_before ) ) {
			$questions_before = array();
		}

		if ( ! isset( $questions_before[ (int) $question_post_id ] ) ) {
			$next_order = empty( $questions_before ) ? 1 : ( max( array_map( 'intval', array_values( $questions_before ) ) ) + 1 );
			$questions_before[ (int) $question_post_id ] = $next_order;
		}

		learndash_set_quiz_questions( (int) $quiz_post_id, $questions_before );
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

			return (int) $question_id;
		} catch ( Exception $e ) {
			return new WP_Error( 'lti_pro_question_error', $e->getMessage() );
		}
	}

	/**
	 * @param object     $model
	 * @param mixed|null $save_result
	 * @return int
	 */
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
	 * @param int   $quiz_post_id
	 * @param int   $pro_quiz_id
	 * @return void
	 */
	private function link_quiz_post_to_pro_quiz( $quiz_post_id, $pro_quiz_id ) {
		update_post_meta( $quiz_post_id, 'quiz_pro_id', (int) $pro_quiz_id );

		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( $quiz_post_id, 'quiz_pro', (int) $pro_quiz_id );
		}
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
