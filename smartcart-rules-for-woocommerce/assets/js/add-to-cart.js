( function ( $ ) {
	'use strict';

	var hideTimer = null;

	function hideToast() {
		var $toast = $( '#scrw-add-to-cart-toast' );
		$toast.removeClass( 'scrw-toast-visible' ).attr( 'aria-hidden', 'true' );
	}

	function showToast() {
		var $toast = $( '#scrw-add-to-cart-toast' );

		if ( ! $toast.length || ! $.trim( $toast.text() ) ) {
			return;
		}

		window.clearTimeout( hideTimer );
		$toast.addClass( 'scrw-toast-visible' ).attr( 'aria-hidden', 'false' );
		hideTimer = window.setTimeout( hideToast, 7500 );
	}

	$( document.body ).on( 'added_to_cart', function () {
		window.setTimeout( showToast, 30 );
	} );

	$( document ).on( 'click', '.scrw-toast-close', hideToast );
} )( jQuery );
