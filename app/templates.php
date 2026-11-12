<?php
/** Ready-made form templates. Fields go through sanitize_fields() when a form is created, so they are always valid. */

function tpl_field(string $type, string $label, array $o = []): array {
    return ['type' => $type, 'label' => $label, 'name' => $o['name'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)), 'required' => !empty($o['req']),
        'width' => $o['w'] ?? 'full', 'placeholder' => $o['ph'] ?? '', 'options' => $o['opts'] ?? [], 'help' => $o['help'] ?? '', 'default' => $o['default'] ?? '',
        'currency' => $o['cur'] ?? '$', 'formula' => $o['formula'] ?? '', 'max' => $o['max'] ?? 5, 'logic' => $o['logic'] ?? null,
        'name_format' => $o['nfmt'] ?? 'first_last', 'address_format' => $o['afmt'] ?? 'street_city_state_zip', 'phone_format' => $o['pfmt'] ?? 'us'];
}
function tpl_logic(string $field, string $op, string $value, string $action = 'show'): array {
    return ['action' => $action, 'match' => 'all', 'rules' => [['field' => $field, 'op' => $op, 'value' => $value]]];
}

function form_templates(): array {
    $consent = tpl_field('consent', 'I agree to be contacted about my enquiry', ['name' => 'consent', 'req' => true]);
    return [
        'blank' => ['name' => 'Blank form', 'desc' => 'Start from scratch.', 'color' => 'slate', 'fields' => [], 'settings' => []],
        'contact' => ['name' => 'Contact us', 'desc' => 'Name, email, phone and a message.', 'color' => 'indigo',
            'fields' => [tpl_field('name', 'Name', ['req' => true]), tpl_field('email', 'Email', ['req' => true, 'w' => 'half']), tpl_field('tel', 'Phone', ['w' => 'half']),
                tpl_field('textarea', 'Message', ['req' => true, 'ph' => 'How can we help?']), $consent], 'settings' => ['success_message' => 'Thank you! We will get back to you shortly.']],
        'quote' => ['name' => 'Quote request', 'desc' => 'Service type with extra questions for commercial jobs.', 'color' => 'orange',
            'fields' => [tpl_field('name', 'Name', ['req' => true]), tpl_field('email', 'Email', ['req' => true, 'w' => 'half']), tpl_field('tel', 'Phone', ['req' => true, 'w' => 'half']),
                tpl_field('select', 'Service type', ['name' => 'service_type', 'req' => true, 'opts' => ['Residential', 'Commercial']]),
                tpl_field('text', 'Company name', ['name' => 'company', 'logic' => tpl_logic('service_type', 'is', 'Commercial')]),
                tpl_field('address', 'Property address'), tpl_field('textarea', 'Project details', ['req' => true]), tpl_field('file', 'Photos or plans (optional)', ['name' => 'attachment']), $consent],
            'settings' => ['submit_text' => 'Request my quote', 'success_message' => 'Thanks! Your quote request has been received.']],
        'appointment' => ['name' => 'Appointment booking', 'desc' => 'Date, time and contact details.', 'color' => 'green',
            'fields' => [tpl_field('name', 'Name', ['req' => true]), tpl_field('email', 'Email', ['req' => true, 'w' => 'half']), tpl_field('tel', 'Phone', ['req' => true, 'w' => 'half']),
                tpl_field('date', 'Preferred date', ['req' => true, 'w' => 'half']), tpl_field('time', 'Preferred time', ['req' => true, 'w' => 'half']),
                tpl_field('select', 'Service', ['opts' => ['Consultation', 'Inspection', 'Follow-up'], 'req' => true]), tpl_field('textarea', 'Anything we should know?', ['name' => 'notes'])],
            'settings' => ['submit_text' => 'Book appointment', 'success_message' => 'Your appointment request has been sent. We will confirm shortly.']],
        'newsletter' => ['name' => 'Newsletter signup', 'desc' => 'Short and simple.', 'color' => 'pink',
            'fields' => [tpl_field('name', 'Name', ['req' => true]), tpl_field('email', 'Email', ['req' => true]), tpl_field('checkbox', 'I am interested in', ['name' => 'interests', 'opts' => ['News', 'Offers', 'Tips']]),
                tpl_field('consent', 'I agree to receive emails', ['name' => 'consent', 'req' => true])], 'settings' => ['submit_text' => 'Subscribe', 'success_message' => 'You are subscribed. Thank you!']],
        'feedback' => ['name' => 'Customer feedback', 'desc' => 'Star rating with a follow-up for low scores.', 'color' => 'amber',
            'fields' => [tpl_field('name', 'Name', ['w' => 'half']), tpl_field('email', 'Email', ['w' => 'half']), tpl_field('rating', 'How was your experience?', ['name' => 'rating', 'req' => true]),
                tpl_field('textarea', 'What could we do better?', ['name' => 'improve', 'logic' => tpl_logic('rating', 'lt', '4')]),
                tpl_field('textarea', 'What did you like most?', ['name' => 'liked', 'logic' => tpl_logic('rating', 'gt', '3')])],
            'settings' => ['submit_text' => 'Send feedback', 'success_message' => 'Thank you for your feedback!']],
        'event' => ['name' => 'Event registration', 'desc' => 'Multi-step with ticket total.', 'color' => 'violet',
            'fields' => [tpl_field('pagebreak', 'Your details'), tpl_field('name', 'Name', ['req' => true]), tpl_field('email', 'Email', ['req' => true]), tpl_field('tel', 'Phone'),
                tpl_field('pagebreak', 'Tickets'), tpl_field('number', 'Number of tickets', ['name' => 'tickets', 'req' => true, 'default' => '1']),
                tpl_field('price', 'Price per ticket', ['name' => 'ticket_price', 'default' => '25']), tpl_field('calculated', 'Total', ['name' => 'total', 'formula' => '{tickets} * {ticket_price}']),
                tpl_field('pagebreak', 'Confirm'), tpl_field('checkbox', 'Dietary needs', ['name' => 'diet', 'opts' => ['Vegetarian', 'Vegan', 'Gluten-free']]), tpl_field('signature', 'Signature', ['req' => true])],
            'settings' => ['submit_text' => 'Register', 'success_message' => 'You are registered! A confirmation email is on its way.']],
        'job' => ['name' => 'Job application', 'desc' => 'Application with resume upload and steps.', 'color' => 'teal',
            'fields' => [tpl_field('pagebreak', 'About you'), tpl_field('name', 'Full name', ['req' => true]), tpl_field('email', 'Email', ['req' => true, 'w' => 'half']), tpl_field('tel', 'Phone', ['w' => 'half']), tpl_field('address', 'Address'),
                tpl_field('pagebreak', 'Experience'), tpl_field('select', 'Position', ['opts' => ['Sales', 'Support', 'Developer', 'Other'], 'req' => true]), tpl_field('textarea', 'Tell us about yourself', ['name' => 'about']),
                tpl_field('file', 'Resume (PDF or DOC)', ['name' => 'resume', 'req' => true])],
            'settings' => ['submit_text' => 'Submit application', 'save_resume' => 1, 'success_message' => 'Thanks for applying! We will review your application.']],
    ];
}

/** Admin notification used for every new form. */
function default_notification_body(): string {
    return '<p>You have a new submission on <strong>{form_name}</strong> (Entry #{entry_id}).</p>{entry_details}';
}
