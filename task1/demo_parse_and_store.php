<?php
/**
 * Demo/fixture: parse a sample multipart MIME message without IMAP.
 * Useful when IMAP credentials are not yet configured.
 *
 *   php task1/demo_parse_and_store.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/lib/MailParser.php';

$sample = <<<'EML'
Message-ID: <demo-task1-001@example.com>
Date: Mon, 13 Jul 2026 10:00:00 +0000
From: =?UTF-8?B?Sm9zZSBHw7NtZXo=?= <jose@example.com>
Subject: =?UTF-8?Q?Pedido_n=C3=BAmero_42?=
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="BOUND123"

--BOUND123
Content-Type: multipart/alternative; boundary="ALT456"

--ALT456
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

Hola, este es un mensaje de prueba con acentos: caf=C3=A9.
--ALT456
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<p>Hola, este es un mensaje de prueba con acentos: caf=C3=A9.</p>
--ALT456--
--BOUND123
Content-Type: application/pdf; name="factura.pdf"
Content-Disposition: attachment; filename="factura.pdf"
Content-Transfer-Encoding: base64

JVBERi0xLjAKJcKlwrHDpcKxCg==
--BOUND123--
EML;

$mail = MailParser::parse($sample);
$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'INSERT IGNORE INTO emails (message_id, from_address, subject, email_date, body_preview, attachments)
     VALUES (:message_id, :from_address, :subject, :email_date, :body_preview, :attachments)'
);
$stmt->execute([
    ':message_id'   => $mail['message_id'],
    ':from_address' => $mail['from'],
    ':subject'      => $mail['subject'],
    ':email_date'   => $mail['date'],
    ':body_preview' => $mail['preview'],
    ':attachments'  => json_encode($mail['attachments'], JSON_UNESCAPED_UNICODE),
]);

echo "Parsed demo message:\n";
echo json_encode($mail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo $stmt->rowCount() ? "Inserted into emails.\n" : "Already stored (duplicate message-id).\n";