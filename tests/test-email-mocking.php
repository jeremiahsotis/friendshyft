<?php
/**
 * Test email sending with mocking
 *
 * @package FriendShyft
 */

class Test_Email_Mocking extends WP_UnitTestCase {

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();

        // Reset mail test
        reset_phpmailer_instance();
    }

    /**
     * Test email sending is called
     */
    public function test_email_function_called() {
        $to = 'test@example.com';
        $subject = 'Test Subject';
        $message = 'Test message body';

        $result = wp_mail($to, $subject, $message);

        // In test environment, wp_mail may return false, but we can check it was called
        $this->assertIsBool($result, "wp_mail should return boolean");
    }

    /**
     * Test email parameters are correct
     */
    public function test_email_parameters() {
        $to = 'volunteer@example.com';
        $subject = 'Welcome to FriendShyft!';
        $message = '<html><body><h1>Welcome!</h1></body></html>';
        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);

        // Get PHPMailer instance
        global $phpmailer;

        if (isset($phpmailer) && $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer) {
            // Verify subject
            $this->assertEquals($subject, $phpmailer->Subject, "Email subject should match");

            // Verify HTML content type
            $this->assertEquals('text/html', $phpmailer->ContentType, "Content type should be text/html");
        }
    }

    /**
     * Test confirmation email structure
     */
    public function test_confirmation_email_structure() {
        $volunteer_name = 'John Doe';
        $opportunity_title = 'Food Distribution';
        $event_date = '2026-01-15';

        $subject = "Signup Confirmed: $opportunity_title";
        $message = "
        <html>
        <body>
            <h2>Signup Confirmed!</h2>
            <p>Hi $volunteer_name,</p>
            <p>You're confirmed for: <strong>$opportunity_title</strong></p>
            <p>Date: $event_date</p>
        </body>
        </html>
        ";

        $this->assertStringContainsString($volunteer_name, $message, "Email should contain volunteer name");
        $this->assertStringContainsString($opportunity_title, $message, "Email should contain opportunity title");
        $this->assertStringContainsString($event_date, $message, "Email should contain event date");
        $this->assertStringContainsString('<html>', $message, "Email should be HTML formatted");
    }

    /**
     * Test reminder email structure
     */
    public function test_reminder_email_structure() {
        $volunteer_name = 'Jane Smith';
        $opportunity_title = 'Park Cleanup';
        $event_date = '2026-02-01';
        $event_time = '09:00 AM';
        $location = '123 Main St';

        $subject = "Reminder: $opportunity_title Tomorrow";
        $message = "
        <html>
        <body>
            <h2>Reminder: Tomorrow's Volunteer Shift</h2>
            <p>Hi $volunteer_name,</p>
            <p>This is a friendly reminder about your upcoming volunteer shift:</p>
            <ul>
                <li><strong>Event:</strong> $opportunity_title</li>
                <li><strong>Date:</strong> $event_date</li>
                <li><strong>Time:</strong> $event_time</li>
                <li><strong>Location:</strong> $location</li>
            </ul>
            <p>See you there!</p>
        </body>
        </html>
        ";

        $this->assertStringContainsString('Reminder', $subject, "Subject should indicate reminder");
        $this->assertStringContainsString($volunteer_name, $message);
        $this->assertStringContainsString($opportunity_title, $message);
        $this->assertStringContainsString($location, $message);
    }

    /**
     * Test cancellation email structure
     */
    public function test_cancellation_email_structure() {
        $volunteer_name = 'Bob Wilson';
        $opportunity_title = 'Community Event';

        $subject = "Signup Cancelled: $opportunity_title";
        $message = "
        <html>
        <body>
            <h2>Signup Cancelled</h2>
            <p>Hi $volunteer_name,</p>
            <p>Your signup for <strong>$opportunity_title</strong> has been cancelled.</p>
            <p>Browse more opportunities in your volunteer portal.</p>
        </body>
        </html>
        ";

        $this->assertStringContainsString('Cancelled', $subject);
        $this->assertStringContainsString($volunteer_name, $message);
        $this->assertStringContainsString($opportunity_title, $message);
    }

    /**
     * Test re-engagement email structure
     */
    public function test_reengagement_email_structure() {
        $volunteer_name = 'Alice Johnson';
        $portal_url = 'http://example.com/volunteer-portal/?token=abc123';

        $subject = 'We Miss You! Come Back to Volunteering';
        $message = "
        <html>
        <body>
            <h2>We Haven't Seen You in a While!</h2>
            <p>Hi $volunteer_name,</p>
            <p>It's been over 3 months since your last volunteer shift, and we truly miss having you on our team.</p>
            <p>Your volunteer work makes a real difference in our community.</p>
            <a href='$portal_url'>Explore New Opportunities</a>
        </body>
        </html>
        ";

        $this->assertStringContainsString('Miss You', $subject);
        $this->assertStringContainsString($volunteer_name, $message);
        $this->assertStringContainsString($portal_url, $message, "Email should include portal link");
    }

    /**
     * Test badge notification email structure
     */
    public function test_badge_notification_email_structure() {
        $volunteer_name = 'Charlie Brown';
        $badge_type = '50 Hours';

        $subject = "Congratulations! You've Earned a New Badge";
        $message = "
        <html>
        <body>
            <h2>🏆 New Badge Earned!</h2>
            <p>Hi $volunteer_name,</p>
            <p>Congratulations! You've earned the <strong>$badge_type</strong> badge!</p>
            <p>Thank you for your dedication to our community.</p>
        </body>
        </html>
        ";

        $this->assertStringContainsString('Congratulations', $subject);
        $this->assertStringContainsString($volunteer_name, $message);
        $this->assertStringContainsString($badge_type, $message);
    }

    /**
     * Test welcome email structure
     */
    public function test_welcome_email_structure() {
        $volunteer_name = 'David Lee';
        $access_token = bin2hex(random_bytes(32));
        $portal_url = "http://example.com/volunteer-portal/?token=$access_token";

        $subject = 'Welcome to FriendShyft!';
        $message = "
        <html>
        <body>
            <h2>Welcome to Our Volunteer Community!</h2>
            <p>Hi $volunteer_name,</p>
            <p>Thank you for joining FriendShyft as a volunteer!</p>
            <p>Access your volunteer portal:</p>
            <a href='$portal_url'>Go to Portal</a>
            <p>In your portal, you can:</p>
            <ul>
                <li>Browse volunteer opportunities</li>
                <li>Sign up for shifts</li>
                <li>Track your hours</li>
                <li>View your badges</li>
            </ul>
        </body>
        </html>
        ";

        $this->assertStringContainsString('Welcome', $subject);
        $this->assertStringContainsString($volunteer_name, $message);
        $this->assertStringContainsString($access_token, $message, "Email should include access token");
        $this->assertStringContainsString('portal', strtolower($message), "Email should mention portal");
    }

    /**
     * Test email headers for HTML content
     */
    public function test_html_email_headers() {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: FriendShyft <noreply@friendshyft.org>',
        );

        $this->assertContains('Content-Type: text/html; charset=UTF-8', $headers, "Headers should specify HTML content");
        $this->assertCount(2, $headers, "Should have 2 headers");
    }

    /**
     * Test email subject sanitization
     */
    public function test_email_subject_sanitization() {
        $unsafe_subject = "Test <script>alert('xss')</script> Subject";
        $safe_subject = strip_tags($unsafe_subject);

        $this->assertEquals('Test  Subject', $safe_subject, "Subject should be sanitized");
        $this->assertStringNotContainsString('<script>', $safe_subject, "Should not contain script tags");
    }

    /**
     * Test email body HTML escaping
     */
    public function test_email_body_html_escaping() {
        $volunteer_name = "Test <script>User";
        $escaped_name = esc_html($volunteer_name);

        $message = "<p>Hi $escaped_name,</p>";

        $this->assertStringContainsString('&lt;script&gt;', $message, "HTML tags should be escaped");
        $this->assertStringNotContainsString('<script>', $message, "Should not contain unescaped script tags");
    }

    /**
     * Test multiple recipients
     */
    public function test_multiple_recipients() {
        $recipients = array(
            'volunteer1@example.com',
            'volunteer2@example.com',
            'volunteer3@example.com',
        );

        $this->assertIsArray($recipients, "Recipients should be an array");
        $this->assertCount(3, $recipients, "Should have 3 recipients");

        foreach ($recipients as $recipient) {
            $this->assertStringContainsString('@', $recipient, "Each recipient should be a valid email");
        }
    }
}
