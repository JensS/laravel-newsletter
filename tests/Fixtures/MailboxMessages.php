<?php

namespace JensS\Newsletter\Tests\Fixtures;

/** Realistic raw messages as they arrive in a bounce mailbox. */
final class MailboxMessages
{
    public static function postfixHardBounce(string $recipient, string $deliveryUuid, string $id = 'bounce-1'): string
    {
        return <<<MAIL
Return-Path: <>
Date: Tue,  3 Nov 2026 10:00:00 +0100 (CET)
From: MAILER-DAEMON@mx2f80.netcup.net (Mail Delivery System)
Subject: Undelivered Mail Returned to Sender
To: newsletter@example.org
Auto-Submitted: auto-replied
Message-Id: <{$id}@mx2f80.netcup.net>
MIME-Version: 1.0
Content-Type: multipart/report; report-type=delivery-status;
	boundary="ABC123/mx2f80.netcup.net"

This is a MIME-encapsulated message.

--ABC123/mx2f80.netcup.net
Content-Description: Notification
Content-Type: text/plain; charset=us-ascii

This is the mail system at host mx2f80.netcup.net.

I'm sorry to have to inform you that your message could not
be delivered to one or more recipients.

<{$recipient}>: host gmail-smtp-in.l.google.com[142.250.1.27] said: 550-5.1.1
    The email account that you tried to reach does not exist. (in reply to
    RCPT TO command)

--ABC123/mx2f80.netcup.net
Content-Description: Delivery report
Content-Type: message/delivery-status

Reporting-MTA: dns; mx2f80.netcup.net
X-Postfix-Queue-ID: 4ABC
Arrival-Date: Tue,  3 Nov 2026 09:59:58 +0100 (CET)

Final-Recipient: rfc822; {$recipient}
Original-Recipient: rfc822;{$recipient}
Action: failed
Status: 5.1.1
Remote-MTA: dns; gmail-smtp-in.l.google.com
Diagnostic-Code: smtp; 550-5.1.1 The email account that you tried to reach does
    not exist.

--ABC123/mx2f80.netcup.net
Content-Description: Undelivered Message Headers
Content-Type: text/rfc822-headers

From: Example Club <newsletter@example.org>
To: {$recipient}
Subject: Hello
Message-ID: <{$deliveryUuid}@example.org>
X-Newsletter-Delivery: {$deliveryUuid}

--ABC123/mx2f80.netcup.net--
MAIL;
    }

    public static function delayed(string $recipient, string $deliveryUuid): string
    {
        return <<<MAIL
From: MAILER-DAEMON@mx2f80.netcup.net
Subject: Delayed Mail (still being retried)
Message-Id: <delayed-1@mx2f80.netcup.net>
Content-Type: multipart/report; report-type=delivery-status; boundary="D1"

--D1
Content-Type: text/plain

Your message is delayed.

--D1
Content-Type: message/delivery-status

Reporting-MTA: dns; mx2f80.netcup.net

Final-Recipient: rfc822; {$recipient}
Action: delayed
Status: 4.4.1
Diagnostic-Code: X-Postfix; connect to mx.example.net: Connection timed out

--D1
Content-Type: message/rfc822

From: newsletter@example.org
To: {$recipient}
Message-ID: <{$deliveryUuid}@example.org>
Subject: Hello

Body
--D1--
MAIL;
    }

    public static function policyRejection(string $recipient): string
    {
        return <<<MAIL
From: Mail Delivery System <MAILER-DAEMON@mx2f80.netcup.net>
Subject: Undelivered Mail Returned to Sender
Message-Id: <policy-1@mx2f80.netcup.net>
Content-Type: multipart/report; report-type=delivery-status; boundary="P1"

--P1
Content-Type: message/delivery-status

Reporting-MTA: dns; mx2f80.netcup.net

Final-Recipient: rfc822; {$recipient}
Action: failed
Status: 5.7.26
Diagnostic-Code: smtp; 550-5.7.26 Unauthenticated email from example.org is not
    accepted due to domain's DMARC policy.

--P1--
MAIL;
    }

    public static function qmailBounce(string $recipient, string $deliveryUuid): string
    {
        return <<<MAIL
From: MAILER-DAEMON@mail.old-provider.example
Subject: failure notice
Message-Id: <qmail-1@old-provider.example>
Content-Type: text/plain

Hi. This is the qmail-send program at mail.old-provider.example.
I'm afraid I wasn't able to deliver your message to the following addresses.
This is a permanent error; I've given up. Sorry it didn't work out.

<{$recipient}>:
192.0.2.1 does not like recipient.
Remote host said: 550 5.1.1 <{$recipient}>... User unknown
Giving up on 192.0.2.1.

--- Below this line is a copy of the message.

Return-Path: <newsletter@example.org>
X-Newsletter-Delivery: {$deliveryUuid}
Subject: Hello
MAIL;
    }

    public static function base64Bounce(string $recipient): string
    {
        $human = base64_encode("Die Nachricht konnte nicht zugestellt werden.\n\n{$recipient}\n550 5.2.1 Mailbox disabled\n");

        return <<<MAIL
From: Mail Delivery Subsystem <mailer-daemon@exim.example>
Subject: =?UTF-8?Q?Unzustellbar=3A_Hello?=
Message-Id: <exim-1@exim.example>
Content-Type: multipart/mixed; boundary="E1"

--E1
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

{$human}
--E1--
MAIL;
    }

    public static function complaint(string $recipient, string $deliveryUuid): string
    {
        return <<<MAIL
From: staff@hotmail.com
To: newsletter@example.org
Subject: complaint about message from 192.0.2.10
Message-ID: <arf-1@hotmail.com>
MIME-Version: 1.0
Content-Type: multipart/report; report-type=feedback-report;
	boundary="ARF"

--ARF
Content-Type: text/plain; charset="US-ASCII"

This is an email abuse report for an email message received from IP 192.0.2.10.

--ARF
Content-Type: message/feedback-report

Feedback-Type: abuse
User-Agent: SomeGenerator/1.0
Version: 1
Original-Mail-From: <newsletter@example.org>
Original-Rcpt-To: <{$recipient}>
Arrival-Date: Thu, 8 Mar 2026 14:00:00 EDT

--ARF
Content-Type: message/rfc822

From: <newsletter@example.org>
To: {$recipient}
Subject: Hello
Message-ID: <{$deliveryUuid}@example.org>
X-Newsletter-Delivery: {$deliveryUuid}

Hi
--ARF--
MAIL;
    }

    public static function autoReply(): string
    {
        return <<<'MAIL'
From: Anna <anna@example.net>
Subject: Abwesenheitsnotiz: Hello
Auto-Submitted: auto-replied
Message-ID: <ooo-1@example.net>
Content-Type: text/plain

Ich bin bis Montag nicht im Büro.
MAIL;
    }

    public static function unsubscribe(string $token, string $from = 'reader@example.net'): string
    {
        return <<<MAIL
From: {$from}
To: unsubscribe@example.org
Subject: unsubscribe {$token}
Message-ID: <unsub-1@example.net>
Content-Type: text/plain

MAIL;
    }

    public static function human(): string
    {
        return <<<'MAIL'
From: Ben <ben@example.net>
Subject: Re: Hello
Message-ID: <human-1@example.net>
Content-Type: text/plain

Thanks for the newsletter, see you on Friday!
MAIL;
    }
}
