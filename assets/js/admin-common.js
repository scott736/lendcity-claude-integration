/**
 * LendCity Admin Common JavaScript
 * Shared utilities for admin pages
 * @version 12.3.0
 */

(function($) {
    'use strict';

    // Global namespace
    window.LendCity = window.LendCity || {};

    /**
     * AJAX helper with standardized error handling
     * @param {string} subAction - The sub_action for the router
     * @param {object} data - Additional data to send
     * @param {function} successCallback - Success callback
     * @param {function} errorCallback - Error callback (optional)
     */
    LendCity.ajax = function(subAction, data, successCallback, errorCallback) {
        var ajaxData = $.extend({
            action: 'lendcity_action',
            sub_action: subAction,
            nonce: LendCity.nonce || ''
        }, data);

        return $.post(ajaxurl, ajaxData, function(response) {
            if (response.success) {
                if (typeof successCallback === 'function') {
                    successCallback(response.data);
                }
            } else {
                var errorMsg = response.data || 'Unknown error';
                if (typeof errorCallback === 'function') {
                    errorCallback(errorMsg);
                } else {
                    console.error('LendCity AJAX Error:', errorMsg);
                }
            }
        }).fail(function(xhr, status, error) {
            var errorMsg = 'Network error: ' + error;
            if (typeof errorCallback === 'function') {
                errorCallback(errorMsg);
            } else {
                console.error('LendCity AJAX Failure:', errorMsg);
            }
        });
    };

    /**
     * Show a loading state on a button
     * @param {jQuery} $btn - Button element
     * @param {string} text - Loading text
     */
    LendCity.buttonLoading = function($btn, text) {
        $btn.data('original-text', $btn.text());
        $btn.prop('disabled', true).text(text || 'Loading...');
    };

    /**
     * Restore a button from loading state
     * @param {jQuery} $btn - Button element
     * @param {string} text - Override text (optional)
     */
    LendCity.buttonReset = function($btn, text) {
        $btn.prop('disabled', false).text(text || $btn.data('original-text') || 'Submit');
    };

    /**
     * Format number with commas
     * @param {number} num - Number to format
     * @returns {string}
     */
    LendCity.formatNumber = function(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    };

    /**
     * Debounce function
     * @param {function} func - Function to debounce
     * @param {number} wait - Wait time in ms
     * @returns {function}
     */
    LendCity.debounce = function(func, wait) {
        var timeout;
        return function() {
            var context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    };

    /**
     * Update progress bar
     * @param {string} selector - Progress bar selector
     * @param {number} percent - Percentage complete
     * @param {string} text - Optional text to display
     */
    LendCity.updateProgress = function(selector, percent, text) {
        var $bar = $(selector);
        $bar.css('width', percent + '%');
        if (text && $bar.find('.progress-text').length) {
            $bar.find('.progress-text').text(text);
        }
    };

    /**
     * Confirm action with optional double confirmation
     * @param {string} message - Confirmation message
     * @param {boolean} doubleConfirm - Require double confirmation
     * @returns {boolean}
     */
    LendCity.confirm = function(message, doubleConfirm) {
        if (!confirm(message)) {
            return false;
        }
        if (doubleConfirm) {
            return confirm('Are you absolutely sure? This cannot be undone.');
        }
        return true;
    };

    /**
     * Initialize Select2 on elements
     * @param {string} selector - Element selector
     * @param {object} options - Select2 options
     */
    LendCity.initSelect2 = function(selector, options) {
        if (typeof $.fn.select2 !== 'undefined') {
            $(selector).each(function() {
                if (!$(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2($.extend({
                        width: '100%',
                        placeholder: 'Search...'
                    }, options));
                }
            });
        }
    };

    /**
     * Queue Status Manager
     */
    LendCity.QueueManager = {
        processing: false,
        refreshInterval: null,

        start: function(processCallback, statusCallback, delay) {
            this.processing = true;
            this.process(processCallback, statusCallback, delay || 30000);
        },

        stop: function() {
            this.processing = false;
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },

        process: function(processCallback, statusCallback, delay) {
            var self = this;
            if (!self.processing) return;

            if (typeof processCallback === 'function') {
                processCallback(function(complete) {
                    if (typeof statusCallback === 'function') {
                        statusCallback();
                    }
                    if (complete) {
                        self.stop();
                    } else if (self.processing) {
                        setTimeout(function() {
                            self.process(processCallback, statusCallback, delay);
                        }, delay);
                    }
                });
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Auto-initialize Select2 on .lendcity-select2 elements
        LendCity.initSelect2('.lendcity-select2');
    });

})(jQuery);
