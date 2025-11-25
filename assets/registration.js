jQuery(document).ready(function($) {
    'use strict';

    // Field counter
    function updateFieldCount() {
        var count = 0;
        $('.wfa-optional-field').each(function() {
            if ($(this).val().trim() !== '') {
                count++;
            }
        });

        $('#wfa-field-count').text(count);

        // Enable/disable verify button based on field count
        var emailFilled = $('#wfa_email').val().trim() !== '';
        $('#wfa-verify-button').prop('disabled', !emailFilled || count < 3);

        // Visual feedback
        if (count >= 3) {
            $('.wfa-fields-info').removeClass('wfa-error').addClass('wfa-success');
        } else {
            $('.wfa-fields-info').removeClass('wfa-success');
        }
    }

    // Update count on field change
    $('.wfa-optional-field, #wfa_email').on('input change', updateFieldCount);

    // Step 1: Verification form submission
    $('#wfa-verification-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $('#wfa-verify-button');
        var $spinner = $form.find('.wfa-spinner');
        var $message = $('#wfa-verification-message');

        // Check email is filled
        if ($('#wfa_email').val().trim() === '') {
            $message.html('<div class="wfa-error">Please enter your email address.</div>');
            return;
        }

        // Check at least 3 fields provided
        var fieldCount = 0;
        $('.wfa-optional-field').each(function() {
            if ($(this).val().trim() !== '') {
                fieldCount++;
            }
        });

        if (fieldCount < 3) {
            $message.html('<div class="wfa-error">Please provide at least 3 verification fields.</div>');
            return;
        }

        $button.prop('disabled', true);
        $spinner.show();
        $message.html('');

        $.ajax({
            url: wfaRegistration.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_verify_details',
                nonce: $form.find('[name="wfa_registration_nonce"]').val(),
                email: $('#wfa_email').val(),
                last_name: $('#wfa_last_name').val(),
                employee_id: $('#wfa_employee_id').val(),
                date_of_birth: $('#wfa_date_of_birth').val(),
                phone: $('#wfa_phone').val(),
                passcode: $('#wfa_passcode').val(),
                postcode: $('#wfa_postcode').val()
            },
            success: function(response) {
                if (response.success) {
                    // Store token and show step 2
                    $('#wfa_token').val(response.data.token);
                    $('#wfa-step-1').hide();
                    $('#wfa-step-2').show();
                } else {
                    $message.html('<div class="wfa-error">' + response.data + '</div>');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $message.html('<div class="wfa-error">Connection error. Please try again.</div>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.hide();
            }
        });
    });

    // Step 2: Password form submission
    $('#wfa-password-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var $spinner = $form.find('.wfa-spinner');
        var $message = $('#wfa-password-message');

        var password = $('#wfa_password').val();
        var passwordConfirm = $('#wfa_password_confirm').val();

        // Validate passwords
        if (password.length < 8) {
            $message.html('<div class="wfa-error">Password must be at least 8 characters long.</div>');
            return;
        }

        if (password !== passwordConfirm) {
            $message.html('<div class="wfa-error">Passwords do not match.</div>');
            return;
        }

        $button.prop('disabled', true);
        $spinner.show();
        $message.html('');

        $.ajax({
            url: wfaRegistration.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_complete_registration',
                nonce: $form.find('[name="wfa_registration_nonce_step2"]').val(),
                token: $('#wfa_token').val(),
                password: password,
                password_confirm: passwordConfirm
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<div class="wfa-success">' + response.data.message + '</div>');

                    // Redirect if provided
                    if (response.data.redirect) {
                        setTimeout(function() {
                            window.location.href = response.data.redirect;
                        }, 1500);
                    }
                } else {
                    $message.html('<div class="wfa-error">' + response.data + '</div>');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $message.html('<div class="wfa-error">Connection error. Please try again.</div>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.hide();
            }
        });
    });

    // Initial field count
    updateFieldCount();
});
