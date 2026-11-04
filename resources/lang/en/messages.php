<?php

return [
    'form' => [
        'email' => 'Email address',
        'name' => 'Name (optional)',
        'submit' => 'Subscribe',
        'consent' => 'I would like to receive emails from :brand. I can unsubscribe at any time using the link in every email.',
    ],
    'signup' => [
        'title' => 'Almost done',
        'check_inbox' => 'If this address is not subscribed yet, we have sent you an email. Please click the link in it to confirm your subscription.',
        'done_title' => 'Thank you',
        'done' => 'Your subscription is active.',
    ],
    'confirm' => [
        'subject' => 'Please confirm your subscription: :audience',
        'intro' => 'Please confirm that you would like to receive “:audience”.',
        'button' => 'Confirm subscription',
        'ignore' => 'If you did not request this, you can ignore this email. You will not be subscribed.',
        'page_title' => 'Confirm your subscription',
        'page_intro' => 'Confirm that :email should receive “:audience”.',
        'done_title' => 'Subscription confirmed',
        'done' => 'Thank you. You will now receive “:audience”.',
        'expired_title' => 'Link expired',
        'expired' => 'This confirmation link is invalid or has expired. Please subscribe again.',
    ],
    'unsubscribe' => [
        'page_title' => 'Unsubscribe',
        'page_intro' => 'Stop sending “:audience” to :email?',
        'button' => 'Unsubscribe',
        'done_title' => 'You have been unsubscribed',
        'done' => 'You will no longer receive “:audience”.',
    ],
    'preferences' => [
        'page_title' => 'Email preferences',
        'intro' => 'Choose which emails :email should receive.',
        'save' => 'Save preferences',
        'unsubscribe_all' => 'Unsubscribe from everything',
        'saved' => 'Your preferences have been saved.',
        'none' => 'There are no emails you can manage here.',
    ],
    'invalid_link' => [
        'title' => 'Invalid link',
        'message' => 'This link is invalid. Please use the link from the most recent email.',
    ],
    'footer' => [
        'reason' => 'You receive this email because you subscribed to updates from :brand.',
        'unsubscribe' => 'Unsubscribe',
        'preferences' => 'Manage email preferences',
    ],
];
