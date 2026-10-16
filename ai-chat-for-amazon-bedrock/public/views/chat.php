<?php
/**
 * Chat markup. Variables: $atts (array), $settings (array), $height (string, validated).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

defined( 'ABSPATH' ) || exit;
$aicfab_input_id = 'aicfab-input-' . wp_unique_id();
?>
<section class="aicfab" data-aicfab aria-label="<?php echo esc_attr( $atts['title'] ); ?>">
	<header class="aicfab__header"><?php echo esc_html( $atts['title'] ); ?></header>
	<div class="aicfab__log" role="log" aria-live="polite" style="height: <?php echo esc_attr( $height ); ?>">
		<div class="aicfab__msg aicfab__msg--assistant"><?php echo esc_html( $settings['welcome_message'] ); ?></div>
	</div>
	<form class="aicfab__form">
		<label class="screen-reader-text" for="<?php echo esc_attr( $aicfab_input_id ); ?>"><?php esc_html_e( 'Your message', 'ai-chat-for-amazon-bedrock' ); ?></label>
		<textarea id="<?php echo esc_attr( $aicfab_input_id ); ?>" class="aicfab__input" rows="2" maxlength="<?php echo esc_attr( (string) $settings['max_input_chars'] ); ?>" placeholder="<?php esc_attr_e( 'Type your message…', 'ai-chat-for-amazon-bedrock' ); ?>"></textarea>
		<div class="aicfab__actions">
			<button type="button" class="aicfab__clear"><?php esc_html_e( 'Clear', 'ai-chat-for-amazon-bedrock' ); ?></button>
			<button type="submit" class="aicfab__send"><?php esc_html_e( 'Send', 'ai-chat-for-amazon-bedrock' ); ?></button>
		</div>
	</form>
	<p class="aicfab__footer"><?php esc_html_e( 'AI-generated responses may be inaccurate. Do not share sensitive information.', 'ai-chat-for-amazon-bedrock' ); ?></p>
</section>
