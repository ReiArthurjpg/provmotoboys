<?php

declare(strict_types=1);

namespace App\Service;

use Throwable;

final class WelcomeMailerService
{
    public function __construct(
        private readonly string $loginUrl = 'https://logmovy.kodyvera.com/login',
        private readonly string $smtpHost = '',
        private readonly int $smtpPort = 587,
        private readonly string $smtpUser = '',
        private readonly string $smtpPass = '',
        private readonly string $fromEmail = 'operacao@logmovy.com.br',
        private readonly string $fromName = 'LogMovy Operações'
    ) {
    }

    /**
     * Gera o pacote completo de boas-vindas (HTML, Texto WhatsApp/E-mail e disparo SMTP/mail).
     *
     * @param array{
     *   name: string,
     *   email: string,
     *   phone: string,
     *   protocol: string,
     *   plate: string,
     *   password: string,
     *   loginUrl?: string
     * } $data
     * @return array{
     *   emailSent: bool,
     *   deliveryMethod: string,
     *   recipientName: string,
     *   recipientEmail: string,
     *   recipientPhone: string,
     *   protocol: string,
     *   loginUrl: string,
     *   accessPassword: string,
     *   subject: string,
     *   textTemplate: string,
     *   htmlTemplate: string
     * }
     */
    public function sendWelcomeCredentials(array $data): array
    {
        $name = trim($data['name']);
        $firstName = explode(' ', $name)[0] ?: $name;
        $email = trim($data['email']);
        $phone = trim($data['phone']);
        $protocol = trim($data['protocol']);
        $plate = trim($data['plate']);
        $password = $data['password'];
        $loginUrl = !empty($data['loginUrl']) ? trim((string) $data['loginUrl']) : $this->loginUrl;

        $subject = sprintf('Bem-vindo à Frota LogMovy, %s! Seu cadastro foi APROVADO (#%s)', $firstName, $protocol);
        $textTemplate = $this->buildTextTemplate($name, $email, $password, $loginUrl, $protocol, $plate);
        $htmlTemplate = $this->buildHtmlTemplate($name, $email, $password, $loginUrl, $protocol, $plate);

        $emailSent = false;
        $deliveryMethod = 'template_ready';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($this->smtpHost !== '' && $this->smtpUser !== '' && $this->smtpPass !== '') {
                $emailSent = $this->sendViaSmtp($email, $name, $subject, $htmlTemplate);
                $deliveryMethod = $emailSent ? 'smtp' : 'smtp_fallback';
            }

            if (!$emailSent) {
                $emailSent = $this->sendViaPhpMail($email, $subject, $htmlTemplate);
                if ($emailSent) {
                    $deliveryMethod = 'php_mail';
                }
            }
        }

