<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Template\Template;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Runtime;
use Utopia\App;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;

class Mails extends Action
{
    public static function getName(): string
    {
        return 'mails';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Mails worker')
            ->inject('message')
            ->inject('register')
            ->inject('log')
            ->callback(fn(Message $message, Registry $register, Log $log) => $this->action($message, $register, $log));
    }

    /**
     * @param Message $message
     * @param Registry $register
     * @param Log $log
     * @throws \PHPMailer\PHPMailer\Exception
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Registry $register, Log $log): void
    {
        Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $smtp = $payload['smtp'];

        if (empty($smtp) && empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('Skipped mail processing. No SMTP configuration has been set.');
        }

        $log->addTag('type', empty($smtp) ? 'cloud' : 'smtp');

        $recipient = $payload['recipient'];
        $subject = $payload['subject'];
        $variables = $payload['variables'];
        $name = $payload['name'];
        $body = $payload['body'];

        $variables['subject'] = $subject;
        $variables['year'] = date("Y");

        $bodyTemplate = $payload['bodyTemplate'];
        if (empty($bodyTemplate)) {
            $bodyTemplate = __DIR__ . '/../../../../app/config/locale/templates/email-base.tpl';
        }

        $bodyTemplate = Template::fromFile($bodyTemplate);
        $bodyTemplate->setParam('{{body}}', $body);
        foreach ($variables as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value);
        }
        $body = $bodyTemplate->render();

        $subjectTemplate = Template::fromString($subject);
        foreach ($variables as $key => $value) {
            $subjectTemplate->setParam('{{' . $key . '}}', $value);
        }
        // render() will return the subject in <p> tags, so use strip_tags() to remove them
        $subject = \strip_tags($subjectTemplate->render());

        /** @var PHPMailer $mail */
        $mail = empty($smtp)
            ? $register->get('smtp')
            : $this->getMailer($smtp);

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();
        $mail->addAddress($recipient, $name);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->AltBody = $body;
        $mail->AltBody = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $mail->AltBody);
        $mail->AltBody = \strip_tags($mail->AltBody);
        $mail->AltBody = \trim($mail->AltBody);

        $replyTo = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $replyToName = \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));

        if (!empty($smtp)) {
            $replyTo = !empty($smtp['replyTo']) ? $smtp['replyTo'] : $smtp['senderEmail'];
            $replyToName = $smtp['senderName'];
        }

        $mail->addReplyTo($replyTo, $replyToName);

        try {
            $mail->send();
        } catch (\Exception $error) {
            throw new Exception('Error sending mail: ' . $error->getMessage(), 500);
        }
    }

    /**
     * @param array $smtp
     * @return PHPMailer
     * @throws \PHPMailer\PHPMailer\Exception
     */
    protected function getMailer(array $smtp): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();

        $username = $smtp['username'];
        $password = $smtp['password'];

        $mail->XMailer = 'Appwrite Mailer';
        $mail->Host = $smtp['host'];
        $mail->Port = $smtp['port'];
        $mail->SMTPAuth = (!empty($username) && !empty($password));
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = $smtp['secure'];
        $mail->SMTPAutoTLS = false;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtp['senderEmail'], $smtp['senderName']);

        $mail->isHTML();

        return $mail;
    }
}
