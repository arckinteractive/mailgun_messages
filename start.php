<?php

// register default Elgg events
elgg_register_event_handler('init', 'system', 'mailgun_messages_init');

/**
 * Gets called during system initialization
 *
 * @return void
 */
function mailgun_messages_init() 
{
	// A sample event handler
	elgg_register_event_handler('receive', 'mg_message', 'mailgun_messages_incoming_handler');

    // Unregister the messages send action
    elgg_unregister_action('messages/send');

    $action_base = elgg_get_plugins_path() . 'mailgun_messages/actions';
    elgg_register_action('messages/send', "$action_base/send.php");
}

/**
 * Handle message replies
 *
 * @param string   $event
 * @param string   $type
 * @param object   $message  \ArckInteractive\Mailgun\Message
 * @return mixed
 */
function mailgun_messages_incoming_handler($event, $type, $message)
{
    // Get the token from the recipient email
    $token = $message->getRecipientToken();

    $ia = elgg_get_ignore_access();
    elgg_set_ignore_access(true);

    // Check if this token is ours 
    $results = elgg_get_entities_from_metadata(array(
        'type'    => 'object',
        'subtype' => 'messages',
        'limit'   => 1,
        'metadata_name_value_pairs' => array(
            'name'       => 'reply_token',
            'value'      => $token,
            'operator'   => '='
        )
    ));

    // Just return if we did not find a message
    if (empty($results)) {
        elgg_set_ignore_access($ia);
        return;
    }

    // Set the topic
    $entity = $results[0];
    $fromId = $entity->fromId;

    elgg_set_ignore_access($ia);

    // Get the Elgg user from the sender
    $user = get_user_by_email($message->getSender());

    if (empty($user)) {
        return;
    }
                                                                                         
    $result = mailgun_messages_send($message->getSubject(), $message->getStrippedText(), $fromId, $user[0]->guid, $entity->guid);

    // Halt event propagation
    return false;
}

/**
 * Send an internal message
 *
 * @param string $subject           The subject line of the message
 * @param string $body              The body of the mesage
 * @param int    $recipient_guid    The GUID of the user to send to
 * @param int    $sender_guid       Optionally, the GUID of the user to send from
 * @param int    $original_guid The GUID of the message to reply from (default: none)
 * @param bool   $notify            Send a notification (default: true)
 * @param bool   $add_to_sent       If true (default), will add a message to the sender's 'sent' tray
 * @return bool
 */
function mailgun_messages_send($subject, $body, $recipient_guid, $sender_guid=0, $original_guid=0, $notify=true, $add_to_sent=true) 
{
    // @todo remove globals
    global $messagesendflag;
    $messagesendflag = 1;

    // @todo remove globals
    global $messages_pm;
    if ($notify) {
        $messages_pm = 1;
    } else {
        $messages_pm = 0;
    }

    // If $sender_guid == 0, set to current user
    if ($sender_guid == 0) {
        $sender_guid = (int) elgg_get_logged_in_user_guid();
    }

    // Initialise 2 new ElggObject
    $message_to = new ElggObject();
    $message_sent = new ElggObject();

    $message_to->subtype = "messages";
    $message_sent->subtype = "messages";

    $message_to->owner_guid = $recipient_guid;
    $message_to->container_guid = $recipient_guid;
    $message_sent->owner_guid = $sender_guid;
    $message_sent->container_guid = $sender_guid;

    $message_to->access_id = ACCESS_PUBLIC;
    $message_sent->access_id = ACCESS_PUBLIC;

    $message_to->title = $subject;
    $message_to->description = $body;

    $message_sent->title = $subject;
    $message_sent->description = $body;

    $message_to->toId = $recipient_guid; // the user receiving the message
    $message_to->fromId = $sender_guid; // the user receiving the message
    $message_to->readYet = 0; // this is a toggle between 0 / 1 (1 = read)
    $message_to->hiddenFrom = 0; // this is used when a user deletes a message in their sentbox, it is a flag
    $message_to->hiddenTo = 0; // this is used when a user deletes a message in their inbox

    $message_sent->toId = $recipient_guid; // the user receiving the message
    $message_sent->fromId = $sender_guid; // the user receiving the message
    $message_sent->readYet = 0; // this is a toggle between 0 / 1 (1 = read)
    $message_sent->hiddenFrom = 0; // this is used when a user deletes a message in their sentbox, it is a flag
    $message_sent->hiddenTo = 0; // this is used when a user deletes a message in their inbox

    // Save the copy of the message that goes to the recipient
    $success = $message_to->save();

    // Save the copy of the message that goes to the sender
    if ($add_to_sent) {
        $message_sent->save();
    }

    $message_to->access_id = ACCESS_PRIVATE;
    $message_to->save();

    if ($add_to_sent) {
        $message_sent->access_id = ACCESS_PRIVATE;
        $message_sent->save();
    }

    // if the new message is a reply then create a relationship link between the new message
    // and the message it is in reply to
    if ($original_guid && $success) {
        add_entity_relationship($message_sent->guid, "reply", $original_guid);
    }

    $message_contents = strip_tags($body);
    
    if (($recipient_guid != elgg_get_logged_in_user_guid()) && $notify) {
    
        $recipient = get_user($recipient_guid);
        $sender    = get_user($sender_guid);

        $subject = elgg_echo('messages:email:subject', array(), $recipient->language);
        $body = elgg_echo('mailgun_messages:email:body', array(
            $sender->name,
            $message_contents
        ), $recipient->language);

        // Get a token to track email replies
        $token = ArckInteractive\Mailgun\Message::addToken(elgg_get_site_entity()->email);

        $message_to->reply_token = $token['token'];

        $params = array(
            'to'       => $recipient->email,
            'from'     => $sender->name . ' <' . $token['email'] . '>',
            'subject'  => $subject,
            'body'     => $body
        );

        mailgun_send_email($params);
    }

    $messagesendflag = 0;
    return $success;
}