<?php
/**
 * Pantalla de administración para importar quizzes.
 */
class LTI_Admin_Page {
	/** @var LTI_Parser */
	private $parser;

	/** @var LTI_Validator */
	private $validator;

	/** @var LTI_LearnDash_Service */
	private $learndash;

	/** @var LTI_Import_Result|null */
	private $result = null;

	/** @var array<string,string> */
	private $form_data = array();

	/** @var array<int,array<string,mixed>> */
	private $preview_items = array();

	/**
	 * @param LTI_Parser            $parser Parser.
	 * @param LTI_Validator         $validator Validador.
	 * @param LTI_LearnDash_Service $learndash Servicio LD.
	 */
	public function __construct( LTI_Parser $parser, LTI_Validator $validator, LTI_LearnDash_Service $learndash ) {
		$this->parser    = $parser;
		$this->validator = $validator;
		$this->learndash = $learndash;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registra submenú bajo LearnDash si existe.
	 *
	 * @return void
	 */
	public function register_menu() {
		$parent_slug = post_type_exists( 'sfwd-courses' ) ? 'learndash-lms' : 'tools.php';

		add_submenu_page(
			$parent_slug,
			'Importar Test LearnDash',
			'Importar Test (Texto)',
			'manage_options',
			'lti-quiz-importer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Carga CSS/JS de la pantalla.
	 *
	 * @param string $hook_suffix Hook actual.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'lti-quiz-importer' ) ) {
			return;
		}

		wp_enqueue_style( 'lti-admin-css', LTI_PLUGIN_URL . 'assets/admin.css', array(), LTI_PLUGIN_VERSION );
		wp_enqueue_script( 'lti-admin-js', LTI_PLUGIN_URL . 'assets/admin.js', array(), LTI_PLUGIN_VERSION, true );
	}

