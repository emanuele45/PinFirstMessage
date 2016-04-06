<?php

class Pin_First_Message_Integrate
{
	protected static $_topicInfo = null;

	public static function display_topic($topicInfo)
	{
		if (!$topicInfo['is_sticky'])
			return;

		self::$_topicInfo = $topicInfo;
	}

	public static function action_display_after()
	{
		global $context;

		if (empty(self::$_topicInfo['id_first_msg']) || empty($context['current_page']))
			return;

		loadMemberData(array(self::$_topicInfo['id_member_started'], self::$_topicInfo['id_member_updated']));

		Template_Layers::getInstance()->addEnd('pinned_first_msg');

		$msg_parameters = array(
			'message_list' => array(self::$_topicInfo['id_first_msg']),
		);
		$msg_selects = array();
		$msg_tables = array();
		call_integration_hook('integrate_message_query', array(&$msg_selects, &$msg_tables, &$msg_parameters));

		$messages_request = loadMessageRequest($msg_selects, $msg_tables, $msg_parameters);
		$context['pinned_first_msg'] = self::prepare_message($messages_request);
	}

	protected static function prepare_message($messages_request)
	{
		global $settings, $txt, $modSettings, $scripturl, $user_info;
		global $memberContext, $context, $topic;

		// Attempt to get the next message.
		$message = currentContext($messages_request);
		if (!$message)
			return false;

		// $context['icon_sources'] says where each icon should come from - here we set up the ones which will always exist!
		if (empty($context['icon_sources']))
		{
			require_once(SUBSDIR . '/MessageIndex.subs.php');
			$context['icon_sources'] = MessageTopicIcons();
		}

		// Message Icon Management... check the images exist.
		if (empty($modSettings['messageIconChecks_disable']))
		{
			// If the current icon isn't known, then we need to do something...
			if (!isset($context['icon_sources'][$message['icon']]))
				$context['icon_sources'][$message['icon']] = file_exists($settings['theme_dir'] . '/images/post/' . $message['icon'] . '.png') ? 'images_url' : 'default_images_url';
		}
		elseif (!isset($context['icon_sources'][$message['icon']]))
			$context['icon_sources'][$message['icon']] = 'images_url';

		// If you're a lazy bum, you probably didn't give a subject...
		$message['subject'] = $message['subject'] != '' ? $message['subject'] : $txt['no_subject'];

		// Are you allowed to remove at least a single reply?
		$context['can_remove_post'] |= allowedTo('delete_own') && (empty($modSettings['edit_disable_time']) || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 >= time()) && $message['id_member'] == $user_info['id'];

		// Have you liked this post, can you?
		$message['you_liked'] = !empty($context['likes'][$message['id_msg']]['member']) && isset($context['likes'][$message['id_msg']]['member'][$user_info['id']]);
		$message['use_likes'] = allowedTo('like_posts') && ($message['id_member'] != $user_info['id'] || !empty($modSettings['likeAllowSelf'])) && (empty($modSettings['likeMinPosts']) ? true : $modSettings['likeMinPosts'] <= $user_info['posts']);
		$message['like_count'] = !empty($context['likes'][$message['id_msg']]['count']) ? $context['likes'][$message['id_msg']]['count'] : 0;

		// If it couldn't load, or the user was a guest.... someday may be done with a guest table.
		if (!loadMemberContext($message['id_member'], true))
		{
			// Notice this information isn't used anywhere else....
			$memberContext[$message['id_member']]['name'] = $message['poster_name'];
			$memberContext[$message['id_member']]['id'] = 0;
			$memberContext[$message['id_member']]['group'] = $txt['guest_title'];
			$memberContext[$message['id_member']]['link'] = $message['poster_name'];
			$memberContext[$message['id_member']]['email'] = $message['poster_email'];
			$memberContext[$message['id_member']]['show_email'] = showEmailAddress(true, 0);
			$memberContext[$message['id_member']]['is_guest'] = true;
		}
		else
		{
			$memberContext[$message['id_member']]['can_view_profile'] = allowedTo('profile_view_any') || ($message['id_member'] == $user_info['id'] && allowedTo('profile_view_own'));
			$memberContext[$message['id_member']]['is_topic_starter'] = $message['id_member'] == $context['topic_starter_id'];
			$memberContext[$message['id_member']]['can_see_warning'] = !isset($context['disabled_fields']['warning_status']) && $memberContext[$message['id_member']]['warning_status'] && ($context['user']['can_mod'] || (!$user_info['is_guest'] && !empty($modSettings['warning_show']) && ($modSettings['warning_show'] > 1 || $message['id_member'] == $user_info['id'])));
		}

		$memberContext[$message['id_member']]['ip'] = $message['poster_ip'];
		$memberContext[$message['id_member']]['show_profile_buttons'] = $settings['show_profile_buttons'] && (!empty($memberContext[$message['id_member']]['can_view_profile']) || (!empty($memberContext[$message['id_member']]['website']['url']) && !isset($context['disabled_fields']['website'])) || (in_array($memberContext[$message['id_member']]['show_email'], array('yes', 'yes_permission_override', 'no_through_forum'))) || $context['can_send_pm']);

		// Do the censor thang.
		censorText($message['body']);
		censorText($message['subject']);

		// Run BBC interpreter on the message.
		$message['body'] = parse_bbc($message['body'], $message['smileys_enabled'], $message['id_msg']);

		// Compose the memory eat- I mean message array.
		require_once(SUBSDIR . '/Attachments.subs.php');
		$output = array(
			'attachment' => loadAttachmentContext($message['id_msg']),
			'alternate' => 1,
			'id' => $message['id_msg'],
			'href' => $scripturl . '?topic=' . $topic . '.msg' . $message['id_msg'] . '#msg' . $message['id_msg'],
			'link' => '<a href="' . $scripturl . '?topic=' . $topic . '.msg' . $message['id_msg'] . '#msg' . $message['id_msg'] . '" rel="nofollow">' . $message['subject'] . '</a>',
			'member' => &$memberContext[$message['id_member']],
			'icon' => $message['icon'],
			'icon_url' => $settings[$context['icon_sources'][$message['icon']]] . '/post/' . $message['icon'] . '.png',
			'subject' => $message['subject'],
			'time' => standardTime($message['poster_time']),
			'html_time' => htmlTime($message['poster_time']),
			'timestamp' => forum_time(true, $message['poster_time']),
			'counter' => 1,
			'modified' => array(
				'time' => standardTime($message['modified_time']),
				'html_time' => htmlTime($message['modified_time']),
				'timestamp' => forum_time(true, $message['modified_time']),
				'name' => $message['modified_name']
			),
			'body' => $message['body'],
			'new' => empty($message['is_read']),
			'approved' => $message['approved'],
			'first_new' => isset($context['start_from']) && $context['start_from'] == 1,
			'is_ignored' => !empty($modSettings['enable_buddylist']) && in_array($message['id_member'], $context['user']['ignoreusers']),
			'is_message_author' => $message['id_member'] == $user_info['id'],
			'can_approve' => !$message['approved'] && $context['can_approve'],
			'can_unapprove' => !empty($modSettings['postmod_active']) && $context['can_approve'] && $message['approved'],
			'can_modify' => (!$context['is_locked'] || allowedTo('moderate_board')) && (allowedTo('modify_any') || (allowedTo('modify_replies') && $context['user']['started']) || (allowedTo('modify_own') && $message['id_member'] == $user_info['id'] && (empty($modSettings['edit_disable_time']) || !$message['approved'] || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time()))),
			'can_remove' => allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']) || (allowedTo('delete_own') && $message['id_member'] == $user_info['id'] && (empty($modSettings['edit_disable_time']) || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time())),
			'can_see_ip' => allowedTo('moderate_forum') || ($message['id_member'] == $user_info['id'] && !empty($user_info['id'])),
			'can_like' => $message['use_likes'] && !$message['you_liked'],
			'can_unlike' => $message['use_likes'] && $message['you_liked'],
			'like_counter' => $message['like_count'],
			'likes_enabled' => !empty($modSettings['likes_enabled']) && ($message['use_likes'] || ($message['like_count'] != 0)),
		);

		if (!empty($output['modified']['name']))
			$output['modified']['last_edit_text'] = sprintf($txt['last_edit_by'], $output['modified']['time'], $output['modified']['name'], standardTime($output['modified']['timestamp']));

		if (!empty($output['member']['karma']['allow']))
		{
			$output['member']['karma'] += array(
				'applaud_url' => $scripturl . '?action=karma;sa=applaud;uid=' . $output['member']['id'] . ';topic=' . $context['current_topic'] . '.' . $context['start'] . ';m=' . $output['id'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'smite_url' => $scripturl . '?action=karma;sa=smite;uid=' . $output['member']['id'] . ';topic=' . $context['current_topic'] . '.' . $context['start'] . ';m=' . $output['id'] . ';' . $context['session_var'] . '=' . $context['session_id']
			);
		}

		call_integration_hook('integrate_prepare_display_context', array(&$output, &$message));

		return $output;
	}
}

function template_pinned_first_msg_above()
{
	global $context, $ignoredMsgs, $options, $txt, $scripturl, $settings;

	$message = $context['pinned_first_msg'];

	// Are we ignoring this message?
	if (!empty($message['is_ignored']))
	{
		$ignoring = true;
		$ignoredMsgs[] = $message['id'];
	}
	else
		$ignoring = false;

	// Show the message anchor and a "new" anchor if this message is new.
	echo '
				<div id="pinned_first_msg" class="post_wrapper ', $message['approved'] ? ($message['alternate'] == 0 ? 'windowbg' : 'windowbg2') : 'approvebg', '">', $message['id'] != $context['first_message'] ? '
					<a class="post_anchor" id="msg' . $message['id'] . '"></a>' . ($message['first_new'] ? '<a id="new"></a>' : '') : '';

	// Showing the sidebar posting area?
	if (empty($options['hide_poster_area']))
		echo '
					<ul class="poster">', template_build_poster_div($message, $ignoring), '</ul>';

	echo '
					<div class="postarea', empty($options['hide_poster_area']) ? '' : '2', '">
						<div class="keyinfo">
						', (!empty($options['hide_poster_area']) ? '<ul class="poster poster2">' . template_build_poster_div($message, $ignoring) . '</ul>' : '');

	if (!empty($context['follow_ups'][$message['id']]))
	{
		echo '
							<ul class="quickbuttons follow_ups">
								<li class="listlevel1 subsections" aria-haspopup="true"><a class="linklevel1">', $txt['follow_ups'], '</a>
									<ul class="menulevel2">';

		foreach ($context['follow_ups'][$message['id']] as $follow_up)
			echo '
										<li class="listlevel2"><a class="linklevel2" href="', $scripturl, '?topic=', $follow_up['follow_up'], '.0">', $follow_up['subject'], '</a></li>';

		echo '
									</ul>
								</li>
							</ul>';
	}

	echo '
							<span id="post_subject_', $message['id'], '" class="post_subject">', $message['subject'], '</span>
							<span id="messageicon_', $message['id'], '" class="messageicon"  ', ($message['icon_url'] !== $settings['images_url'] . '/post/xx.png') ? '' : 'style="display:none;"', '>
								<img src="', $message['icon_url'] . '" alt=""', $message['can_modify'] ? ' id="msg_icon_' . $message['id'] . '"' : '', ' />
							</span>
							<h5 id="info_', $message['id'], '">', $message['html_time'], '
							</h5>
							<div id="msg_', $message['id'], '_quick_mod"', $ignoring ? ' style="display:none;"' : '', '></div>
						</div>';

	// Ignoring this user? Hide the post.
	if ($ignoring)
		echo '
						<div id="msg_', $message['id'], '_ignored_prompt">
							', $txt['ignoring_user'], '
							<a href="#" id="msg_', $message['id'], '_ignored_link" style="display: none;">', $txt['show_ignore_user_post'], '</a>
						</div>';

	// Awaiting moderation?
	if (!$message['approved'] && $message['member']['id'] != 0 && $message['member']['id'] == $context['user']['id'])
		echo '
						<div class="approve_post">
							', $txt['post_awaiting_approval'], '
						</div>';

	// Show the post itself, finally!
	echo '
						<div class="inner" id="msg_', $message['id'], '"', $ignoring ? ' style="display:none;"' : '', '>', $message['body'], '</div>';

	// Assuming there are attachments...
	if (!empty($message['attachment']))
		template_display_attachments($message, $ignoring);

	// Show the quickbuttons, for various operations on posts.
	echo '
						<ul id="buttons_', $message['id'], '" class="quickbuttons">';

	// Show a checkbox for quick moderation?
	if (!empty($options['display_quick_mod']) && $options['display_quick_mod'] == 1 && $message['can_remove'])
		echo '
							<li class="listlevel1 inline_mod_check" style="display: none;" id="in_topic_mod_check_', $message['id'], '"></li>';

	// Show "Last Edit: Time by Person" if this post was edited.
	if ($settings['show_modify'])
		echo '
							<li class="listlevel1 modified" id="modified_', $message['id'], '"',  !empty($message['modified']['name']) ? '' : ' style="display:none"', '>
								',  !empty($message['modified']['name']) ? $message['modified']['last_edit_text'] : '', '
							</li>';

	// Maybe they can modify the post (this is the more button)
	if ($message['can_modify'] || ($context['can_report_moderator']))
		echo '
							<li class="listlevel1 subsections" aria-haspopup="true"><a href="#" ', !empty($options['use_click_menu']) ? '' : 'onclick="event.stopPropagation();return false;" ', 'class="linklevel1 post_options">', $txt['post_options'], '</a>';

	if ($message['can_modify'] || $message['can_remove'] || $context['can_follow_up'] || ($context['can_split'] && !empty($context['real_num_replies'])) || $context['can_restore_msg'] || $message['can_approve'] || $message['can_unapprove'] || $context['can_report_moderator'])
	{
		// Show them the other options they may have in a nice pulldown
		echo '
								<ul class="menulevel2">';

		// Can the user modify the contents of this post?
		if ($message['can_modify'])
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=post;msg=', $message['id'], ';topic=', $context['current_topic'], '.', $context['start'], '" class="linklevel2 modify_button">', $txt['modify'], '</a></li>';

		// How about... even... remove it entirely?!
		if ($message['can_remove'])
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=deletemsg;topic=', $context['current_topic'], '.', $context['start'], ';msg=', $message['id'], ';', $context['session_var'], '=', $context['session_id'], '" onclick="return confirm(\'', $txt['remove_message'], '?\');" class="linklevel2 remove_button">', $txt['remove'], '</a></li>';

		// Can they quote to a new topic? @todo - This needs rethinking for GUI layout.
		if ($context['can_follow_up'])
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=post;board=', $context['current_board'], ';quote=', $message['id'], ';followup=', $message['id'], '" class="linklevel2 quotetonew_button">', $txt['quote_new'], '</a></li>';

		// What about splitting it off the rest of the topic?
		if ($context['can_split'] && !empty($context['real_num_replies']))
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=splittopics;topic=', $context['current_topic'], '.0;at=', $message['id'], '" class="linklevel2 split_button">', $txt['split_topic'], '</a></li>';

		// Can we restore topics?
		if ($context['can_restore_msg'])
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=restoretopic;msgs=', $message['id'], ';', $context['session_var'], '=', $context['session_id'], '" class="linklevel2 restore_button">', $txt['restore_message'], '</a></li>';

		// Maybe we can approve it, maybe we should?
		if ($message['can_approve'])
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=moderate;area=postmod;sa=approve;topic=', $context['current_topic'], '.', $context['start'], ';msg=', $message['id'], ';', $context['session_var'], '=', $context['session_id'], '"  class="linklevel2 approve_button">', $txt['approve'], '</a></li>';

		// Maybe we can unapprove it?
		if ($message['can_unapprove'])
			echo '
									<li class="listlevel2"><a href="', $scripturl, '?action=moderate;area=postmod;sa=approve;topic=', $context['current_topic'], '.', $context['start'], ';msg=', $message['id'], ';', $context['session_var'], '=', $context['session_id'], '"  class="linklevel2 unapprove_button">', $txt['unapprove'], '</a></li>';

		// Maybe they want to report this post to the moderator(s)?
		if ($context['can_report_moderator'])
			echo '
									<li class="listlevel2"><a href="' . $scripturl . '?action=reporttm;topic=' . $context['current_topic'] . '.' . $message['counter'] . ';msg=' . $message['id'] . '" class="linklevel2 warn_button">' . $txt['report_to_mod'] . '</a></li>';

		// Anything else added by mods for example?
		if (!empty($context['additional_drop_buttons']))
			foreach ($context['additional_drop_buttons'] as $key => $button)
				echo '
									<li class="listlevel2"><a href="' . $button['href'] . '" class="linklevel2 ', $key, '">' . $button['text'] . '</a></li>';

		echo '
								</ul>';
	}

	// Hide likes if its off
	if ($message['likes_enabled'])
	{
		// Can they like/unlike this post?
		if ($message['can_like'] || $message['can_unlike'])
			echo '
							<li class="listlevel1', !empty($message['like_counter']) ? ' liked"' : '"' ,'>
								<a class="linklevel1 ', $message['can_unlike'] ? 'unlike_button' : 'like_button', '" href="javascript:void(0)" title="', !empty($message['like_counter']) ? $txt['liked_by'] . ' ' . implode(', ', $context['likes'][$message['id']]['member']) : '', '" onclick="likePosts.prototype.likeUnlikePosts(event,', $message['id'],', ',$context['current_topic'],'); return false;">',
									!empty($message['like_counter']) ? '<span class="likes_indicator">' . $message['like_counter'] . '</span>&nbsp;' . $txt['likes'] : $txt['like_post'], '
								</a>
							</li>';

		// Or just view the count
		else
			echo '
							<li class="listlevel1', !empty($message['like_counter']) ? ' liked"' : '"', '>
								<a href="javascript:void(0)" title="', !empty($message['like_counter']) ? $txt['liked_by'] . ' ' . implode(', ', $context['likes'][$message['id']]['member']) : '', '" class="linklevel1 likes_button">',
									!empty($message['like_counter']) ? '<span class="likes_indicator">' . $message['like_counter'] . '</span>&nbsp;' . $txt['likes'] : '&nbsp;', '
								</a>
							</li>';
	}

	// Can the user quick modify the contents of this post?  Show the quick (inline) modify button.
	if ($message['can_modify'])
		echo '
							<li class="listlevel1 quick_edit" id="modify_button_', $message['id'], '" style="display: none"><a class="linklevel1 quick_edit" onclick="oQuickModify.modifyMsg(\'', $message['id'], '\')">', $txt['quick_edit'], '</a></li>';

	// Can they reply? Have they turned on quick reply?
	if ($context['can_quote'] && !empty($options['display_quick_reply']))
		echo '
							<li class="listlevel1">
								<a href="', $scripturl, '?action=post;quote=', $message['id'], ';topic=', $context['current_topic'], '.', $context['start'], ';last_msg=', $context['topic_last_message'], '" onclick="return oQuickReply.quote(', $message['id'], ');" class="linklevel1 quote_button">', $txt['quote'], '</a>
							</li>';
	// So... quick reply is off, but they *can* reply?
	elseif ($context['can_quote'])
		echo '
							<li class="listlevel1">
								<a href="', $scripturl, '?action=post;quote=', $message['id'], ';topic=', $context['current_topic'], '.', $context['start'], ';last_msg=', $context['topic_last_message'], '" class="linklevel1 quote_button">', $txt['quote'], '</a>
							</li>';

	// Anything else added by mods for example?
	if (!empty($context['additional_quick_buttons']))
		foreach ($context['additional_quick_buttons'] as $key => $button)
			echo '
								<li class="listlevel1"><a href="' . $button['href'] . '" class="linklevel1 ', $key, '">' . $button['text'] . '</a></li>';

	echo '
						</ul>';

	// Are there any custom profile fields for above the signature?
	// Show them if signatures are enabled and you want to see them.
	if (!empty($message['member']['custom_fields']) && empty($options['show_no_signatures']) && $context['signature_enabled'])
	{
		$shown = false;
		foreach ($message['member']['custom_fields'] as $custom)
		{
			if ($custom['placement'] != 2 || empty($custom['value']))
				continue;

			if (empty($shown))
			{
				$shown = true;
				echo '
						<div class="custom_fields_above_signature">
							<ul>';
			}

			echo '
								<li>', $custom['value'], '</li>';
		}

		if ($shown)
			echo '
							</ul>
						</div>';
	}

	// Show the member's signature?
	if (!empty($message['member']['signature']) && empty($options['show_no_signatures']) && $context['signature_enabled'])
		echo '
						<div class="signature" id="msg_', $message['id'], '_signature"', $ignoring ? ' style="display:none;"' : '', '>', $message['member']['signature'], '</div>';

	echo '
					</div>
				</div>
				<hr class="post_separator" />';
}