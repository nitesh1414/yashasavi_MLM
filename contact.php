<?php
/** Contact page with enquiry form */
require_once __DIR__ . '/includes/init.php';

if (is_post()) {
    verify_csrf();
    $name = post_str('name');
    $email = post_str('email');
    $mobile = post_str('mobile');
    $subject = post_str('subject');
    $message = post_str('message');
    $errors = [];
    if (strlen($name) < 3) { $errors[] = 'Please enter your name.'; }
    if ($email !== '' && !is_email($email)) { $errors[] = 'Please enter a valid email.'; }
    if ($mobile !== '' && !preg_match('/^[0-9+\- ]{8,15}$/', $mobile)) { $errors[] = 'Please enter a valid mobile number.'; }
    if (strlen($message) < 10) { $errors[] = 'Message should be at least 10 characters.'; }
    if ($errors) {
        flash('error', implode(' ', $errors));
    } else {
        q("INSERT INTO enquiries (name, email, mobile, subject, message, status, ip, created_at)
           VALUES (?, ?, ?, ?, ?, 'new', ?, ?)",
          [$name, $email ?: null, $mobile ?: null, $subject ?: 'Website Enquiry', $message, client_ip(), now()]);
        flash('success', 'Thank you! Your enquiry has been received. Our team will contact you soon.');
        redirect('contact.php');
    }
}

$pageTitle = 'Contact Us';
require __DIR__ . '/includes/site_header.php';
?>
<section class="page-hero">
    <h1>Contact Us</h1>
    <div class="crumbs"><a href="<?= url('index.php') ?>">Home</a> / Contact Us</div>
</section>

<section class="section">
    <div class="container">
        <div class="contact-grid">
            <div class="contact-info-card">
                <h3>Get in Touch</h3>
                <div class="ci"><span class="ci-ico">📍</span><div><?= e(setting('contact_address')) ?></div></div>
                <div class="ci"><span class="ci-ico">📞</span><div><?= e(setting('contact_phone')) ?></div></div>
                <div class="ci"><span class="ci-ico">📧</span><div><a href="mailto:<?= e(setting('contact_email')) ?>" style="color:#fff"><?= e(setting('contact_email')) ?></a></div></div>
                <div class="ci"><span class="ci-ico">🕘</span><div>Mon – Sat, 10:00 AM – 6:00 PM</div></div>
            </div>
            <div class="form-card">
                <h2 style="margin-bottom:6px">Send us a Message</h2>
                <p style="color:var(--ink-soft);font-size:14px;margin-bottom:18px">Have a question about products or the business opportunity? Write to us.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Your Name <span class="req">*</span></label>
                            <input class="form-control" type="text" name="name" required value="<?= e(post_str('name')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Mobile No.</label>
                            <input class="form-control" type="text" name="mobile" value="<?= e(post_str('mobile')) ?>">
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Email</label>
                            <input class="form-control" type="email" name="email" value="<?= e(post_str('email')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <input class="form-control" type="text" name="subject" value="<?= e(post_str('subject')) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Message <span class="req">*</span></label>
                        <textarea class="form-control" name="message" required><?= e(post_str('message')) ?></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Send Message</button>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
