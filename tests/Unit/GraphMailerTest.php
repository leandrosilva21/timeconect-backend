<?php

namespace Tests\Unit;

use App\Services\GraphMailer;
use Tests\TestCase;

/**
 * Testa o GraphMailer SEM tocar a rede:
 *  - enabled() reage só à config (dormente por padrão);
 *  - buildMessage() monta o payload sendMail correto (recipients, anexos base64, contentType).
 */
class GraphMailerTest extends TestCase
{
    public function test_enabled_is_false_when_config_is_empty(): void
    {
        config(['services.graph' => ['tenant_id' => null, 'client_id' => null, 'client_secret' => null]]);

        $this->assertFalse(GraphMailer::enabled(), 'enabled() deve ser false sem credenciais (dormente por padrão).');
    }

    public function test_enabled_is_false_when_partially_configured(): void
    {
        config(['services.graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => null]]);

        $this->assertFalse(GraphMailer::enabled(), 'enabled() deve ser false se faltar qualquer uma das 3 credenciais.');
    }

    public function test_enabled_is_true_when_fully_configured(): void
    {
        config(['services.graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']]);

        $this->assertTrue(GraphMailer::enabled(), 'enabled() deve ser true com as 3 credenciais preenchidas.');
    }

    public function test_build_message_shapes_recipients_subject_and_body(): void
    {
        $msg = GraphMailer::buildMessage(
            'Assunto X',
            '<p>Olá</p>',
            ['a@x.com', '', '  b@x.com  '], // filtra vazio + trim
            ['cc@x.com'],
            []
        );

        $this->assertSame('Assunto X', $msg['subject']);
        $this->assertSame('HTML', $msg['body']['contentType']);
        $this->assertSame('<p>Olá</p>', $msg['body']['content']);

        $this->assertSame([
            ['emailAddress' => ['address' => 'a@x.com']],
            ['emailAddress' => ['address' => 'b@x.com']],
        ], $msg['toRecipients']);

        $this->assertSame([
            ['emailAddress' => ['address' => 'cc@x.com']],
        ], $msg['ccRecipients']);

        // Sem anexos: a chave 'attachments' não deve existir.
        $this->assertArrayNotHasKey('attachments', $msg);
    }

    public function test_build_message_encodes_attachment_and_infers_content_type(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gm_') . '.pdf';
        $bytes = '%PDF-1.4 fake content';
        file_put_contents($tmp, $bytes);

        try {
            $msg = GraphMailer::buildMessage('S', '<b>b</b>', ['to@x.com'], [], [$tmp]);

            $this->assertArrayHasKey('attachments', $msg);
            $this->assertCount(1, $msg['attachments']);

            $att = $msg['attachments'][0];
            $this->assertSame('#microsoft.graph.fileAttachment', $att['@odata.type']);
            $this->assertSame(basename($tmp), $att['name']);
            $this->assertSame('application/pdf', $att['contentType']);
            $this->assertSame(base64_encode($bytes), $att['contentBytes']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_build_message_infers_xlsx_content_type(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gm_') . '.xlsx';
        file_put_contents($tmp, 'PK fake xlsx');

        try {
            $msg = GraphMailer::buildMessage('S', 'b', ['to@x.com'], [], [$tmp]);
            $this->assertSame(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $msg['attachments'][0]['contentType']
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function test_build_message_throws_when_attachments_exceed_limit(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gm_') . '.bin';
        file_put_contents($tmp, str_repeat('x', 3 * 1024 * 1024 + 1)); // > ~3 MB

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/upload session/i');
            GraphMailer::buildMessage('S', 'b', ['to@x.com'], [], [$tmp]);
        } finally {
            @unlink($tmp);
        }
    }
}