	/**
	 * Muestra pantalla y procesa importación.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'ld-text-importer' ) );
		}

		$this->form_data = $this->get_form_data_from_request();

		$view = 'form';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lti_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['lti_action'] ) );
			if ( 'preview_import' === $action ) {
				$view = $this->handle_preview_request();
			} elseif ( 'confirm_import' === $action ) {
				$view = $this->handle_confirm_request();
			} elseif ( 'back_to_edit' === $action ) {
				$view = 'form';
			}
		}

		?>
		<div class="wrap lti-wrap">
			<h1><?php echo esc_html__( 'Importar Quiz de LearnDash desde texto', 'ld-text-importer' ); ?></h1>
			<p><?php echo esc_html__( 'Paso 1: validar y previsualizar. Paso 2: confirmar importación.', 'ld-text-importer' ); ?></p>

			<?php $this->render_notices(); ?>

			<?php
			if ( 'preview' === $view ) {
				$this->render_preview();
			} else {
				$this->render_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * @return string form|preview
	 */
	private function handle_preview_request() {
		$result = new LTI_Import_Result();

		if ( ! isset( $_POST['lti_import_quiz_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lti_import_quiz_nonce'] ) ), 'lti_import_quiz_action' ) ) {
			$result->add_error( 'Error de seguridad (nonce inválido). Recarga la página e inténtalo de nuevo.' );
			$this->result = $result;
			return 'form';
		}

		$mode = $this->form_data['import_mode'];

		if ( 'create_new' === $mode && '' === $this->form_data['quiz_title'] ) {
			$result->add_error( 'Debes indicar un título para el test.' );
		}

		if ( 'add_existing' === $mode && (int) $this->form_data['existing_quiz_id'] <= 0 ) {
			$result->add_error( 'Debes seleccionar un quiz existente para añadir preguntas.' );
		}

		if ( ! $this->learndash->is_available() ) {
			$result->add_error( 'LearnDash no parece estar activo o disponible en esta instalación.' );
		}

		$items             = $this->parser->parse( $this->form_data['content'] );
		$validation_result = $this->validator->validate( $items );
		if ( $validation_result->has_errors() ) {
			foreach ( $validation_result->get_errors() as $error ) {
				$result->add_error( $error );
			}
		}

		if ( $result->has_errors() ) {
			$this->result = $result;
			return 'form';
		}

		$this->preview_items = $items;
		$this->result        = null;
		return 'preview';
	}

	/**
	 * @return string form
	 */
	private function handle_confirm_request() {
		$result = new LTI_Import_Result();

		if ( ! isset( $_POST['lti_confirm_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lti_confirm_import_nonce'] ) ), 'lti_confirm_import_action' ) ) {
			$result->add_error( 'Error de seguridad al confirmar la importación. Vuelve a previsualizar e inténtalo de nuevo.' );
			$this->result = $result;
			return 'form';
		}

		$mode = $this->form_data['import_mode'];
		$items = $this->parser->parse( $this->form_data['content'] );
		$validation_result = $this->validator->validate( $items );

		if ( $validation_result->has_errors() ) {
			$this->result = $validation_result;
			return 'form';
		}

		$this->result = $this->learndash->import_questions(
			$mode,
			$this->form_data['quiz_title'],
			$this->form_data['quiz_description'],
			$items,
			(int) $this->form_data['existing_quiz_id']
		);

		return 'form';
	}

	/**
	 * @return void
	 */
	private function render_form() {
		$quizzes = $this->learndash->get_existing_quizzes();
		?>
		<form method="post" action="" id="lti-import-form">
			<?php wp_nonce_field( 'lti_import_quiz_action', 'lti_import_quiz_nonce' ); ?>
			<input type="hidden" name="lti_action" value="preview_import" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Modo de importación</th>
					<td>
						<label><input type="radio" name="lti_import_mode" value="create_new" <?php checked( 'create_new', $this->form_data['import_mode'] ); ?> /> Crear nuevo test</label><br />
						<label><input type="radio" name="lti_import_mode" value="add_existing" <?php checked( 'add_existing', $this->form_data['import_mode'] ); ?> /> Añadir preguntas a test existente</label>
					</td>
				</tr>
				<tr class="lti-row-create-new">
					<th scope="row"><label for="lti_quiz_title">Título del test</label></th>
					<td><input name="lti_quiz_title" id="lti_quiz_title" type="text" class="regular-text" value="<?php echo esc_attr( $this->form_data['quiz_title'] ); ?>" /></td>
				</tr>
				<tr class="lti-row-create-new">
					<th scope="row"><label for="lti_quiz_description">Descripción del test (opcional)</label></th>
					<td><textarea name="lti_quiz_description" id="lti_quiz_description" class="large-text" rows="3"><?php echo esc_textarea( $this->form_data['quiz_description'] ); ?></textarea></td>
				</tr>
				<tr class="lti-row-existing-quiz">
					<th scope="row"><label for="lti_existing_quiz_id">Quiz existente</label></th>
					<td>
						<select name="lti_existing_quiz_id" id="lti_existing_quiz_id">
							<option value="0">Selecciona un quiz...</option>
							<?php foreach ( $quizzes as $quiz ) : ?>
								<option value="<?php echo esc_attr( (string) $quiz['id'] ); ?>" <?php selected( (int) $this->form_data['existing_quiz_id'], (int) $quiz['id'] ); ?>>
									<?php echo esc_html( sprintf( '#%d - %s', (int) $quiz['id'], $quiz['title'] ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lti_content">Contenido</label></th>
					<td><textarea name="lti_content" id="lti_content" class="large-text code" rows="18" required><?php echo esc_textarea( $this->form_data['content'] ); ?></textarea></td>
				</tr>
			</table>

			<?php submit_button( 'Previsualizar importación' ); ?>
		</form>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_preview() {
		$mode          = $this->form_data['import_mode'];
		$quiz_title    = 'create_new' === $mode ? $this->form_data['quiz_title'] : get_the_title( (int) $this->form_data['existing_quiz_id'] );
		$quiz_desc     = $this->form_data['quiz_description'];
		$total         = count( $this->preview_items );
		?>
		<div class="notice notice-info">
			<p><strong>Previsualización validada.</strong> Revisa la información y confirma para importar.</p>
		</div>

		<div class="lti-preview-summary">
			<p><strong>Modo:</strong> <?php echo esc_html( 'create_new' === $mode ? 'Crear nuevo test' : 'Añadir a test existente' ); ?></p>
			<p><strong>Título del test:</strong> <?php echo esc_html( $quiz_title ); ?></p>
			<?php if ( 'create_new' === $mode ) : ?>
				<p><strong>Descripción:</strong> <?php echo esc_html( $quiz_desc ); ?></p>
			<?php endif; ?>
			<p><strong>Preguntas detectadas:</strong> <?php echo esc_html( (string) $total ); ?></p>
		</div>

		<div class="lti-preview-list">
			<?php foreach ( $this->preview_items as $item ) : ?>
				<div class="lti-preview-question">
					<h3><?php echo esc_html( $item['title'] ); ?></h3>
					<p><strong>Enunciado:</strong> <?php echo esc_html( $item['question'] ); ?></p>
					<ul>
						<?php foreach ( $item['answers'] as $answer ) : ?>
							<li class="<?php echo ! empty( $answer['is_correct'] ) ? 'lti-answer-correct' : ''; ?>">
								<?php echo ! empty( $answer['is_correct'] ) ? '✅ ' : ''; ?><?php echo esc_html( $answer['text'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p><strong>Comentario:</strong> <?php echo esc_html( $item['comment'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="lti-preview-actions">
			<form method="post" action="" style="display:inline-block; margin-right: 10px;">
				<?php wp_nonce_field( 'lti_import_quiz_action', 'lti_import_quiz_nonce' ); ?>
				<input type="hidden" name="lti_action" value="back_to_edit" />
				<?php $this->render_hidden_state_fields(); ?>
				<?php submit_button( 'Volver a editar', 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="" style="display:inline-block;">
				<?php wp_nonce_field( 'lti_confirm_import_action', 'lti_confirm_import_nonce' ); ?>
				<input type="hidden" name="lti_action" value="confirm_import" />
				<?php $this->render_hidden_state_fields(); ?>
				<?php submit_button( 'Confirmar importación', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_hidden_state_fields() {
		?>
		<input type="hidden" name="lti_import_mode" value="<?php echo esc_attr( $this->form_data['import_mode'] ); ?>" />
		<input type="hidden" name="lti_quiz_title" value="<?php echo esc_attr( $this->form_data['quiz_title'] ); ?>" />
		<input type="hidden" name="lti_quiz_description" value="<?php echo esc_attr( $this->form_data['quiz_description'] ); ?>" />
		<input type="hidden" name="lti_existing_quiz_id" value="<?php echo esc_attr( $this->form_data['existing_quiz_id'] ); ?>" />
		<textarea name="lti_content" style="display:none;"><?php echo esc_textarea( $this->form_data['content'] ); ?></textarea>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private function get_form_data_from_request() {
		$quiz_title       = isset( $_POST['lti_quiz_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lti_quiz_title'] ) ) : '';
		$quiz_desc        = isset( $_POST['lti_quiz_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lti_quiz_description'] ) ) : '';
		$content          = isset( $_POST['lti_content'] ) ? wp_unslash( $_POST['lti_content'] ) : '';
		$import_mode      = isset( $_POST['lti_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['lti_import_mode'] ) ) : 'create_new';
		$existing_quiz_id = isset( $_POST['lti_existing_quiz_id'] ) ? (string) absint( wp_unslash( $_POST['lti_existing_quiz_id'] ) ) : '0';

		if ( ! in_array( $import_mode, array( 'create_new', 'add_existing' ), true ) ) {
			$import_mode = 'create_new';
		}

		return array(
			'quiz_title'       => $quiz_title,
			'quiz_description' => $quiz_desc,
			'content'          => $content,
			'import_mode'      => $import_mode,
			'existing_quiz_id' => $existing_quiz_id,
		);
	}

	/**
	 * @return void
	 */
	private function render_notices() {
		if ( ! $this->result ) {
			return;
		}

		if ( $this->result->has_errors() ) {
			echo '<div class="notice notice-error"><p><strong>Se encontraron errores y no se creó/actualizó ningún quiz:</strong></p><ul>';
			foreach ( $this->result->get_errors() as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>' . esc_html( implode( ' ', $this->result->get_messages() ) ) . '</p>';

		echo '<p><strong>Resumen final</strong></p><ul>';
		echo '<li><strong>Acción:</strong> ' . esc_html( 'create_new' === $this->result->get_data( 'mode' ) ? 'Se creó un test nuevo' : 'Se actualizó un test existente' ) . '</li>';
		echo '<li><strong>Quiz:</strong> ' . esc_html( (string) $this->result->get_data( 'quiz_title', '' ) ) . '</li>';
		echo '<li><strong>ID Quiz:</strong> ' . esc_html( (string) $this->result->get_data( 'quiz_post_id', 0 ) ) . '</li>';
		echo '<li><strong>Preguntas creadas:</strong> ' . esc_html( (string) $this->result->get_data( 'questions_created', 0 ) ) . '</li>';
		echo '</ul>';

		$quiz_edit_link = $this->result->get_data( 'quiz_edit_link', '' );
		if ( $quiz_edit_link ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( $quiz_edit_link ) . '">Editar quiz</a></p>';
		}

		$created_questions = $this->result->get_data( 'created_questions', array() );
		if ( ! empty( $created_questions ) && is_array( $created_questions ) ) {
			echo '<p><strong>Preguntas creadas:</strong></p><ul>';
			foreach ( $created_questions as $question ) {
				$title = isset( $question['title'] ) ? $question['title'] : '';
				$link  = isset( $question['edit_link'] ) ? $question['edit_link'] : '';
				echo '<li>' . esc_html( $title );
				if ( $link ) {
					echo ' - <a href="' . esc_url( $link ) . '">Editar pregunta</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}
}
