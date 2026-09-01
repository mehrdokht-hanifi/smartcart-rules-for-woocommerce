( function ( $ ) {
	'use strict';

	$( function () {
		var lastPaymentMethod = $( 'input[name="payment_method"]:checked' ).val() || '';
		var updateTimer = null;

		$( document.body ).on(
			'change.scrwSmartCartRules',
			'input[name="payment_method"]',
			function () {
				var currentPaymentMethod = $( this ).val() || '';

				if ( ! currentPaymentMethod || currentPaymentMethod === lastPaymentMethod ) {
					return;
				}

				lastPaymentMethod = currentPaymentMethod;
				window.clearTimeout( updateTimer );
				updateTimer = window.setTimeout( function () {
					$( document.body ).trigger( 'update_checkout' );
				}, 50 );
			}
		);

		$( document.body ).on( 'updated_checkout', function () {
			var selectedPaymentMethod = $( 'input[name="payment_method"]:checked' ).val();
			if ( selectedPaymentMethod ) {
				lastPaymentMethod = selectedPaymentMethod;
			}
		} );

		$( document.body ).on(
			'applied_coupon_in_checkout removed_coupon_in_checkout',
			function () {
				window.clearTimeout( updateTimer );
				updateTimer = window.setTimeout( function () {
					$( document.body ).trigger( 'update_checkout' );
				}, 100 );
			}
		);
	} );
} )( jQuery );
