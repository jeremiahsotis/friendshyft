/**
 * FriendShyft Volunteer Portal JavaScript
 * Handles AJAX interactions, loading states, and double-submit prevention
 */

(function($) {
    'use strict';

    /**
     * Add loading spinner to button
     */
    function addLoadingState($button) {
        $button.prop('disabled', true);
        $button.data('original-text', $button.html());
        $button.html('<span class="fs-spinner"></span> ' + $button.text());
        $button.addClass('fs-loading');
    }

    /**
     * Remove loading spinner from button
     */
    function removeLoadingState($button) {
        $button.prop('disabled', false);
        var originalText = $button.data('original-text');
        if (originalText) {
            $button.html(originalText);
        }
        $button.removeClass('fs-loading');
    }

    /**
     * Show user-friendly error message
     */
    function showError(message, $container) {
        var $error = $('<div class="fs-message fs-error"></div>').text(message);
        $container.prepend($error);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $error.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);

        // Scroll to error
        $('html, body').animate({
            scrollTop: $error.offset().top - 100
        }, 300);
    }

    /**
     * Show success message
     */
    function showSuccess(message, $container) {
        var $success = $('<div class="fs-message fs-success"></div>').text(message);
        $container.prepend($success);

        // Auto-dismiss after 3 seconds
        setTimeout(function() {
            $success.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    /**
     * Handle opportunity signup
     */
    $(document).on('click', '.fs-signup-btn', function(e) {
        e.preventDefault();

        var $button = $(this);
        var opportunityId = $button.data('opportunity-id');
        var shiftId = $button.data('shift-id') || null;
        var $container = $button.closest('.fs-opportunity, .fs-portal-section');

        // Prevent double-submit
        if ($button.hasClass('fs-loading')) {
            return false;
        }

        addLoadingState($button);

        $.ajax({
            url: friendshyft_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'fs_signup_opportunity',
                opportunity_id: opportunityId,
                shift_id: shiftId,
                _ajax_nonce: friendshyft_ajax.nonce
            },
            success: function(response) {
                removeLoadingState($button);

                if (response.success) {
                    showSuccess(response.data.message || 'Successfully signed up!', $container);

                    // Update button to show signed up state
                    $button.text('✓ Signed Up');
                    $button.addClass('fs-signed-up');
                    $button.prop('disabled', true);

                    // Refresh page after 2 seconds to show updated data
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showError(response.data.message || 'Failed to sign up. Please try again.', $container);
                }
            },
            error: function() {
                removeLoadingState($button);
                showError('A connection error occurred. Please check your internet and try again.', $container);
            }
        });
    });

    /**
     * Handle signup cancellation
     */
    $(document).on('click', '.fs-cancel-signup-btn', function(e) {
        e.preventDefault();

        var $button = $(this);
        var signupId = $button.data('signup-id');
        var $container = $button.closest('.fs-signup-item, .fs-portal-section');

        // Confirm cancellation
        if (!confirm('Are you sure you want to cancel this signup?')) {
            return false;
        }

        // Prevent double-submit
        if ($button.hasClass('fs-loading')) {
            return false;
        }

        addLoadingState($button);

        $.ajax({
            url: friendshyft_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'fs_cancel_signup',
                signup_id: signupId,
                _ajax_nonce: friendshyft_ajax.nonce
            },
            success: function(response) {
                removeLoadingState($button);

                if (response.success) {
                    showSuccess(response.data.message || 'Signup cancelled successfully.', $container);

                    // Remove the signup item from DOM
                    $container.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    showError(response.data.message || 'Failed to cancel signup. Please try again.', $container);
                }
            },
            error: function() {
                removeLoadingState($button);
                showError('A connection error occurred. Please try again.', $container);
            }
        });
    });

    /**
     * Handle workflow step completion
     */
    $(document).on('click', '.fs-complete-step-btn', function(e) {
        e.preventDefault();

        var $button = $(this);
        var progressId = $button.data('progress-id');
        var stepName = $button.data('step-name');
        var $container = $button.closest('.fs-workflow-step, .fs-portal-section');

        // Prevent double-submit
        if ($button.hasClass('fs-loading')) {
            return false;
        }

        addLoadingState($button);

        $.ajax({
            url: friendshyft_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'fs_complete_step',
                progress_id: progressId,
                step_name: stepName,
                _ajax_nonce: friendshyft_ajax.nonce
            },
            success: function(response) {
                removeLoadingState($button);

                if (response.success) {
                    showSuccess('Step completed! Great work!', $container);

                    // Mark step as complete visually
                    $button.closest('.fs-workflow-step').addClass('fs-completed');
                    $button.text('✓ Completed');
                    $button.prop('disabled', true);
                } else {
                    showError(response.data.message || 'Failed to complete step. Please try again.', $container);
                }
            },
            error: function() {
                removeLoadingState($button);
                showError('A connection error occurred. Please try again.', $container);
            }
        });
    });

    /**
     * Initialize on page load
     */
    $(document).ready(function() {
        // Add fade-in animation to portal sections
        $('.fs-portal-section').css('opacity', 0).animate({opacity: 1}, 300);
    });

    /**
     * Browse Opportunities - Search, Filter, and Sort Functionality
     */
    $(document).ready(function() {
        var $cards = $('.opportunity-card');
        var $searchInput = $('#opportunity-search');
        var $dateFilter = $('#date-filter');
        var $availabilityFilter = $('#availability-filter');
        var $sortBy = $('#sort-by');
        var $resetBtn = $('#reset-filters');
        var $visibleCount = $('#visible-count');
        var $totalCount = $('#total-count');

        // Exit if no cards found (not on browse page)
        if ($cards.length === 0) {
            return;
        }

        // Store original order
        var originalOrder = [];
        $cards.each(function() {
            originalOrder.push($(this));
        });

        function filterAndSort() {
            var searchTerm = $searchInput.val().toLowerCase();
            var dateFilter = $dateFilter.val();
            var availabilityFilter = $availabilityFilter.val();
            var sortBy = $sortBy.val();

            // Calculate date ranges
            var today = new Date();
            today.setHours(0, 0, 0, 0);

            var thisWeekEnd = new Date(today);
            thisWeekEnd.setDate(today.getDate() + (7 - today.getDay()));

            var nextWeekStart = new Date(thisWeekEnd);
            nextWeekStart.setDate(thisWeekEnd.getDate() + 1);
            var nextWeekEnd = new Date(nextWeekStart);
            nextWeekEnd.setDate(nextWeekStart.getDate() + 6);

            var thisMonthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            var nextMonthStart = new Date(today.getFullYear(), today.getMonth() + 1, 1);
            var nextMonthEnd = new Date(today.getFullYear(), today.getMonth() + 2, 0);

            // Filter cards
            var visibleCards = [];
            $cards.each(function() {
                var $card = $(this);
                var title = $card.data('title') || '';
                var description = $card.data('description') || '';
                var location = $card.data('location') || '';
                var dateStr = $card.data('date');
                var isFull = $card.data('is-full') === 'true' || $card.data('is-full') === true;
                var spotsRemaining = parseInt($card.data('spots-remaining')) || 0;

                // Search filter
                var matchesSearch = !searchTerm ||
                    title.toString().toLowerCase().includes(searchTerm) ||
                    description.toString().toLowerCase().includes(searchTerm) ||
                    location.toString().toLowerCase().includes(searchTerm);

                // Date filter
                var matchesDate = true;
                if (dateFilter !== 'all' && dateStr) {
                    var cardDate = new Date(dateStr);
                    cardDate.setHours(0, 0, 0, 0);

                    switch (dateFilter) {
                        case 'this-week':
                            matchesDate = cardDate >= today && cardDate <= thisWeekEnd;
                            break;
                        case 'next-week':
                            matchesDate = cardDate >= nextWeekStart && cardDate <= nextWeekEnd;
                            break;
                        case 'this-month':
                            matchesDate = cardDate >= today && cardDate <= thisMonthEnd;
                            break;
                        case 'next-month':
                            matchesDate = cardDate >= nextMonthStart && cardDate <= nextMonthEnd;
                            break;
                    }
                }

                // Availability filter
                var matchesAvailability = true;
                if (availabilityFilter === 'available') {
                    matchesAvailability = !isFull && spotsRemaining > 0;
                } else if (availabilityFilter === 'full') {
                    matchesAvailability = isFull || spotsRemaining === 0;
                }

                // Show/hide card
                if (matchesSearch && matchesDate && matchesAvailability) {
                    $card.show();
                    visibleCards.push($card);
                } else {
                    $card.hide();
                }
            });

            // Sort visible cards
            visibleCards.sort(function(a, b) {
                var aTitle = $(a).data('title') || '';
                var bTitle = $(b).data('title') || '';
                var aDate = $(a).data('date') || '';
                var bDate = $(b).data('date') || '';
                var aSpots = parseInt($(a).data('spots-remaining')) || 0;
                var bSpots = parseInt($(b).data('spots-remaining')) || 0;

                switch (sortBy) {
                    case 'date-asc':
                        return aDate.toString().localeCompare(bDate.toString()) || aTitle.toString().localeCompare(bTitle.toString());
                    case 'date-desc':
                        return bDate.toString().localeCompare(aDate.toString()) || aTitle.toString().localeCompare(bTitle.toString());
                    case 'title-asc':
                        return aTitle.toString().localeCompare(bTitle.toString());
                    case 'title-desc':
                        return bTitle.toString().localeCompare(aTitle.toString());
                    case 'spots':
                        return bSpots - aSpots || aDate.toString().localeCompare(bDate.toString());
                    default:
                        return 0;
                }
            });

            // Reorder DOM
            var $container = $('.opportunities-grid');
            visibleCards.forEach(function($card) {
                $container.append($card);
            });

            // Update counter
            $visibleCount.text(visibleCards.length);
        }

        // Event listeners
        $searchInput.on('input', filterAndSort);
        $dateFilter.on('change', filterAndSort);
        $availabilityFilter.on('change', filterAndSort);
        $sortBy.on('change', filterAndSort);

        // Reset button
        $resetBtn.on('click', function() {
            $searchInput.val('');
            $dateFilter.val('all');
            $availabilityFilter.val('all');
            $sortBy.val('date-asc');

            // Restore original order
            var $container = $('.opportunities-grid');
            originalOrder.forEach(function($card) {
                $container.append($card);
                $card.show();
            });

            $visibleCount.text($cards.length);
        });
    });

    /**
     * Handle team/individual signup toggle
     */
    $(document).ready(function() {
        $('input[name^="signup_type_"]').on('change', function() {
            var oppId = this.name.replace('signup_type_', '');
            var signupType = this.value;
            var $teamSelector = $('#team_selector_' + oppId);

            if (signupType === 'team') {
                $teamSelector.slideDown(300);
            } else {
                $teamSelector.slideUp(300);
            }
        });
    });

    /**
     * History View - Search and Filter functionality
     */
    $(document).ready(function() {
        var $historyItems = $('.history-item.signup-item');
        var $searchInput = $('#history-search');
        var $dateFrom = $('#date-from');
        var $dateTo = $('#date-to');
        var $statusFilter = $('#status-filter');
        var $resetBtn = $('#reset-history-filters');
        var $visibleCount = $('#history-visible-count');

        // Exit if not on history page
        if ($historyItems.length === 0 && !$searchInput.length) {
            return;
        }

        function filterHistory() {
            var searchTerm = $searchInput.val().toLowerCase();
            var fromDate = $dateFrom.val();
            var toDate = $dateTo.val();
            var status = $statusFilter.val();

            var visibleItems = 0;

            $historyItems.each(function() {
                var $item = $(this);
                var title = $item.attr('data-title') || '';
                var location = $item.attr('data-location') || '';
                var itemDate = $item.attr('data-date');
                var itemStatus = $item.attr('data-status');

                // Search filter
                var matchesSearch = !searchTerm ||
                    title.toLowerCase().includes(searchTerm) ||
                    location.toLowerCase().includes(searchTerm);

                // Date range filter
                var matchesDateRange = true;
                if (fromDate && itemDate < fromDate) {
                    matchesDateRange = false;
                }
                if (toDate && itemDate > toDate) {
                    matchesDateRange = false;
                }

                // Status filter
                var matchesStatus = status === 'all' || itemStatus === status;

                // Show/hide item
                if (matchesSearch && matchesDateRange && matchesStatus) {
                    $item.show();
                    visibleItems++;
                } else {
                    $item.hide();
                }
            });

            $visibleCount.text(visibleItems);
        }

        // Event listeners
        if ($searchInput.length) $searchInput.on('input', filterHistory);
        if ($dateFrom.length) $dateFrom.on('change', filterHistory);
        if ($dateTo.length) $dateTo.on('change', filterHistory);
        if ($statusFilter.length) $statusFilter.on('change', filterHistory);

        if ($resetBtn.length) {
            $resetBtn.on('click', function() {
                $searchInput.val('');
                $dateFrom.val('');
                $dateTo.val('');
                $statusFilter.val('all');

                $historyItems.each(function() {
                    $(this).show();
                });

                $visibleCount.text($historyItems.length);
            });
        }
    });

    /**
     * History tabs functionality
     */
    $(document).ready(function() {
        var $tabs = $('.history-tab');
        var $tabContents = $('.history-tab-content');

        if ($tabs.length === 0) {
            return;
        }

        $tabs.on('click', function() {
            var targetTab = $(this).attr('data-tab');

            // Remove active class from all tabs and contents
            $tabs.removeClass('active');
            $tabContents.removeClass('active');

            // Add active class to clicked tab and corresponding content
            $(this).addClass('active');
            $('#tab-' + targetTab).addClass('active');
        });
    });

})(jQuery);

