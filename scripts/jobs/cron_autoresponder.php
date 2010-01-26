<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Florian Lippert <flo@syscp.org> (2003-2009)
 * @author	   Remo Fritzsche
 * @author	   Manuel Aller
 * @author	   Michael Schlechtinger
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Cron
 * @version    $Id$
 * @todo	   skip mail parsing after x bytes for large mails
 */

$mail = new PHPMailer();

//dont do anything when module is disabled

if((int)$settings['autoresponder']['autoresponder_active'] == 0)
{
	return;
}

//only send autoresponder to mails which were delivered since last run

if((int)$settings['autoresponder']['last_autoresponder_run'] == 0)
{
	//mails from last 5 minutes, otherwise all mails will be parsed -> mailbomb prevention

	$cycle = 300;
}
else
{
	$cycle = time() - (int)$settings['autoresponder']['last_autoresponder_run'];

	//prevent mailbombs when cycle is bigger than two days

	if($cycle > (2 * 60 * 60 * 24))$cycle = (60 * 60 * 24);
}

$db->query("UPDATE `" . TABLE_PANEL_SETTINGS . "` SET `value` = '" . (int)time() . "' WHERE `settinggroup` = 'autoresponder' AND `varname` = 'last_autoresponder_run'");

/*
//can be used for later usage if autoresponders should be only active in a defined period

//This query has to disable every autoresponder entry which ended in the past
$db->query("UPDATE `autoresponder` SET `enabled` = 0 WHERE `to` < CURDATE()");

//This query has to activate every autoresponder entry which starts today
$db->query("UPDATE `autoresponder` SET `enabled` = 1 WHERE `from` = CURDATE()");
*/
//getting all mailboxes where autoresponders are active and configured

$result = $db->query("SELECT * FROM `" . TABLE_MAIL_AUTORESPONDER . "` INNER JOIN `" . TABLE_MAIL_USERS . "` ON `" . TABLE_MAIL_AUTORESPONDER . "`.`email` = `" . TABLE_MAIL_USERS . "`.`email` WHERE `enabled` = 1");

if($db->num_rows($result) > 0)
{
	while($row = $db->fetch_array($result))
	{
		/*
		 * check if specific autoresponder should be used
		 */
		$ts_now = time();
		$ts_start = (int)$row['date_from'];
		$ts_end = (int)$row['date_until'];
		
		// not yet
		if($ts_start != -1 && $ts_start > $ts_now) continue;
		// already ended
		if($ts_end != -1 && $ts_end < $ts_now) continue;
		
		$path = $row['homedir'] . $row['maildir'] . "new/";
		$files = scandir($path);
		foreach($files as $entry)
		{
			if($entry == '.'
			   || $entry == '..')continue;

			if(time() - filemtime($path . $entry) - $cycle <= 0)
			{
				$content = file($path . $entry);

				//error reading mail contents

				if(count($content) == 0)
				{
					$cronlog->logAction(LOG_ERROR, LOG_WARNING, "Unable to read mail from maildir: " . $entry);
					continue;
				}

				$match = array();
				$from = '';
				$to = '';
				$sender = '';
				$spam = false;
				foreach($content as $line)
				{
					// header ends on first empty line, skip rest of mail

					if(strlen(rtrim($line)) == 0)
					{
						break;
					}

					//fetching from field

					if(!strlen($from)
					   && preg_match("/^From:(.+)<(.*)>$/", $line, $match))
					{
						$from = $match[2];
					}
					elseif(!strlen($from)
					       && preg_match("/^From:\s+(.*@.*)$/", $line, $match))
					{
						$from = $match[1];
					}

					//fetching to field

					if(!strlen($to)
					   && preg_match("/^To:(.+)<(.*)>$/", $line, $match))
					{
						$to = $match[2];
					}
					elseif(!strlen($to)
					       && preg_match("/To:\s+(.*@.*)$/", $line, $match))
					{
						$to = $match[1];
					}

					//fetching sender field

					if(!strlen($to)
					   && preg_match("/^Sender:(.+)<(.*)>$/", $line, $match))
					{
						$sender = $match[2];
					}
					elseif(!strlen($to)
					       && preg_match("/Sender:\s+(.*@.*)$/", $line, $match))
					{
						$sender = $match[1];
					}

					//check for amavis/spamassassin spam headers

					if(preg_match("/^X-Spam-Status: (Yes|No)(.*)$/", $line, $match))
					{
						if($match[1] == 'Yes')$spam = true;
					}
					
					//check for precedence header
					if(preg_match("/^Precedence: (bulk|list|junk)(.*)$/", $line, $match))
					{
						// use the spam flag to skip reply
						$spam = true;
					}
				}

				//skip mail when marked as spam

				if($spam == true)continue;

				//error while parsing mail

				if($to == ''
				   || $from == '')
				{
					$cronlog->logAction(LOG_ERROR, LOG_WARNING, "No valid headers found in mail to parse: " . $entry);
					continue;
				}

				//important! prevent mailbombs when mail comes from a maildaemon/mailrobot
				//robot/daemon mails must go to Sender: field in envelope header
				//refers to "Das Postfix-Buch" / RFC 2822

				if($sender != '')$from = $sender;

				//make message valid to email format

				$message = str_replace("\r\n", "\n", $row['message']);

				//check if mail is already an answer

				$fullcontent = implode("", $content);

				if(strstr($fullcontent, $message))
				{
					continue;
				}

				//send mail with mailer class
				$mail->From = $to;
				$mail->FromName = $to;
				$mail->Subject = $row['subject'];
				$mail->Body = html_entity_decode($message);
				$mail->AddAddress($from, $from);
				$mail->AddCustomHeader('Precedence: bulk');

				// set correct return path
				$mail->Sender = $to;

				if(!$mail->Send())
				{
					if($mail->ErrorInfo != '')
					{
						$mailerr_msg = $mail->ErrorInfo;
					}
					else
					{
						$mailerr_msg = $from;
					}

					$cronlog->logAction(LOG_ERROR, LOG_WARNING, "Error sending autoresponder mail: " . $mailerr_msg);
				}

				$mail->ClearAddresses();
			}
		}
	}
}

?>
