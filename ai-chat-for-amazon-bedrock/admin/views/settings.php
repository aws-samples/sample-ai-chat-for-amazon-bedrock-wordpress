<?php
/**
 * Settings page. Variables: $settings, $servers, $regions, $has_secret, $notice.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

defined( 'ABSPATH' ) || exit;

$aicfab_opt = AICFAB\Settings::OPTION;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'AI Chat for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ); ?></h1>

	<?php if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>
	<?php settings_errors( $aicfab_opt ); ?>

	<p><?php esc_html_e( 'Add the chat to any page with the shortcode', 'ai-chat-for-amazon-bedrock' ); ?> <code>[ai_chat_bedrock]</code>.</p>

	<form method="post" action="options.php">
		<?php settings_fields( AICFAB\Admin::GROUP ); ?>

		<h2><?php esc_html_e( 'Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="aicfab-region"><?php esc_html_e( 'Region', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><select id="aicfab-region" name="<?php echo esc_attr( $aicfab_opt ); ?>[region]">
					<?php foreach ( $regions as $aicfab_region ) : ?>
						<option value="<?php echo esc_attr( $aicfab_region ); ?>" <?php selected( $settings['region'], $aicfab_region ); ?>><?php echo esc_html( $aicfab_region ); ?></option>
					<?php endforeach; ?>
				</select></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-model"><?php esc_html_e( 'Model or inference profile ID', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-model" class="regular-text code" type="text" name="<?php echo esc_attr( $aicfab_opt ); ?>[model_id]" value="<?php echo esc_attr( $settings['model_id'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Any Bedrock model that supports the Converse API, for example us.amazon.nova-lite-v1:0. Tool use requires a model that supports tools.', 'ai-chat-for-amazon-bedrock' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-max-tokens"><?php esc_html_e( 'Max output tokens', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-max-tokens" type="number" min="64" max="8192" name="<?php echo esc_attr( $aicfab_opt ); ?>[max_tokens]" value="<?php echo esc_attr( (string) $settings['max_tokens'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-temperature"><?php esc_html_e( 'Temperature', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-temperature" type="number" min="0" max="1" step="0.05" name="<?php echo esc_attr( $aicfab_opt ); ?>[temperature]" value="<?php echo esc_attr( (string) $settings['temperature'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-system"><?php esc_html_e( 'System prompt', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><textarea id="aicfab-system" class="large-text" rows="4" name="<?php echo esc_attr( $aicfab_opt ); ?>[system_prompt]"><?php echo esc_textarea( $settings['system_prompt'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Guardrail (recommended)', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td>
					<label><?php esc_html_e( 'ID or ARN', 'ai-chat-for-amazon-bedrock' ); ?> <input class="regular-text code" type="text" name="<?php echo esc_attr( $aicfab_opt ); ?>[guardrail_id]" value="<?php echo esc_attr( $settings['guardrail_id'] ); ?>" /></label>
					<label><?php esc_html_e( 'Version', 'ai-chat-for-amazon-bedrock' ); ?> <input class="small-text" type="text" name="<?php echo esc_attr( $aicfab_opt ); ?>[guardrail_version]" value="<?php echo esc_attr( $settings['guardrail_version'] ); ?>" placeholder="DRAFT" /></label>
					<p class="description"><?php esc_html_e( 'An Amazon Bedrock Guardrail filters harmful content and prompt attacks in both directions.', 'ai-chat-for-amazon-bedrock' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Streaming', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $aicfab_opt ); ?>[enable_streaming]" value="1" <?php checked( $settings['enable_streaming'] ); ?> /> <?php esc_html_e( 'Stream responses as they are generated (requires the PHP cURL extension and a server that does not buffer responses).', 'ai-chat-for-amazon-bedrock' ); ?></label></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AWS credentials', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<p><?php esc_html_e( 'Recommended: run WordPress on AWS with an IAM role (EC2 instance profile, ECS task role or EKS Pod Identity / IRSA) that allows only bedrock:InvokeModel and bedrock:InvokeModelWithResponseStream on the model you use. No key is then stored anywhere. Alternatively define AICFAB_AWS_ACCESS_KEY_ID and AICFAB_AWS_SECRET_ACCESS_KEY in wp-config.php.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Source', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td>
					<label><input type="radio" name="<?php echo esc_attr( $aicfab_opt ); ?>[credential_source]" value="auto" <?php checked( $settings['credential_source'], 'auto' ); ?> /> <?php esc_html_e( 'Automatic: wp-config.php constants, environment, or IAM role', 'ai-chat-for-amazon-bedrock' ); ?></label><br />
					<label><input type="radio" name="<?php echo esc_attr( $aicfab_opt ); ?>[credential_source]" value="stored" <?php checked( $settings['credential_source'], 'stored' ); ?> /> <?php esc_html_e( 'Access key stored in the database (encrypted; not recommended)', 'ai-chat-for-amazon-bedrock' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-key-id"><?php esc_html_e( 'Access key ID', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-key-id" class="regular-text code" type="text" autocomplete="off" name="<?php echo esc_attr( $aicfab_opt ); ?>[access_key_id]" value="<?php echo esc_attr( $settings['access_key_id'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-secret"><?php esc_html_e( 'Secret access key', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td>
					<input id="aicfab-secret" class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr( $aicfab_opt ); ?>[secret_access_key]" value="" placeholder="<?php echo $has_secret ? esc_attr__( 'Stored. Leave blank to keep it.', 'ai-chat-for-amazon-bedrock' ) : ''; ?>" />
					<?php if ( $has_secret ) : ?>
						<br /><label><input type="checkbox" name="<?php echo esc_attr( $aicfab_opt ); ?>[clear_secret]" value="1" /> <?php esc_html_e( 'Delete the stored key', 'ai-chat-for-amazon-bedrock' ); ?></label>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Access and limits', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Who can chat', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td>
					<?php
					$aicfab_access = array(
						'logged_in' => __( 'Logged-in users only (recommended)', 'ai-chat-for-amazon-bedrock' ),
						'everyone'  => __( 'Everyone, including anonymous visitors. Every visitor spends your AWS budget; keep the limits below low and set an AWS Budgets alert.', 'ai-chat-for-amazon-bedrock' ),
						'disabled'  => __( 'Nobody (chat disabled)', 'ai-chat-for-amazon-bedrock' ),
					);
					foreach ( $aicfab_access as $aicfab_value => $aicfab_label ) :
						?>
						<label><input type="radio" name="<?php echo esc_attr( $aicfab_opt ); ?>[access]" value="<?php echo esc_attr( $aicfab_value ); ?>" <?php checked( $settings['access'], $aicfab_value ); ?> /> <?php echo esc_html( $aicfab_label ); ?></label><br />
					<?php endforeach; ?>
				</td>
			</tr>
			<?php
			$aicfab_numbers = array(
				'rate_limit_per_minute' => array( __( 'Messages per visitor per minute', 'ai-chat-for-amazon-bedrock' ), 1, 120 ),
				'daily_message_cap'     => array( __( 'Messages per day, whole site', 'ai-chat-for-amazon-bedrock' ), 1, 100000 ),
				'max_input_chars'       => array( __( 'Maximum characters per message', 'ai-chat-for-amazon-bedrock' ), 100, 20000 ),
				'max_history_turns'     => array( __( 'Previous turns sent as context', 'ai-chat-for-amazon-bedrock' ), 0, 40 ),
			);
			foreach ( $aicfab_numbers as $aicfab_key => $aicfab_field ) :
				?>
				<tr>
					<th scope="row"><label for="aicfab-<?php echo esc_attr( $aicfab_key ); ?>"><?php echo esc_html( $aicfab_field[0] ); ?></label></th>
					<td><input id="aicfab-<?php echo esc_attr( $aicfab_key ); ?>" type="number" min="<?php echo esc_attr( (string) $aicfab_field[1] ); ?>" max="<?php echo esc_attr( (string) $aicfab_field[2] ); ?>" name="<?php echo esc_attr( $aicfab_opt . '[' . $aicfab_key . ']' ); ?>" value="<?php echo esc_attr( (string) $settings[ $aicfab_key ] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Chat appearance', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="aicfab-title"><?php esc_html_e( 'Title', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-title" class="regular-text" type="text" name="<?php echo esc_attr( $aicfab_opt ); ?>[chat_title]" value="<?php echo esc_attr( $settings['chat_title'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicfab-welcome"><?php esc_html_e( 'Welcome message', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-welcome" class="large-text" type="text" name="<?php echo esc_attr( $aicfab_opt ); ?>[welcome_message]" value="<?php echo esc_attr( $settings['welcome_message'] ); ?>" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Tools', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Site content', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $aicfab_opt ); ?>[enable_site_tools]" value="1" <?php checked( $settings['enable_site_tools'] ); ?> /> <?php esc_html_e( 'Let the model search and read published, public posts and pages of this site.', 'ai-chat-for-amazon-bedrock' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP servers', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $aicfab_opt ); ?>[enable_mcp]" value="1" <?php checked( $settings['enable_mcp'] ); ?> /> <?php esc_html_e( 'Let the model call tools of the MCP servers listed below. Only add servers you trust: the model decides what to send to them, including text from the conversation.', 'ai-chat-for-amazon-bedrock' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug log', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $aicfab_opt ); ?>[debug]" value="1" <?php checked( $settings['debug'] ); ?> /> <?php esc_html_e( 'Write Bedrock error codes to the PHP error log when WP_DEBUG_LOG is on. Prompts, responses and credentials are never logged.', 'ai-chat-for-amazon-bedrock' ); ?></label></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e( 'Test the connection', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aicfab_test" />
		<?php wp_nonce_field( 'aicfab_test' ); ?>
		<p><?php esc_html_e( 'Sends one short request to the saved model (a few tokens).', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<?php submit_button( __( 'Test connection', 'ai-chat-for-amazon-bedrock' ), 'secondary', 'submit', false ); ?>
	</form>

	<h2><?php esc_html_e( 'MCP servers', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<p><?php esc_html_e( 'Remote MCP servers using the Streamable HTTP transport. URLs must be https and public. Tool lists are pinned when a server is added or refreshed; review them here.', 'ai-chat-for-amazon-bedrock' ); ?></p>
	<?php if ( empty( $servers ) ) : ?>
		<p><em><?php esc_html_e( 'No MCP servers configured.', 'ai-chat-for-amazon-bedrock' ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Server', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th><?php esc_html_e( 'Tools', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'ai-chat-for-amazon-bedrock' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $servers as $aicfab_id => $aicfab_server ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $aicfab_server['label'] ); ?></strong><br /><code><?php echo esc_html( $aicfab_server['url'] ); ?></code>
						<?php
						if ( ! empty( $aicfab_server['token'] ) ) :
							?>
							<br /><small><?php esc_html_e( 'Bearer token stored', 'ai-chat-for-amazon-bedrock' ); ?></small><?php endif; ?></td>
					<td>
						<?php if ( empty( $aicfab_server['tools'] ) ) : ?>
							<em><?php esc_html_e( 'none', 'ai-chat-for-amazon-bedrock' ); ?></em>
						<?php else : ?>
							<ul>
							<?php foreach ( $aicfab_server['tools'] as $aicfab_tool ) : ?>
								<li><code><?php echo esc_html( $aicfab_tool['name'] ); ?></code> — <?php echo esc_html( $aicfab_tool['description'] ); ?></li>
							<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
					<td>
						<?php
						foreach ( array(
							'refresh' => __( 'Refresh tools', 'ai-chat-for-amazon-bedrock' ),
							'remove'  => __( 'Remove', 'ai-chat-for-amazon-bedrock' ),
						) as $aicfab_action => $aicfab_label ) :
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="<?php echo esc_attr( 'aicfab_mcp_' . $aicfab_action ); ?>" />
								<input type="hidden" name="server" value="<?php echo esc_attr( $aicfab_id ); ?>" />
								<?php wp_nonce_field( 'aicfab_mcp_' . $aicfab_action ); ?>
								<button type="submit" class="button button-small"><?php echo esc_html( $aicfab_label ); ?></button>
							</form>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Add a server', 'ai-chat-for-amazon-bedrock' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aicfab_mcp_add" />
		<?php wp_nonce_field( 'aicfab_mcp_add' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="aicfab-mcp-label"><?php esc_html_e( 'Label', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-mcp-label" type="text" name="label" maxlength="40" required /></td></tr>
			<tr><th scope="row"><label for="aicfab-mcp-url"><?php esc_html_e( 'URL', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-mcp-url" class="regular-text code" type="url" name="url" placeholder="https://example.com/mcp" required /></td></tr>
			<tr><th scope="row"><label for="aicfab-mcp-token"><?php esc_html_e( 'Bearer token (optional)', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input id="aicfab-mcp-token" class="regular-text code" type="password" autocomplete="new-password" name="token" /></td></tr>
		</table>
		<?php submit_button( __( 'Add server', 'ai-chat-for-amazon-bedrock' ), 'secondary' ); ?>
	</form>
</div>
