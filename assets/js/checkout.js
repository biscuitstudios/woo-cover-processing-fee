jQuery(function ($) {

	// Physically move the box to sit directly above #payment.
	// Runs on load and after every checkout refresh, so it stays in
	// place no matter what the donation plugin's hook/priority does.
	function wooRepositionFeeBox() {
		var $box = $('#woo-cover-fee-wrapper');
		var $payment = $('#payment');
		if ($box.length && $payment.length) {
			$box.insertBefore($payment);
		}
	}

	wooRepositionFeeBox();
	$(document.body).on('updated_checkout', wooRepositionFeeBox);

	$('form.checkout').on('change', '#woo_cover_fee', function () {
		var $box = $('#woo-cover-fee-wrapper');
		var isChecked = $(this).is(':checked');

		$box.addClass('is-busy');

		$.post(
			wooCoverFee.ajaxUrl,
			{
				action: 'woo_cover_fee',
				checked: isChecked,
				nonce: wooCoverFee.nonce
			}
		).done(function () {
			// Server owns the state; re-render totals from it.
			$(document.body).trigger('update_checkout');
		}).fail(function () {
			// Most likely an expired nonce on a long-idle checkout page.
			// Roll the checkbox back so the UI can't claim a fee that the
			// session doesn't actually have.
			$('#woo_cover_fee').prop('checked', !isChecked);
		}).always(function () {
			$box.removeClass('is-busy');
		});
	});
});
