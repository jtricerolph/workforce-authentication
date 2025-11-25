<?php
/**
 * Registration form template.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wfa-registration-container">
    <h2>Register</h2>

    <!-- Step 1: Verification -->
    <div id="wfa-step-1" class="wfa-registration-step">
        <p>Please enter your email address and at least <strong>3</strong> of the following verification fields, email and verification fields must match your workforce account details:</p>

        <form id="wfa-verification-form">
            <?php wp_nonce_field('wfa_registration_nonce', 'wfa_registration_nonce'); ?>

            <div class="wfa-form-group wfa-required-field">
                <label for="wfa_email">Email Address <span class="required">*</span></label>
                <input type="email" id="wfa_email" name="email" required>
            </div>

            <div class="wfa-verification-fields">
                <p class="wfa-fields-info">Provide at least 3 of the following fields (<span id="wfa-field-count">0</span>/6 provided):</p>

                <div class="wfa-form-group">
                    <label for="wfa_last_name">Last Name</label>
                    <input type="text" id="wfa_last_name" name="last_name" class="wfa-optional-field">
                </div>

                <div class="wfa-form-group">
                    <label for="wfa_employee_id">Employee ID</label>
                    <input type="text" id="wfa_employee_id" name="employee_id" class="wfa-optional-field">
                </div>

                <div class="wfa-form-group">
                    <label for="wfa_date_of_birth">Date of Birth <small>(DD/MM/YYYY)</small></label>
                    <input type="text" id="wfa_date_of_birth" name="date_of_birth" placeholder="DD/MM/YYYY" class="wfa-optional-field">
                </div>

                <div class="wfa-form-group">
                    <label for="wfa_phone">Phone Number</label>
                    <input type="tel" id="wfa_phone" name="phone" class="wfa-optional-field">
                </div>

                <div class="wfa-form-group">
                    <label for="wfa_passcode">Passcode (PIN)</label>
                    <input type="text" id="wfa_passcode" name="passcode" class="wfa-optional-field">
                </div>

                <div class="wfa-form-group">
                    <label for="wfa_postcode">Postcode</label>
                    <input type="text" id="wfa_postcode" name="postcode" class="wfa-optional-field">
                </div>
            </div>

            <div class="wfa-form-actions">
                <button type="submit" class="wfa-button wfa-button-primary" id="wfa-verify-button" disabled>Verify Details</button>
                <span class="wfa-spinner" style="display: none;"></span>
            </div>

            <div id="wfa-verification-message" class="wfa-message"></div>
        </form>
    </div>

    <!-- Step 2: Password Creation -->
    <div id="wfa-step-2" class="wfa-registration-step" style="display: none;">
        <p class="wfa-success-message">✓ Details verified successfully!</p>
        <p>Please create a password for your account:</p>

        <form id="wfa-password-form">
            <?php wp_nonce_field('wfa_registration_nonce', 'wfa_registration_nonce_step2'); ?>
            <input type="hidden" id="wfa_token" name="token" value="">

            <div class="wfa-form-group">
                <label for="wfa_password">Password <span class="required">*</span></label>
                <input type="password" id="wfa_password" name="password" required minlength="8">
                <small>Minimum 8 characters</small>
            </div>

            <div class="wfa-form-group">
                <label for="wfa_password_confirm">Confirm Password <span class="required">*</span></label>
                <input type="password" id="wfa_password_confirm" name="password_confirm" required minlength="8">
            </div>

            <div class="wfa-form-actions">
                <button type="submit" class="wfa-button wfa-button-primary">Create Account</button>
                <span class="wfa-spinner" style="display: none;"></span>
            </div>

            <div id="wfa-password-message" class="wfa-message"></div>
        </form>
    </div>

    <div class="wfa-login-link">
        Already have an account? <a href="<?php echo wp_login_url(); ?>">Log in here</a>
    </div>
</div>