/**
 * Global signupForShift function for Browse Opportunities
 * Defined outside jQuery wrapper to be globally accessible from inline onclick handlers
 */
window.signupForShift = function(oppId, shiftId, oppTitle, shiftTime) {
    // Check if this opportunity supports teams and which option is selected
    var signupTypeRadio = document.querySelector('input[name="signup_type_' + oppId + '"]:checked');
    var isTeamSignup = signupTypeRadio && signupTypeRadio.value === 'team';

    if (isTeamSignup) {
        // Handle team signup
        var teamSelect = document.getElementById('team_select_' + oppId);
        if (!teamSelect || !teamSelect.value) {
            alert('Please select a team first');
            return;
        }

        var teamId = teamSelect.value;
        var teamOption = teamSelect.options[teamSelect.selectedIndex];
        var teamName = teamOption.text.split('(')[0].trim();
        var teamSize = teamOption.getAttribute('data-size');

        if (!confirm('Sign up ' + teamName + ' (' + teamSize + ' people) for ' + oppTitle + ' at ' + shiftTime + '?')) {
            return;
        }

        // Use team signup endpoint - get URLs from friendshyft_ajax object
        var ajaxUrl = (typeof friendshyft_ajax !== 'undefined') ? friendshyft_ajax.ajax_url : '/wp-admin/admin-ajax.php';
        var urlParams = new URLSearchParams(window.location.search);
        var token = urlParams.get('token') || '';

        window.location.href = ajaxUrl + '?action=fs_team_claim_shift&team_id=' + teamId +
            '&opportunity_id=' + oppId + '&shift_id=' + shiftId + '&team_size=' + teamSize +
            '&token=' + token;
        return;
    }

    // Handle individual signup
    if (!confirm('Sign up for ' + oppTitle + ' at ' + shiftTime + '?')) {
        return;
    }

    var button = document.querySelector('[data-shift-id="' + shiftId + '"]');
    if (button) {
        button.disabled = true;
        button.textContent = 'Signing up...';
    }

    // Get token from URL if present
    var urlParams = new URLSearchParams(window.location.search);
    var token = urlParams.get('token');

    var ajaxUrl, ajaxData, contentType;

    if (token) {
        // Token-based auth - use REST API
        ajaxUrl = (typeof friendshyft_ajax !== 'undefined' && friendshyft_ajax.rest_url)
            ? friendshyft_ajax.rest_url + 'friendshyft/v1/signup-opportunity'
            : '/wp-json/friendshyft/v1/signup-opportunity';
        ajaxData = JSON.stringify({
            opportunity_id: oppId,
            shift_id: shiftId,
            token: token
        });
        contentType = 'application/json';
    } else {
        // Logged-in user - use admin-ajax
        ajaxUrl = (typeof friendshyft_ajax !== 'undefined') ? friendshyft_ajax.ajax_url : '/wp-admin/admin-ajax.php';
        ajaxData = 'action=fs_signup_opportunity&opportunity_id=' + oppId + '&shift_id=' + shiftId;
        contentType = 'application/x-www-form-urlencoded; charset=UTF-8';
    }

    jQuery.ajax({
        url: ajaxUrl,
        type: 'POST',
        data: ajaxData,
        contentType: contentType,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Redirect with success message
                var newUrl = window.location.pathname + '?view=browse' +
                    (token ? '&token=' + token : '') +
                    '&success=1&message=' + encodeURIComponent('Successfully signed up!');
                window.location.href = newUrl;
            } else {
                alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Sign Up';
                }
            }
        },
        error: function() {
            alert('Something went wrong. Please try again.');
            if (button) {
                button.disabled = false;
                button.textContent = 'Sign Up';
            }
        }
    });
};
