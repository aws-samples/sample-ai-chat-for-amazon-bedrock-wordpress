/**
 * Chat UI. Model output is untrusted and is only ever inserted with textContent (no HTML rendering).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */
( function () {
	'use strict';

	const config = window.aicfabChat;
	if ( ! config ) {
		return;
	}

	function headers() {
		const h = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			h[ 'X-WP-Nonce' ] = config.nonce;
		}
		return h;
	}

	function addMessage( log, role, text ) {
		const el = document.createElement( 'div' );
		el.className = 'aicfab__msg aicfab__msg--' + role;
		el.textContent = text;
		log.appendChild( el );
		log.scrollTop = log.scrollHeight;
		return el;
	}

	async function readError( response ) {
		try {
			const body = await response.json();
			if ( body && typeof body.message === 'string' ) {
				return body.message;
			}
		} catch ( e ) {}
		return config.i18n.error;
	}

	// Parse an SSE byte stream; calls onEvent( name, data ) for each event.
	async function readStream( response, onEvent ) {
		const reader = response.body.getReader();
		const decoder = new TextDecoder();
		let buffer = '';
		for ( ;; ) {
			const { value, done } = await reader.read();
			if ( done ) {
				break;
			}
			buffer += decoder.decode( value, { stream: true } );
			let index;
			while ( ( index = buffer.indexOf( '\n\n' ) ) >= 0 ) {
				const frame = buffer.slice( 0, index );
				buffer = buffer.slice( index + 2 );
				let name = 'message';
				const data = [];
				frame.split( '\n' ).forEach( function ( line ) {
					if ( line.startsWith( 'event:' ) ) {
						name = line.slice( 6 ).trim();
					} else if ( line.startsWith( 'data:' ) ) {
						data.push( line.slice( 5 ).trim() );
					}
				} );
				if ( data.length ) {
					let parsed = null;
					try {
						parsed = JSON.parse( data.join( '\n' ) );
					} catch ( e ) {
						continue;
					}
					onEvent( name, parsed ); // May throw to abort the stream.
				}
			}
		}
	}

	function init( root ) {
		const log = root.querySelector( '.aicfab__log' );
		const form = root.querySelector( '.aicfab__form' );
		const input = root.querySelector( '.aicfab__input' );
		const send = root.querySelector( '.aicfab__send' );
		const clear = root.querySelector( '.aicfab__clear' );
		const welcome = log.cloneNode( true );
		let history = [];
		let busy = false;

		function setBusy( value ) {
			busy = value;
			send.disabled = value;
			input.disabled = value;
		}

		clear.addEventListener( 'click', function () {
			history = [];
			log.replaceChildren( ...welcome.cloneNode( true ).childNodes );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				form.requestSubmit();
			}
		} );

		form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const text = input.value.trim();
			if ( ! text || busy ) {
				return;
			}
			if ( text.length > config.maxChars ) {
				addMessage( log, 'error', config.i18n.tooLong );
				return;
			}
			input.value = '';
			addMessage( log, 'user', text );
			const reply = addMessage( log, 'assistant', config.i18n.thinking );
			reply.classList.add( 'is-pending' );
			setBusy( true );

			let answer = '';
			let failed = false;
			try {
				const response = await fetch( config.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: headers(),
					body: JSON.stringify( {
						message: text,
						history: history.slice( -config.maxTurns ),
						stream: !! config.stream,
					} ),
				} );
				if ( ! response.ok ) {
					throw new Error( await readError( response ) );
				}
				const type = response.headers.get( 'Content-Type' ) || '';
				if ( type.indexOf( 'text/event-stream' ) === 0 && response.body ) {
					await readStream( response, function ( name, data ) {
						if ( name === 'text' && typeof data.delta === 'string' ) {
							answer += data.delta;
							reply.classList.remove( 'is-pending' );
							reply.textContent = answer;
							log.scrollTop = log.scrollHeight;
						} else if ( name === 'tool' ) {
							if ( answer ) {
								answer += '\n\n';
							}
							reply.classList.add( 'is-pending' );
							reply.textContent = answer || config.i18n.tool;
						} else if ( name === 'error' ) {
							throw new Error( typeof data.message === 'string' ? data.message : config.i18n.error );
						}
					} );
				} else {
					const body = await response.json();
					answer = body && typeof body.text === 'string' ? body.text : '';
				}
				if ( ! answer.trim() ) {
					throw new Error( config.i18n.error );
				}
				reply.textContent = answer.trim();
			} catch ( error ) {
				failed = true;
				reply.className = 'aicfab__msg aicfab__msg--error';
				reply.textContent = error && error.message ? error.message : config.i18n.error;
			} finally {
				reply.classList.remove( 'is-pending' );
				setBusy( false );
				input.focus();
			}
			if ( ! failed ) {
				history.push( { role: 'user', text: text }, { role: 'assistant', text: answer.trim() } );
				history = history.slice( -config.maxTurns );
			}
		} );
	}

	document.querySelectorAll( '[data-aicfab]' ).forEach( init );
}() );