        return [
            'emailSent' => $emailSent,
            'deliveryMethod' => $deliveryMethod,
            'recipientName' => $name,
            'recipientEmail' => $email,
            'recipientPhone' => $phone,
            'protocol' => $protocol,
            'loginUrl' => $loginUrl,
            'accessPassword' => $password,
            'subject' => $subject,
            'textTemplate' => $textTemplate,
            'htmlTemplate' => $htmlTemplate,
        ];
    }

    private function buildTextTemplate(
        string $name,
        string $email,
        string $password,
        string $loginUrl,
        string $protocol,
        string $plate
    ): string {
        return implode("\n", [
            "🎉 *BEM-VINDO À FROTA LOGMOVY!*",
            "",
            "Olá, *{$name}*! Seu credenciamento (Protocolo *#{$protocol}* • Placa *{$plate}*) foi *HOMOLOGADO COM SUCESSO* pela nossa central de operações.",
            "",
            "Abaixo estão suas credenciais oficiais para acessar o painel e acompanhar suas escalas:",
            "",
            "👤 *Nome:* {$name}",
            "📧 *E-mail (Login):* {$email}",
            "🔑 *Senha de Acesso:* {$password}",
            "🔗 *URL de Login:* {$loginUrl}",
            "",
            "Acesse o link acima com seu e-mail e senha para visualizar seus turnos e confirmar presença nas escalas.",
            "",
            "— *Equipe de Operações LogMovy*",
        ]);
    }

    private function buildHtmlTemplate(
        string $name,
        string $email,
        string $password,
        string $loginUrl,
        string $protocol,
        string $plate
    ): string {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safePass = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $safeProtocol = htmlspecialchars($protocol, ENT_QUOTES, 'UTF-8');
        $safePlate = htmlspecialchars($plate, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bem-vindo à LogMovy</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background-color:#FFFFFF;border-radius:24px;overflow:hidden;border:1px solid #E2E8F0;box-shadow:0 12px 32px rgba(15,23,42,0.08);">
          <!-- Header Escuro LogMovy -->
          <tr>
            <td style="background-color:#0B1120;padding:32px 36px;border-bottom:3px solid #A3E635;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <span style="display:inline-block;padding:4px 12px;border-radius:999px;background-color:rgba(163,230,53,0.15);border:1px solid rgba(163,230,53,0.4);color:#A3E635;font-size:11px;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;">
                      Cadastro Homologado • #{$safeProtocol}
                    </span>
                    <h1 style="margin:14px 0 4px 0;color:#FFFFFF;font-size:24px;font-weight:900;letter-spacing:-0.02em;">
                      Bem-vindo à Frota LogMovy!
                    </h1>
                    <p style="margin:0;color:#94A3B8;font-size:13px;">
                      Suas credenciais de acesso ao sistema já estão liberadas.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Corpo -->
          <tr>
            <td style="padding:32px 36px;">
              <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#334155;">
                Olá, <strong style="color:#0F172A;">{$safeName}</strong>!
              </p>
              <p style="margin:0 0 24px 0;font-size:14px;line-height:1.6;color:#475569;">
                Temos o prazer de informar que sua documentação e o veículo de placa <strong style="color:#0F172A;font-family:monospace;">{$safePlate}</strong> foram <strong>aprovados e homologados</strong> pela nossa equipe operacional.
              </p>

              <!-- Caixa de Credenciais de Acesso -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:16px;padding:20px 24px;margin-bottom:26px;">
                <tr>
                  <td colspan="2" style="padding-bottom:12px;border-bottom:1px solid #E2E8F0;">
                    <span style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#64748B;">
                      Suas Credenciais de Acesso ao Sistema
                    </span>
                  </td>
                </tr>
                <tr>
                  <td style="padding-top:14px;font-size:12px;color:#64748B;font-weight:600;width:130px;">Nome:</td>
                  <td style="padding-top:14px;font-size:13px;color:#0F172A;font-weight:700;">{$safeName}</td>
                </tr>
                <tr>
                  <td style="padding-top:10px;font-size:12px;color:#64748B;font-weight:600;">E-mail (Login):</td>
                  <td style="padding-top:10px;font-size:13px;color:#0F172A;font-weight:700;font-family:monospace;">{$safeEmail}</td>
                </tr>
                <tr>
                  <td style="padding-top:10px;font-size:12px;color:#64748B;font-weight:600;">Senha de Acesso:</td>
                  <td style="padding-top:10px;">
                    <span style="display:inline-block;padding:4px 10px;background-color:#0B1120;color:#A3E635;border-radius:8px;font-family:monospace;font-size:14px;font-weight:800;letter-spacing:0.04em;">
                      {$safePass}
                    </span>
                  </td>
                </tr>
                <tr>
                  <td style="padding-top:10px;font-size:12px;color:#64748B;font-weight:600;">URL de Login:</td>
                  <td style="padding-top:10px;font-size:12px;">
                    <a href="{$safeUrl}" style="color:#059669;font-weight:700;text-decoration:underline;word-break:break-all;">{$safeUrl}</a>
                  </td>
                </tr>
              </table>

              <!-- Botão CTA -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center">
                    <a href="{$safeUrl}" style="display:inline-block;padding:14px 32px;background-color:#A3E635;color:#0B1120;font-size:14px;font-weight:900;text-decoration:none;border-radius:14px;box-shadow:0 4px 14px rgba(163,230,53,0.35);">
                      Acessar Sistema LogMovy &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Rodapé -->
          <tr>
            <td style="background-color:#F8FAFC;padding:20px 36px;border-top:1px solid #E2E8F0;text-align:center;">
              <p style="margin:0;font-size:11px;color:#94A3B8;">
                LogMovy &copy; {$year} &bull; Central de Gestão de Escalas e Homologação de Frota
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function sendViaPhpMail(string $to, string $subject, string $htmlBody): bool
    {
        if (!function_exists('mail')) {
            return false;
        }
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $this->fromName, $this->fromEmail),
            sprintf('Reply-To: %s', $this->fromEmail),
            'X-Mailer: LogMovy-Motoboys-API/1.0',
        ];
        try {
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, implode("\r\n", $headers));
        } catch (Throwable) {
            return false;
        }
    }

    private function sendViaSmtp(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        try {
            $prefix = $this->smtpPort === 465 ? 'ssl://' : '';
            $fp = @fsockopen($prefix . $this->smtpHost, $this->smtpPort, $errno, $errstr, 5);
            if (!is_resource($fp)) {
                return false;
            }
            stream_set_timeout($fp, 5);
            $this->readSmtpResponse($fp);

            $this->writeSmtpCommand($fp, 'EHLO logmovy.local');
            if ($this->smtpPort === 587) {
                $this->writeSmtpCommand($fp, 'STARTTLS');
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $cryptoMethod)) {
                    fclose($fp);
                    return false;
                }
                $this->writeSmtpCommand($fp, 'EHLO logmovy.local');
            }

            $cleanPass = str_replace(' ', '', $this->smtpPass);
            $this->writeSmtpCommand($fp, 'AUTH LOGIN');
            $this->writeSmtpCommand($fp, base64_encode($this->smtpUser));
            $authResp = $this->writeSmtpCommand($fp, base64_encode($cleanPass));
            if (!str_starts_with($authResp, '235')) {
                fclose($fp);
                return false;
            }

            $this->writeSmtpCommand($fp, sprintf('MAIL FROM:<%s>', $this->fromEmail));
            $this->writeSmtpCommand($fp, sprintf('RCPT TO:<%s>', $toEmail));
            $this->writeSmtpCommand($fp, 'DATA');

            $headers = [
                sprintf('Date: %s', date(DATE_RFC2822)),
                sprintf('To: %s <%s>', $toName, $toEmail),
                sprintf('From: %s <%s>', $this->fromName, $this->fromEmail),
                sprintf('Subject: =?UTF-8?B?%s?=', base64_encode($subject)),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                chunk_split(base64_encode($htmlBody)),
                '.',
            ];

            $dataResp = $this->writeSmtpCommand($fp, implode("\r\n", $headers));
            $this->writeSmtpCommand($fp, 'QUIT');
            fclose($fp);

            return str_starts_with($dataResp, '250');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param resource $fp
     */
    private function writeSmtpCommand($fp, string $cmd): string
    {
        fwrite($fp, $cmd . "\r\n");
        return $this->readSmtpResponse($fp);
    }

    /**
     * @param resource $fp
     */
    private function readSmtpResponse($fp): string
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }
}

